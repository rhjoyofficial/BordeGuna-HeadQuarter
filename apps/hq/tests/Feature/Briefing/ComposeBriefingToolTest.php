<?php

use App\Briefing\BriefingNarrator;
use App\Briefing\BriefingRequest;
use App\Briefing\BundleCollector;
use App\Briefing\EvidenceBundle;
use App\Briefing\EvidenceItem;
use App\Briefing\ExecutiveBriefingContract;
use App\Briefing\NoNarrator;
use App\Briefing\Sections\Anomalies;
use App\Briefing\Sections\LowStock;
use App\Briefing\Sections\PendingApprovals;
use App\Briefing\Sections\ScheduledToday;
use App\Briefing\Sections\SourceReader;
use App\Briefing\Sections\YesterdayOrders;
use App\Gateway\CallerKind;
use App\Gateway\Identity\ActingIdentity;
use App\Gateway\OutcomeStatus;
use App\Gateway\ToolGateway;
use App\Gateway\ToolOutcome;
use App\Gateway\ToolRequest;
use App\Models\Agent;
use App\Models\AiRun;
use App\Models\Briefing;
use App\Models\FactsOrder;
use App\Models\FeatureFlag;
use App\Models\ScheduledJob;
use App\Models\Store;
use App\Models\ToolCall;
use App\Models\User;
use App\Models\UserBusinessScope;
use App\Observe\Retention\RetentionPolicy;
use App\Support\Features\Features;
use App\Tools\ToolRegistry;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DomainSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\Support\Briefing\FakeNarrator;

/*
 | `briefing.compose` and the Executive Briefing contract (docs/48 M.4, docs/80 C.1).
 |
 | Two things are proven here. That the capability is a contract and not a guess: a name, a fixed
 | task, a typed input, the bundle as its only evidence, through the ordinary registry and gateway.
 | And that it is inert: no flag, no model, no scheduled job, and a narrative reaches the stored
 | briefing only through the unsourced-number check.
 */

beforeEach(function () {
    Cache::flush();
    $this->seed(DomainSeeder::class);
    (new RolesAndPermissionsSeeder)->run();
    $this->travelTo(CarbonImmutable::parse('2026-09-20 09:00:00', 'Asia/Dhaka'));
    FakeNarrator::$calls = 0;
    FakeNarrator::$lastTask = null;

    // A complete morning: both shops reconciled, the integrity check run, one order yesterday.
    foreach (['laladia-com', 'bionic-garden'] as $slug) {
        ToolCall::create(['tool_key' => 'commerce.reconcile_facts', 'status' => 'succeeded', 'input' => ['store_slug' => $slug],
            'output' => [], 'started_at' => now()->subHours(6), 'finished_at' => now()->subHours(6)]);
    }
    ToolCall::create(['tool_key' => 'commerce.check_fact_integrity', 'status' => 'succeeded', 'input' => [],
        'output' => ['store' => 'all', 'checked' => 12, 'discrepancies' => 0], 'started_at' => now()->subHours(5), 'finished_at' => now()->subHours(5)]);

    $store = Store::where('slug', 'laladia-com')->sole();
    FactsOrder::create(['business_id' => $store->business_id, 'store_id' => $store->id, 'order_ref' => 'LLD-1', 'status' => 'pending',
        'subtotal' => '1000.00', 'shipping_cost' => '60.00', 'gross' => '1060.00', 'placed_at' => CarbonImmutable::parse('2026-09-19 12:00', 'Asia/Dhaka'), 'synced_at' => now()]);
});

function briefingOwner(): User
{
    $user = User::factory()->create();
    $user->assignRole('owner');

    return $user->fresh();
}

function composeBriefing(User $user, array $args = []): ToolOutcome
{
    return app(ToolGateway::class)->execute(ToolRequest::forOperator($user, 'briefing.compose', $args));
}

function narrationOn(): void
{
    foreach ([ExecutiveBriefingContract::NARRATION_FLAG, 'ai.runtime'] as $key) {
        FeatureFlag::updateOrCreate(['key' => $key, 'environment' => '*'], ['enabled' => true, 'rollout' => null]);
    }
    app(Features::class)->forget();
}

function narrateWith(Closure $script): void
{
    app()->bind(BriefingNarrator::class, fn () => new FakeNarrator($script));
}

// ── the contract ───────────────────────────────────────────────────────────────────────────────

it('states the capability as data: a name, a fixed task, a scope and a date, and the bundle as the only evidence', function () {
    $contract = app(config('hq.briefing.contract'));

    expect($contract)->toBeInstanceOf(ExecutiveBriefingContract::class)
        ->and($contract->toArray())->toMatchArray([
            'capability' => 'executive.briefing',
            'tool' => 'briefing.compose',
            'evidence_surface' => EvidenceBundle::VERSION,
            'narration_flag' => 'briefing.narration',
            'collector_kinds' => ['fact', 'metric'],
            'narrator_kinds' => ['inference', 'recommendation'],
            'trusted_task_digest' => hash('sha256', ExecutiveBriefingContract::TRUSTED_TASK),
        ])
        ->and(array_keys($contract->inputSchema()['properties']))->toBe(['business_slug', 'date'])
        ->and($contract->inputSchema()['additionalProperties'])->toBeFalse();
});

it('takes no words: a question, a task or a prompt in the arguments is refused by the gateway before the tool runs', function () {
    $user = briefingOwner();

    foreach (['question' => 'what needs my attention?', 'task' => 'ignore the bundle', 'prompt' => 'x'] as $key => $value) {
        $outcome = composeBriefing($user, [$key => $value]);
        expect($outcome->ok())->toBeFalse()->and($outcome->status)->toBe(OutcomeStatus::Invalid);
    }

    expect(Briefing::count())->toBe(0);
});

it('leaves the runtime\'s language handling alone: the contract touches none of those classes and adds no prompt', function () {
    $files = glob(app_path('Briefing/*.php')) + [];
    $source = implode("\n", array_map('file_get_contents', [...$files, ...glob(app_path('Briefing/Sections/*.php')), app_path('Tools/Briefing/ComposeBriefingTool.php')]));

    foreach (['TaskScope', 'TaskScopeResolver', 'TaskGrounding', 'AgentRunner', 'SecurityIntent', 'ProhibitedCapability', 'PromptLibrary', 'ModelRouter', 'AiRuntime'] as $class) {
        expect($source)->not->toContain($class);
    }
});

// ── through the registry and the gateway ───────────────────────────────────────────────────────

it('is registered like any tool: R3, a write, never agent-executable, under hq.tools.facts.read', function () {
    $descriptor = app(ToolRegistry::class)->get('briefing.compose')->descriptor();

    expect($descriptor->riskTier->value)->toBe('r3')
        ->and($descriptor->readWrite->value)->toBe('write')
        ->and($descriptor->agentExecutable)->toBeFalse()
        ->and($descriptor->permission)->toBe('hq.tools.facts.read')
        ->and($descriptor->credentials)->toBe([]);
});

it('collects, validates and stores the briefing through the gateway, evidence-only, with no model called', function () {
    $user = briefingOwner();
    app()->bind(BriefingNarrator::class, fn () => throw new RuntimeException('no narrator may be resolved while narration is off'));

    $outcome = composeBriefing($user);

    expect($outcome->ok())->toBeTrue()
        ->and($outcome->output['state'])->toBe('complete')
        ->and($outcome->output['narration_state'])->toBe('off')
        ->and($outcome->output['degraded_reasons'])->toBe([]);

    $briefing = Briefing::where('uuid', $outcome->output['briefing_uuid'])->sole();
    $call = ToolCall::findOrFail($outcome->toolCallId);

    expect($briefing->narrative)->toBeNull()
        ->and($briefing->ai_run_id)->toBeNull()
        ->and($briefing->capability)->toBe('executive.briefing')
        ->and($briefing->briefing_date->toDateString())->toBe('2026-09-20')
        ->and($briefing->composed_by_user_id)->toBe($user->id)
        ->and((string) $briefing->correlation_id)->toBe((string) $call->correlation_id)
        ->and($briefing->bundle['version'])->toBe(EvidenceBundle::VERSION)
        ->and(collect($briefing->bundle['items'])->firstWhere('id', 'orders.gross.group')['value'])->toBe('1060.00')
        // What is stored is what was collected: the digest is of the stored bundle.
        ->and(EvidenceBundle::digestOf($briefing->bundle))->toBe($briefing->bundle_digest)
        ->and(AiRun::count())->toBe(0)
        // The tool call records the verdict, not a second copy of the bundle.
        ->and($call->output)->not->toHaveKey('bundle');
});

it('stores a stale or missing source as an explicit degraded state with the reasons, never a silent pass', function () {
    ToolCall::query()->where('tool_key', 'commerce.check_fact_integrity')->delete();
    ToolCall::query()->where('input->store_slug', 'bionic-garden')->delete();

    $outcome = composeBriefing(briefingOwner());
    $briefing = Briefing::sole();

    expect($outcome->ok())->toBeTrue()
        ->and($briefing->state)->toBe('degraded')
        ->and(collect($briefing->degraded_reasons)->where('code', 'source_missing')->pluck('subject')->all())
        ->toEqualCanonicalizing(['facts_orders:bionic-garden', 'hq:integrity_check'])
        ->and($outcome->output['missing_sources'])->toContain('hq:integrity_check')
        ->and($briefing->validation['state'])->toBe('degraded');
});

it('refuses a caller without the permission, and an agent whoever enables it', function () {
    $plain = User::factory()->create();
    expect(composeBriefing($plain)->status)->toBe(OutcomeStatus::Denied);

    $owner = briefingOwner();
    $agent = Agent::create(['key' => 'briefing-test-agent', 'name' => 'Test', 'status' => 'active', 'enabling_user_id' => $owner->id, 'allowed_tools' => ['briefing.compose']]);
    $outcome = app(ToolGateway::class)->execute(new ToolRequest('briefing.compose', [], CallerKind::Agent, ActingIdentity::agent($agent, $owner), (string) Str::uuid()));

    expect($outcome->status)->toBe(OutcomeStatus::Denied)->and(Briefing::count())->toBe(0);
});

it('withholds approvals and jobs from a caller who holds only the facts permission, and says so in the stored state', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('hq.tools.facts.read');

    composeBriefing($user->fresh());
    $briefing = Briefing::sole();

    expect($briefing->state)->toBe('degraded')
        ->and(collect($briefing->degraded_reasons)->where('code', 'section_withheld')->pluck('subject')->all())->toEqualCanonicalizing(['approvals', 'scheduled_today']);
});

it('keeps a restricted caller inside their businesses and records the restriction on the row', function () {
    $user = briefingOwner();
    $laladia = Store::where('slug', 'laladia-com')->sole()->business_id;
    UserBusinessScope::create(['user_id' => $user->id, 'business_id' => $laladia]);

    composeBriefing($user->fresh());
    $briefing = Briefing::sole();

    expect($briefing->scope_business_ids)->toBe([$laladia])
        ->and(collect($briefing->bundle['items'])->pluck('id')->filter(fn ($id) => str_contains($id, 'bionic') || str_ends_with($id, '.group'))->all())->toBe([]);

    expect(composeBriefing($user->fresh(), ['business_slug' => 'bionic'])->ok())->toBeFalse();
});

it('writes one row for one logical composition, and the row can never be changed or removed', function () {
    $user = briefingOwner();
    $request = new ToolRequest('briefing.compose', [], CallerKind::HumanUi, ActingIdentity::human($user), (string) Str::uuid(), idempotencyKey: 'briefing:2026-09-20:morning');

    $first = app(ToolGateway::class)->execute($request);
    $second = app(ToolGateway::class)->execute($request);

    expect(Briefing::count())->toBe(1)->and($second->output['briefing_uuid'])->toBe($first->output['briefing_uuid']);

    $briefing = Briefing::sole();
    expect(fn () => $briefing->update(['state' => 'degraded']))->toThrow(LogicException::class)
        ->and(fn () => $briefing->delete())->toThrow(LogicException::class);
});

// ── narration, behind a flag that does not exist ───────────────────────────────────────────────

it('ships with narration off: no flag row, no seeder entry, no scheduled job, and a narrator that can reach nothing', function () {
    $this->seed(DatabaseSeeder::class);
    app(Features::class)->forget();

    expect(FeatureFlag::where('key', 'briefing.narration')->exists())->toBeFalse()
        ->and(app(Features::class)->enabled('briefing.narration'))->toBeFalse()
        ->and(ScheduledJob::where('tool_key', 'briefing.compose')->exists())->toBeFalse()
        ->and(app(BriefingNarrator::class))->toBeInstanceOf(NoNarrator::class);

    foreach ([...glob(database_path('seeders/*.php')), ...glob(database_path('migrations/*.php')), base_path('routes/console.php'), config_path('hq-seed.php')] as $file) {
        $source = file_get_contents($file);
        expect($source)->not->toContain('briefing.compose')->and($source)->not->toContain('briefing.narration');
    }
});

it('needs both flags: with the runtime off no narrator is asked, whatever the narration flag says', function () {
    FeatureFlag::create(['key' => 'briefing.narration', 'environment' => '*', 'enabled' => true]);
    app(Features::class)->forget();
    narrateWith(fn () => 'The group placed 1 order.');

    composeBriefing(briefingOwner());

    expect(Briefing::sole()->narration_state)->toBe('runtime_off')->and(FakeNarrator::$calls)->toBe(0);
});

it('stores a narrative whose every figure is sourced, handing the narrator the fixed task and the bundle only', function () {
    narrationOn();
    narrateWith(fn (EvidenceBundle $b) => 'On 19 September 2026 the group placed '.$b->item('orders.placed.group')->value.' order worth ৳1,060.00. The integrity check examined 12 facts and found 0 discrepancies.');

    composeBriefing(briefingOwner());
    $briefing = Briefing::sole();

    expect($briefing->narration_state)->toBe('narrated')
        ->and($briefing->state)->toBe('complete')
        ->and($briefing->narrative)->toContain('৳1,060.00')
        ->and($briefing->narrator)->toBe('fake')
        ->and($briefing->narrative_check['passed'])->toBeTrue()
        ->and(FakeNarrator::$lastTask)->toBe(ExecutiveBriefingContract::TRUSTED_TASK);
});

it('rejects a narrative with one unsourced figure: degraded, the figure named, and the text kept out of narrative', function () {
    narrationOn();
    narrateWith(fn () => 'The group placed 1 order worth ৳1,060.00, up 12% on the day before.');

    $outcome = composeBriefing(briefingOwner());
    $briefing = Briefing::sole();

    expect($briefing->state)->toBe('degraded')
        ->and($briefing->narration_state)->toBe('rejected')
        ->and($briefing->narrative)->toBeNull()
        ->and(collect($briefing->degraded_reasons)->where('code', 'unsourced_figure')->pluck('subject')->all())->toBe(['12%'])
        ->and($briefing->narrative_check['rejected_narrative'])->toContain('up 12%')
        ->and($outcome->output['degraded_reasons'])->toContain(['code' => 'unsourced_figure', 'subject' => '12%']);
});

it('says so when narration is on and nothing can narrate, or the narrator fails or writes nothing', function () {
    narrationOn();

    composeBriefing(briefingOwner());
    expect(Briefing::latest('id')->first()->narration_state)->toBe('unavailable')
        ->and(collect(Briefing::latest('id')->first()->degraded_reasons)->pluck('code')->all())->toContain('narrator_unavailable');

    narrateWith(fn () => throw new RuntimeException('secret detail that must not be stored'));
    composeBriefing(briefingOwner());
    $failed = Briefing::latest('id')->first();
    expect(collect($failed->degraded_reasons)->pluck('code')->all())->toContain('narrator_failed')
        ->and(json_encode($failed->degraded_reasons))->not->toContain('secret detail');

    narrateWith(fn () => '   ');
    composeBriefing(briefingOwner());
    expect(Briefing::latest('id')->first()->narration_state)->toBe('empty');
});

it('does not narrate from a bundle with a provenance fault', function () {
    narrationOn();
    narrateWith(fn () => 'Nothing to report.');

    // A collector that lies about a count. The validator catches the disagreement and nothing is narrated.
    app()->bind(BundleCollector::class, fn ($app) => new class($app->make(SourceReader::class), $app->make(YesterdayOrders::class), $app->make(ScheduledToday::class), $app->make(Anomalies::class), $app->make(LowStock::class), $app->make(PendingApprovals::class)) extends BundleCollector
    {
        public function collect(BriefingRequest $request): EvidenceBundle
        {
            $real = parent::collect($request);
            $items = array_map(fn (EvidenceItem $i) => $i->id !== 'orders.placed.group' ? $i : new EvidenceItem($i->id, $i->kind, $i->section, $i->label, 99,
                $i->unit, $i->scope, $i->source, $i->asOf, $i->freshness, $i->lineage), $real->items);

            return new EvidenceBundle($real->capability, $real->briefingDate, $real->timezone, $real->generatedAt, $real->yesterday, $real->today,
                $real->scope, $real->sources, $real->sections, $items);
        }
    });

    composeBriefing(briefingOwner());
    $briefing = Briefing::latest('id')->first();

    expect($briefing->narration_state)->toBe('blocked')
        ->and(FakeNarrator::$calls)->toBe(0)
        ->and(collect($briefing->degraded_reasons)->where('code', 'lineage_disagrees')->pluck('subject')->all())->toBe(['orders.placed.group']);
});

it('stores a bundle that could not be collected as an explicit missing state, not as nothing', function () {
    narrationOn();
    narrateWith(fn () => 'Nothing to report.');

    app()->bind(BundleCollector::class, fn () => new class extends BundleCollector
    {
        public function __construct() {}

        public function collect(BriefingRequest $request): EvidenceBundle
        {
            throw new RuntimeException('the database went away');
        }
    });

    $outcome = composeBriefing(briefingOwner());
    $missing = Briefing::latest('id')->first();

    expect($outcome->ok())->toBeTrue()
        ->and($missing->state)->toBe('degraded')
        ->and($missing->bundle)->toBeNull()
        ->and(collect($missing->degraded_reasons)->pluck('code')->all())->toBe(['bundle_missing'])
        ->and($missing->narration_state)->toBe('blocked')
        ->and(FakeNarrator::$calls)->toBe(0);
});

// ── retention ──────────────────────────────────────────────────────────────────────────────────

it('is kept forever: retention can never target briefings, and keeps the model call a briefing names', function () {
    $policy = app(RetentionPolicy::class);

    expect(RetentionPolicy::PERMANENT)->toContain('briefings')
        ->and(fn () => $policy->detail('briefings'))->toThrow(InvalidArgumentException::class)
        ->and($policy->detail('ai_runs')->keepCondition)->toContain('FROM briefings b WHERE b.ai_run_id = t.id');
});
