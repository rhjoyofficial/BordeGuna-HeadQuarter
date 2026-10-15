<?php

use App\Context\Provenance;
use App\Decisions\DecisionBusinessBackfill;
use App\Decisions\DecisionCorpusReader;
use App\Decisions\DecisionCorpusWindow;
use App\Decisions\DecisionVocabulary;
use App\Decisions\EditClassifier;
use App\Decisions\UnassignedDecisions;
use App\Gateway\Descriptor\AutonomyLevel;
use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Conversation;
use App\Models\DecisionRecord;
use App\Models\FactsOrder;
use App\Models\Store;
use App\Models\UserBusinessScope;
use App\Outcomes\ActionType;
use App\Outcomes\AttributionMethod;
use App\Outcomes\AttributionRole;
use App\Outcomes\AttributionSpec;
use App\Outcomes\ImplicitHqScopeRefused;
use App\Outcomes\OutcomeRecorder;
use App\Outcomes\OutcomeSource;
use App\Outcomes\OutcomeSpec;
use App\Outcomes\ResultRefSpec;
use App\Outcomes\ResultType;
use Database\Seeders\AgentSeeder;
use Database\Seeders\DomainSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
 | docs/120 section B, S5 items 18-21 and 34 (docs/125): the "what I would have sent" box, reasons off the page,
 | one decision vocabulary with a compatibility map, and an explicit business on every decision record.
 */

beforeEach(function () {
    Cache::flush();
    Http::fake();
    config(['hq.alerts.telegram_bot_token' => '', 'hq.alerts.telegram_chat_id' => '']);
    $this->seed(DomainSeeder::class);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(AgentSeeder::class);
    $this->owner = $this->hqUser('owner');
    $this->laladia = Business::where('slug', 'laladia')->sole();
    $this->bionic = Business::where('slug', 'bionic')->sole();
});

function w4ShadowRun(?int $businessId, array $overrides = []): AgentRun
{
    return AgentRun::create(array_merge([
        'uuid' => (string) Str::uuid(), 'agent_key' => 'executive', 'correlation_id' => (string) Str::uuid(), 'trigger' => 'console',
        'question' => 'How did yesterday go?', 'autonomy_level' => AutonomyLevel::A0->value, 'autonomy_reason' => 'no grant', 'shadow' => true,
        'status' => 'answered', 'answer' => 'Two orders, BDT 2,120. Sorry for the delay.', 'evidence' => [], 'proposals' => [], 'context_provenance' => [],
        'turns' => 1, 'tool_calls_count' => 0, 'started_at' => now(), 'finished_at' => now(), 'business_id' => $businessId,
    ], $overrides));
}

/** @return array<string, int> */
function w4EffectCounts(): array
{
    return collect(['tool_calls', 'approvals', 'conversation_messages', 'message_deliveries', 'conversations', 'workflow_instances'])
        ->mapWithKeys(fn (string $t) => [$t => DB::table($t)->count()])->all();
}

it('item 18: keeps the reviewer\'s "what I would have sent" as a labelled correction in the record, and sends nothing', function () {
    $run = w4ShadowRun($this->laladia->id);
    $before = w4EffectCounts();

    $this->actingAsHq($this->owner)->post("/agents/runs/{$run->id}/review", [
        'verdict' => 'edited', 'note' => 'too apologetic', 'would_have_sent' => 'Two orders, BDT 2,120.',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $record = DecisionRecord::sole();
    expect($record->decision)->toBe('edited')
        ->and($record->business_id)->toBe($this->laladia->id)
        ->and($record->final)->toMatchArray(['verdict' => 'edited', 'would_have_sent' => 'Two orders, BDT 2,120.', 'would_have_sent_executed' => false])
        ->and($record->delta)->toEqual(['from' => 'Two orders, BDT 2,120. Sorry for the delay.', 'to' => 'Two orders, BDT 2,120.']);

    // Nothing left HQ and nothing was queued: no tool call, approval, message, delivery, thread or workflow.
    expect(w4EffectCounts())->toBe($before);
    Http::assertNothingSent();

    // The audit says a suggestion exists, never its text.
    $audit = AuditLog::where('action', 'agent.run_reviewed')->sole();
    expect($audit->after['would_have_sent'])->toBeTrue()
        ->and(json_encode($audit->after))->not->toContain('BDT 2,120.');

    // The run page shows it back in its own box, apart from the run's answer.
    $this->actingAsHq($this->owner)->get("/agents/runs/{$run->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('run.review_suggestion', 'Two orders, BDT 2,120.')
            ->where('run.answer', 'Two orders, BDT 2,120. Sorry for the delay.'));

    // The corpus now classifies the shadow correction instead of counting a verdict without text.
    $report = app(DecisionCorpusReader::class)->read(DecisionCorpusWindow::from('all'));
    expect($report['gaps']['shadow_reviews_edited_without_text'])->toBe(0)
        ->and(collect($report['categories'])->pluck('category')->all())->not->toContain(EditClassifier::VERDICT_ONLY);
});

it('item 18: refuses a suggestion on an approval, and the box is optional otherwise', function () {
    $run = w4ShadowRun($this->laladia->id);
    $this->actingAsHq($this->owner)->post("/agents/runs/{$run->id}/review", ['verdict' => 'approved', 'would_have_sent' => 'x'])
        ->assertSessionHasErrors('would_have_sent');
    expect(DecisionRecord::count())->toBe(0);

    $this->actingAsHq($this->owner)->post("/agents/runs/{$run->id}/review", ['verdict' => 'rejected', 'note' => 'wrong store'])->assertSessionHasNoErrors();
    expect(DecisionRecord::sole()->delta)->toBeNull()->and(DecisionRecord::sole()->final)->not->toHaveKey('would_have_sent');
});

it('item 19: keeps the reason off the corpus page while the record and the audit keep it', function () {
    $run = w4ShadowRun($this->laladia->id);
    $this->actingAsHq($this->owner)->post("/agents/runs/{$run->id}/review", ['verdict' => 'rejected', 'note' => 'internal-reason-zebra']);

    expect(DecisionRecord::sole()->reason)->toBe('internal-reason-zebra')
        ->and(AuditLog::where('action', 'agent.run_reviewed')->sole()->reason)->toBe('internal-reason-zebra');

    $response = $this->actingAsHq($this->owner)->get('/observe/decision-corpus?window=all')->assertOk();
    expect($response->getContent())->not->toContain('internal-reason-zebra');
});

it('item 20: stores only approved, edited or rejected from now on, and reads legacy words through the documented map without rewriting them', function () {
    expect(fn () => DecisionRecord::create(['context_type' => 'conversation', 'context_id' => 'x', 'decision' => 'send']))
        ->toThrow(InvalidArgumentException::class, 'approved, edited or rejected');

    expect(DecisionVocabulary::normalize('send', ['edited' => true]))->toBe('edited')
        ->and(DecisionVocabulary::normalize('send', ['edited' => false]))->toBe('approved')
        ->and(DecisionVocabulary::normalize('send', null, ['from' => 'a', 'to' => 'b']))->toBe('edited')
        ->and(DecisionVocabulary::normalize('discard'))->toBe('rejected')
        ->and(DecisionVocabulary::normalize('rejected'))->toBe('rejected')
        ->and(DecisionVocabulary::fromInboxAction('discard', false))->toBe('rejected')
        ->and(DecisionVocabulary::fromInboxAction('send', true))->toBe('edited')
        ->and(DecisionVocabulary::fromInboxAction('send', false))->toBe('approved');

    // A legacy row stays exactly as written; the reader maps it.
    $thread = Conversation::create(['uuid' => (string) Str::uuid(), 'business_id' => $this->laladia->id, 'channel' => 'messenger', 'status' => 'open', 'opened_at' => now()]);
    $legacy = DB::table('decision_records')->insertGetId(['context_type' => 'conversation', 'context_id' => $thread->uuid, 'decision' => 'discard',
        'business_id' => $this->laladia->id, 'created_at' => now()]);
    $report = app(DecisionCorpusReader::class)->read(DecisionCorpusWindow::from('all'));
    expect($report['totals']['rejected'])->toBe(1)
        ->and($report['totals']['by_stored_decision']['discard'])->toBe(1)
        ->and(DB::table('decision_records')->where('id', $legacy)->value('decision'))->toBe('discard');
});

it('item 21: backfills the business only from a context row that names one, leaves the rest null and reports each with its reason', function () {
    $approval = Approval::create(['uuid' => (string) Str::uuid(), 'tool_key' => 'commerce.take_order', 'tool_version' => '1.0.0', 'payload' => [],
        'payload_hash' => str_repeat('b', 64), 'risk_tier' => 'r3', 'action_class' => 'approval_required', 'status' => 'pending',
        'business_id' => $this->bionic->id, 'agent_key' => 'executive', 'expires_at' => now()->addDay()]);
    $scoped = w4ShadowRun($this->laladia->id);
    $groupWide = w4ShadowRun(null);
    $thread = Conversation::create(['uuid' => (string) Str::uuid(), 'business_id' => $this->laladia->id, 'channel' => 'messenger', 'status' => 'open', 'opened_at' => now()]);

    $raw = fn (string $type, string $id, ?int $business = null) => DB::table('decision_records')->insertGetId(
        ['context_type' => $type, 'context_id' => $id, 'decision' => 'approved', 'business_id' => $business, 'created_at' => now()]);
    $ids = [
        'approval' => $raw('approval', (string) $approval->id),
        'scoped_run' => $raw('agent_run', $scoped->uuid),
        'thread' => $raw('conversation', $thread->uuid),
        'group_run' => $raw('agent_run', $groupWide->uuid),
        'gone' => $raw('agent_run', (string) Str::uuid()),
        'odd' => $raw('briefing', '42'),
        // Already assigned: never overwritten, even where the context says otherwise.
        'assigned' => $raw('approval', (string) $approval->id, $this->laladia->id),
    ];

    expect((new DecisionBusinessBackfill)->run())->toBe(['approval' => 1, 'agent_run' => 1, 'conversation' => 1]);
    $business = fn (string $k) => DB::table('decision_records')->where('id', $ids[$k])->value('business_id');
    expect($business('approval'))->toBe($this->bionic->id)
        ->and($business('scoped_run'))->toBe($this->laladia->id)
        ->and($business('thread'))->toBe($this->laladia->id)
        ->and($business('group_run'))->toBeNull()
        ->and($business('gone'))->toBeNull()
        ->and($business('odd'))->toBeNull()
        ->and($business('assigned'))->toBe($this->laladia->id);

    // Idempotent: a second run assigns nothing.
    expect((new DecisionBusinessBackfill)->run())->toBe(['approval' => 0, 'agent_run' => 0, 'conversation' => 0]);

    $unassigned = collect(app(UnassignedDecisions::class)->list())->keyBy('id');
    expect($unassigned->keys()->sort()->values()->all())->toBe(collect([$ids['group_run'], $ids['gone'], $ids['odd']])->sort()->values()->all())
        ->and($unassigned[$ids['group_run']]['reason'])->toBe(UnassignedDecisions::CONTEXT_WITHOUT_BUSINESS)
        ->and($unassigned[$ids['gone']]['reason'])->toBe(UnassignedDecisions::CONTEXT_MISSING)
        ->and($unassigned[$ids['odd']]['reason'])->toBe(UnassignedDecisions::UNKNOWN_CONTEXT);

    $this->artisan('hq:decisions:report --unassigned')->expectsOutputToContain('3 decision record(s) without a business')->assertSuccessful();
});

it('item 21: every writer stores the business of its context', function () {
    $run = w4ShadowRun($this->bionic->id);
    $this->actingAsHq($this->owner)->post("/agents/runs/{$run->id}/review", ['verdict' => 'approved'])->assertSessionHasNoErrors();
    expect(DecisionRecord::sole()->business_id)->toBe($this->bionic->id);

    $group = w4ShadowRun(null);
    $this->actingAsHq($this->owner)->post("/agents/runs/{$group->id}/review", ['verdict' => 'approved'])->assertSessionHasNoErrors();
    expect(DecisionRecord::where('context_id', $group->uuid)->sole()->business_id)->toBeNull();
});

it('item 34: a record with no business never widens a restricted read, and cannot be credited by an outcome', function () {
    DB::table('decision_records')->insert(['context_type' => 'agent_run', 'context_id' => w4ShadowRun(null)->uuid, 'decision' => 'approved', 'created_at' => now()]);
    $mine = DB::table('decision_records')->insertGetId(['context_type' => 'agent_run', 'context_id' => w4ShadowRun($this->laladia->id)->uuid,
        'decision' => 'approved', 'business_id' => $this->laladia->id, 'created_at' => now()]);
    $nobody = DB::table('decision_records')->insertGetId(['context_type' => 'briefing', 'context_id' => '7', 'decision' => 'approved', 'created_at' => now()]);

    $restricted = $this->hqUser('owner');
    UserBusinessScope::create(['user_id' => $restricted->id, 'business_id' => $this->laladia->id, 'access' => 'full']);
    $report = app(DecisionCorpusReader::class)->read(DecisionCorpusWindow::from('all'), $restricted->fresh());
    expect($report['totals']['records'])->toBe(1)->and($report['scope']['unscoped_records'])->toBe(0);

    $group = app(DecisionCorpusReader::class)->read(DecisionCorpusWindow::from('all'));
    expect($group['totals']['records'])->toBe(3)->and($group['scope']['unscoped_records'])->toBe(2);

    // An outcome may credit the record that names its business; the one without a business stays refused.
    $store = Store::where('slug', 'laladia-com')->sole();
    $order = FactsOrder::query()->forceCreate(['business_id' => $this->laladia->id, 'store_id' => $store->id, 'order_ref' => 'LD-34', 'source' => 'checkout',
        'status' => 'delivered', 'gross' => 100, 'placed_at' => now()->subDay()]);
    $recorder = app(OutcomeRecorder::class);
    $outcome = $recorder->record(new OutcomeSpec(type: 'order.delivered', businessId: $this->laladia->id, occurredAt: now(), source: OutcomeSource::ShopWebhook,
        sourceSystem: 'shop', provenance: Provenance::Business, idempotencyKey: 'order.delivered:shop:'.$store->id.':LD-34',
        results: [new ResultRefSpec(ResultType::ShopOrder, 'shop', 'laladia-com:LD-34', (int) $order->id)], storeId: $store->id));
    $spec = fn (int $id, string $key) => new AttributionSpec(ActionType::DecisionRecord, $id, 'decision_record:'.$id, AttributionRole::Approved,
        AttributionMethod::Correlation, 'test.v1', 1.0, $key);

    expect($recorder->attribute($outcome, $spec($mine, 'attr:mine'))->decision_record_id)->toBe($mine);
    expect(fn () => $recorder->attribute($outcome, $spec($nobody, 'attr:nobody')))->toThrow(ImplicitHqScopeRefused::class);
});
