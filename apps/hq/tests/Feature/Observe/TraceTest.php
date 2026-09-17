<?php

use App\Agents\AgentResponseSchema;
use App\Ai\ModelTier;
use App\Ask\AskHq;
use App\Commerce\Support\ConnectorCallLogger;
use App\Erp\ErpRegistry;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AiRun;
use App\Models\AskConversation;
use App\Models\AskMessage;
use App\Models\ConnectorCall;
use App\Models\FeatureFlag;
use App\Models\IntegrationCredential;
use App\Models\ToolCall;
use App\Support\Features\Features;
use Database\Seeders\AgentSeeder;
use Database\Seeders\DomainSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Tests\Support\Ai\FixtureProvider;

/*
 | docs/48 M7's acceptance criterion (docs/78 E): from an Ask HQ question to the external system's HTTP
 | status, with no broken link. The model is the fixture provider; everything after it is the real
 | runner, gateway, connector and logger, with the ERP's HTTP faked at the edge.
 */

const TRACE_ERP = 'https://erp.trace.test';

beforeEach(function () {
    Cache::flush();
    FixtureProvider::reset();
    $this->seed(DomainSeeder::class);
    aiProviders();
    enableAiRuntime();
    aiModel('fixture', 'fixture-model', ModelTier::Medium);

    $this->owner = $this->hqUser('owner');
    $this->seed(AgentSeeder::class);
    Agent::query()->update(['status' => 'active', 'enabling_user_id' => $this->owner->id]);

    config(['hq.erp.base_url' => TRACE_ERP]);
    IntegrationCredential::create(['provider' => ErpRegistry::PROVIDER, 'account_ref' => 'bor-de-guna', 'credential_type' => 'token',
        'payload' => ['value' => 'trace-token'], 'status' => 'active']);

    FeatureFlag::updateOrCreate(['key' => AskHq::FLAG, 'environment' => '*'], ['enabled' => true, 'rollout' => null]);
    app(Features::class)->forget();
});

function traceTurn(array $overrides, string $question): mixed
{
    return FixtureProvider::reply((string) json_encode($overrides + [
        'status' => AgentResponseSchema::STATUS_ANSWER,
        'answer' => '', 'refusal_reason' => '', 'facts' => [], 'inferences' => [], 'tool_calls' => [],
        'task' => $question, 'authority_request' => AgentResponseSchema::AUTHORITY_NONE,
    ]));
}

/** The model's two turns: ask for the report, then answer from it. */
function traceScript(string $question): void
{
    FixtureProvider::script('fixture', [
        traceTurn(['status' => AgentResponseSchema::STATUS_NEED_EVIDENCE, 'tool_calls' => [[
            'tool' => 'erp.run_report', 'args' => ['business_slug' => 'bor-de-guna', 'report_key' => 'sales_by_brand'], 'why' => 'the figures',
        ]]], $question),
        traceTurn(['answer' => 'Net sales by brand are in the report.'], $question),
    ]);
}

function traceAnswer(): AskMessage
{
    return AskMessage::where('role', AskMessage::ROLE_ASSISTANT)->latest('id')->firstOrFail();
}

it('follows one question from the Ask HQ row to the ERP\'s HTTP status without a broken link', function () {
    Http::fake([TRACE_ERP.'/api/hq/v1/4/reports/sales_by_brand/run' => Http::response(['data' => [['brand' => 'LaLaDia', 'net_sales' => 1200]]], 200)]);
    $question = 'Run the sales_by_brand report for bor-de-guna and tell me the net sales.';

    traceScript($question);
    $this->actingAsHq($this->owner)->post('/ask', ['question' => $question, 'agent_key' => 'executive'])->assertRedirect();
    $answer = traceAnswer();
    $asked = AskMessage::where('role', AskMessage::ROLE_USER)->sole();

    // 1. The answer row names its run, and the run names the question and the person.
    expect($answer->status)->toBe(AskMessage::DONE)
        ->and($answer->ask_conversation_id)->toBe($asked->ask_conversation_id);
    $run = AgentRun::findOrFail($answer->agent_run_id);
    expect($run->correlation_id)->toBe($answer->correlation_id)
        ->and($run->question)->toBe($asked->content)
        ->and($run->triggered_by_user_id)->toBe($this->owner->id);

    // 2. The run's model calls.
    expect(AiRun::where('correlation_id', $run->correlation_id)->count())->toBe(2);

    // 3. The tool call the gateway recorded for it.
    $toolCall = ToolCall::where('correlation_id', $run->correlation_id)->sole();
    expect($toolCall->tool_key)->toBe('erp.run_report')
        ->and($toolCall->status)->toBe('succeeded')
        ->and($toolCall->agent_key)->toBe('executive')
        ->and($toolCall->enabling_user_id)->toBe($this->owner->id);

    // 4. What left the building, and 5. what the ERP answered.
    $connectorCall = ConnectorCall::where('correlation_id', $run->correlation_id)->sole();
    expect($connectorCall->connector)->toBe('erp:bor-de-guna')
        ->and($connectorCall->operation)->toBe('runReport')
        ->and($connectorCall->request_ref)->toBe('sales_by_brand')
        ->and($connectorCall->status)->toBe('succeeded')
        ->and($connectorCall->response_status)->toBe(200);
    Http::assertSent(fn (Request $r) => $r->url() === TRACE_ERP.'/api/hq/v1/4/reports/sales_by_brand/run' && $r->hasHeader('Authorization', 'Bearer trace-token'));

    // The same chain is what the page shows the person who asked.
    $this->actingAsHq($this->owner)->get('/ask/'.AskConversation::sole()->uuid)
        ->assertInertia(fn ($page) => $page->where('messages.1.run.id', $run->id)
            ->where('messages.1.run.tool_calls.0.tool_key', 'erp.run_report')
            ->where('messages.1.run.tool_calls.0.status', 'succeeded'));
});

it('keeps the link when the external system fails, with the status it failed with', function () {
    Http::fake([TRACE_ERP.'/api/hq/v1/4/reports/sales_by_brand/run' => Http::response(['message' => 'down for maintenance'], 503)]);

    $question = 'Run the sales_by_brand report for bor-de-guna and tell me the net sales.';
    traceScript($question);
    $this->actingAsHq($this->owner)->post('/ask', ['question' => $question, 'agent_key' => 'executive'])->assertRedirect();
    $run = AgentRun::findOrFail(traceAnswer()->agent_run_id);

    $connectorCall = ConnectorCall::where('correlation_id', $run->correlation_id)->sole();
    expect($connectorCall->status)->toBe('failed')
        ->and($connectorCall->response_status)->toBe(503)
        ->and(ToolCall::where('correlation_id', $run->correlation_id)->sole()->status)->not->toBe('succeeded');
});

it('records a status only for a response inside the call it belongs to, and no correlation outside a gateway call', function () {
    Http::fake(['https://elsewhere.test/*' => Http::response('ok', 204), TRACE_ERP.'/*' => Http::response(['data' => []], 200)]);
    $logger = app(ConnectorCallLogger::class);

    // A response received outside any recorded call is not remembered for the next one.
    Http::get('https://elsewhere.test/ping');
    $logger->call('probe', 'noHttp', fn () => true);

    // A call made outside the gateway carries whatever correlation the context holds, here none.
    Context::forget('correlation_id');
    $logger->call('probe', 'withHttp', fn () => Http::get(TRACE_ERP.'/api/hq/v1/4/overview')->status());

    $calls = ConnectorCall::where('connector', 'probe')->orderBy('id')->get();
    expect($calls->pluck('response_status')->all())->toBe([null, 200])
        ->and($calls->pluck('correlation_id')->all())->toBe([null, null]);

    // Inside a scoped context the id is written down.
    Context::scope(fn () => $logger->call('probe', 'scoped', fn () => true), ['correlation_id' => 'corr-123']);
    expect(ConnectorCall::where('operation', 'scoped')->sole()->correlation_id)->toBe('corr-123');
});
