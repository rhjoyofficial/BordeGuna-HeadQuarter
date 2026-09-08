<?php

use App\Erp\ErpRegistry;
use App\Gateway\CallerKind;
use App\Gateway\Credentials\CredentialResolver;
use App\Gateway\Descriptor\CredentialRequirement;
use App\Gateway\Descriptor\RiskTier;
use App\Gateway\Descriptor\ToolDescriptor;
use App\Gateway\Identity\ActingIdentity;
use App\Gateway\Identity\IdentityKind;
use App\Gateway\OutcomeStatus;
use App\Gateway\Registry\ToolAvailability;
use App\Gateway\ToolGateway;
use App\Gateway\ToolRequest;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\IntegrationCredential;
use App\Models\JobRun;
use App\Models\ScheduledJob;
use App\Models\ToolCall;
use App\Models\User;
use App\Scheduling\ScheduledJobService;
use App\Scheduling\Tools\Erp\PollErpOverviewTool;
use App\Scheduling\Tools\ToolRegistry;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\Tools\CountingTool;

/*
 | The credential boundary (brief section 13), correlation propagation (section 12) and the
 | scheduler seam (section 17).
 |
 | ⚠ M1 does NOT route the scheduler through the gateway. RunScheduledJob still calls the tool
 | directly, exactly as it does in production, and the six live jobs are untouched. What is proven
 | here is that the request M2 will hand the gateway can be built correctly from a real job and run,
 | so that migration is a small change rather than a discovery.
 */

const ERP_TOKEN = 'erp-token-3f9a1c77b2';

function seamUser(): User
{
    (new RolesAndPermissionsSeeder)->run();
    $user = User::factory()->create();
    $user->assignRole('owner');

    return $user->fresh();
}

function erpBusiness(): Business
{
    return Business::create(['name' => 'Bor de Guna', 'slug' => 'bor-de-guna', 'erp_business_id' => 4]);
}

beforeEach(fn () => Cache::flush());

it('refuses before executing when a declared credential is missing, and names which one', function () {
    $business = erpBusiness(); // no credential stored

    $outcome = app(ToolGateway::class)->execute(ToolRequest::forOperator(
        seamUser(), 'erp.poll_overview', ['business_slug' => 'bor-de-guna'], $business->id,
    ));

    expect($outcome->status)->toBe(OutcomeStatus::Unavailable)
        ->and($outcome->error)->toContain('erp_api_token')
        ->and($outcome->error)->toContain('missing or not active');
});

it('records which credential was used and never what it was', function () {
    $business = erpBusiness();
    IntegrationCredential::create([
        'provider' => ErpRegistry::PROVIDER, 'account_ref' => 'bor-de-guna', 'business_id' => $business->id,
        'credential_type' => 'token', 'payload' => ['value' => ERP_TOKEN], 'status' => 'active',
    ]);

    $resolution = app(CredentialResolver::class)->resolve(
        (new PollErpOverviewTool(app(ErpRegistry::class)))->descriptor(),
        ToolRequest::forOperator(seamUser(), 'erp.poll_overview', ['business_slug' => 'bor-de-guna'], $business->id),
        ['business_slug' => 'bor-de-guna'],
    );

    expect($resolution->satisfied())->toBeTrue()
        ->and($resolution->credentialIds())->toHaveCount(1);

    // Ids and account refs, never a value. There is deliberately no field on the resolution that
    // could hold one, so a future change cannot carry a secret into tool_calls by adding a line.
    expect(json_encode($resolution->toArray()))->not->toContain(ERP_TOKEN);
});

it('keeps the ERP token out of tool_calls and the audit log when a real tool runs', function () {
    $business = erpBusiness();
    IntegrationCredential::create([
        'provider' => ErpRegistry::PROVIDER, 'account_ref' => 'bor-de-guna', 'business_id' => $business->id,
        'credential_type' => 'token', 'payload' => ['value' => ERP_TOKEN], 'status' => 'active',
    ]);

    // The captured fixture, not an invented one. A payload written from the design agrees with code
    // written from the same design and both disagree with production; this file was taken off the
    // real ERP on 7 September (docs/45).
    Http::fake(['*' => Http::response(
        json_decode((string) file_get_contents(base_path('tests/fixtures/erp/overview.json')), true),
        200,
    )]);

    $outcome = app(ToolGateway::class)->execute(ToolRequest::forOperator(
        seamUser(), 'erp.poll_overview', ['business_slug' => 'bor-de-guna'], $business->id,
    ));

    $everything = json_encode([
        ToolCall::findOrFail($outcome->toolCallId)->toArray(),
        AuditLog::all()->toArray(),
    ]);

    expect($everything)->not->toContain(ERP_TOKEN);
});

it('resolves an account ref from the argument first and the business second', function () {
    $business = erpBusiness();
    $other = Business::create(['name' => 'Other', 'slug' => 'other-biz', 'erp_business_id' => 9]);
    foreach (['bor-de-guna', 'other-biz'] as $ref) {
        IntegrationCredential::create([
            'provider' => 'demo_provider', 'account_ref' => $ref, 'credential_type' => 'token',
            'payload' => ['value' => 'v-'.$ref], 'status' => 'active',
        ]);
    }

    $descriptor = new ToolDescriptor(
        key: 'demo', version: '1.0.0', title: 'Demo', purpose: 'p',
        riskTier: RiskTier::R1, permission: 'hq.tools.diagnostics', auditAction: 'tool.demo',
        credentials: [new CredentialRequirement('demo_provider', 'args.business_slug|business.slug')],
    );
    $resolver = app(CredentialResolver::class);
    $user = seamUser();

    // The argument wins.
    $fromArgs = $resolver->resolve($descriptor, ToolRequest::forOperator($user, 'demo', [], $other->id), ['business_slug' => 'bor-de-guna']);
    expect($fromArgs->found[0]['account_ref'])->toBe('bor-de-guna');

    // With no argument, the business the call was filed under.
    $fromBusiness = $resolver->resolve($descriptor, ToolRequest::forOperator($user, 'demo', [], $other->id), []);
    expect($fromBusiness->found[0]['account_ref'])->toBe('other-biz');

    unset($business);
});

it('carries one correlation id from the request through the tool call and the audit row', function () {
    app(ToolRegistry::class)->register(CountingTool::class);
    app(ToolAvailability::class)->forget();

    $correlation = (string) Str::uuid();
    $outcome = app(ToolGateway::class)->execute(new ToolRequest(
        toolKey: 'test.counting', args: [], caller: CallerKind::Console,
        identity: ActingIdentity::human(seamUser()), correlationId: $correlation,
    ));

    // Never regenerated at a layer boundary: a new id at each layer is the same as having none.
    expect(ToolCall::findOrFail($outcome->toolCallId)->correlation_id)->toBe($correlation);
});

it('builds a valid gateway request from a real scheduled job, which is the M2 seam', function () {
    $user = seamUser();
    $business = erpBusiness();

    $job = app(ScheduledJobService::class)->create([
        'name' => 'nightly overview',
        'kind' => 'cron',
        'cron_expression' => '0 4 * * *',
        'tool_key' => 'erp.poll_overview',
        'business_id' => $business->id,
        'payload' => ['business_slug' => 'bor-de-guna'],
    ], $user);

    $run = JobRun::create([
        'scheduled_job_id' => $job->id,
        'scheduled_for' => CarbonImmutable::now(),
        'trigger' => 'tick',
        'attempt' => 1,
        'correlation_id' => (string) Str::uuid(),
    ]);

    $request = ToolRequest::forScheduledJob($job->fresh(), $run, $user);

    expect($request->toolKey)->toBe('erp.poll_overview')
        ->and($request->caller)->toBe(CallerKind::Scheduler)
        ->and($request->args)->toBe(['business_slug' => 'bor-de-guna'])
        ->and($request->businessId)->toBe($business->id)
        // A scheduled job runs as its owning human, not as an anonymous process.
        ->and($request->identity->enablingUser()?->id)->toBe($user->id)
        // The run's own identity is the key, which is what makes at-least-once delivery harmless.
        ->and($request->idempotencyKey)->toBe("job-run:{$run->correlation_id}")
        ->and($request->jobRunId)->toBe($run->id);
});

it('falls back to the narrow system identity for a job nobody owns', function () {
    $business = erpBusiness();
    // HasUuids fills `uuid` itself; naming it here would be an attribute the model discards.
    $job = ScheduledJob::create([
        'name' => 'ownerless', 'kind' => 'cron', 'cron_expression' => '0 4 * * *',
        'tool_key' => 'erp.poll_overview', 'business_id' => $business->id, 'owner_user_id' => null,
    ]);
    $run = JobRun::create([
        'scheduled_job_id' => $job->id, 'scheduled_for' => CarbonImmutable::now(), 'trigger' => 'tick',
        'attempt' => 1, 'correlation_id' => (string) Str::uuid(),
    ]);

    $request = ToolRequest::forScheduledJob($job, $run);

    expect($request->identity->kind)->toBe(IdentityKind::Service)
        ->and($request->identity->label)->toBe('system')
        // Narrow enough that the Phase 1 poll and reconcile jobs fit, and nothing wider does.
        ->and($request->identity->holds('hq.tools.erp.read', 'erp.poll_overview'))->toBeTrue()
        ->and($request->identity->holds('hq.credentials.manage', 'anything'))->toBeFalse();
});
