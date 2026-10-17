<?php

use App\Ask\AskHq;
use App\Gateway\ToolGateway;
use App\Gateway\ToolRequest;
use App\Models\Alert;
use App\Models\Approval;
use App\Models\Business;
use App\Models\FactsOrder;
use App\Models\FeatureFlag;
use App\Models\Store;
use App\Models\ToolCall;
use App\Models\User;
use App\Models\UserBusinessScope;
use App\Scheduling\Commands\SchedulerTick;
use App\Support\Features\Features;
use Carbon\CarbonImmutable;
use Database\Seeders\DomainSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PragmaRX\Google2FA\Google2FA;

/*
 | docs/135 W-UI2: the console on /dashboard. The brief, the decision cards, the live panel and the
 | composer, each behind its own screen's permission and inside the person's business restriction,
 | with switched-off things stated and no way to decide or write from the console itself.
 */

beforeEach(function () {
    Cache::flush();
    $this->seed(DomainSeeder::class);
    (new RolesAndPermissionsSeeder)->run();
    $this->travelTo(CarbonImmutable::parse('2026-09-20 09:00:00', 'Asia/Dhaka'));
});

function consoleUser(array|string $grant = 'owner'): User
{
    $user = User::factory()->create();
    is_array($grant) ? $user->givePermissionTo(array_merge(['hq.dashboard.view'], $grant)) : $user->assignRole($grant);
    $user->forceFill(['two_factor_secret' => app(Google2FA::class)->generateSecretKey(), 'two_factor_confirmed_at' => now()])->save();

    return $user->fresh();
}

function consoleRestrictTo(User $user, string $businessSlug): void
{
    UserBusinessScope::create(['user_id' => $user->id, 'business_id' => Business::where('slug', $businessSlug)->value('id')]);
}

function consoleApproval(?string $businessSlug, array $overrides = []): Approval
{
    return Approval::create(array_merge([
        'uuid' => (string) Str::uuid(), 'tool_key' => 'demo.request_approval', 'tool_version' => '1.0.0',
        'payload' => ['amount' => 100], 'payload_hash' => str_repeat('a', 64), 'risk_tier' => 'r4',
        'action_class' => 'approval_required', 'reason' => 'a reason', 'status' => 'pending',
        'business_id' => $businessSlug === null ? null : Business::where('slug', $businessSlug)->value('id'),
        'sla_due_at' => now()->addHours(4), 'expires_at' => now()->addDay(),
    ], $overrides));
}

function consoleFlag(string $key, bool $on): void
{
    FeatureFlag::updateOrCreate(['key' => $key, 'environment' => '*'], ['enabled' => $on, 'rollout' => null]);
    app(Features::class)->forget();
}

function consoleProps(User $user): array
{
    $props = null;
    test()->actingAsHq($user)->get('/dashboard')->assertOk()
        ->assertInertia(function (Assert $page) use (&$props) {
            $page->component('dashboard')->has('console');
            $props = $page->toArray()['props']['console'];
        });

    return $props;
}

it('carries every console block for an owner, with the switched-off composer saying why', function () {
    $console = consoleProps(consoleUser());

    expect($console)->toHaveKeys(['brief', 'decisions', 'receipts', 'live', 'composer', 'generated_at', 'timezone'])
        ->and($console['brief'])->not->toBeNull()
        ->and($console['decisions'])->toBe(['total' => 0, 'overdue' => 0, 'cards' => []])
        ->and($console['live'])->toHaveKeys(['scheduler', 'alerts', 'cost', 'guard', 'posture'])
        ->and($console['live']['alerts'])->not->toBeNull()
        ->and($console['live']['cost'])->not->toBeNull()
        ->and($console['live']['guard'])->not->toBeNull()
        ->and($console['live']['scheduler']['jobs'])->not->toBeNull()
        // Inert by default: the flag and the runtime are both off, so the composer is not usable and
        // hands the screen no agent list to pick from.
        ->and($console['composer']['permitted'])->toBeTrue()
        ->and($console['composer']['flag_on'])->toBeFalse()
        ->and($console['composer']['runtime_on'])->toBeFalse()
        ->and($console['composer']['usable'])->toBeFalse()
        ->and($console['composer']['agents'])->toBe([])
        ->and($console['live']['posture']['ai_runtime'])->toBeFalse();
});

it('gives a person with only the dashboard permission no brief, no cards and no live blocks of other screens', function () {
    consoleApproval('laladia');
    Alert::create(['rule' => 'demo', 'fingerprint' => 'x', 'severity' => 'warning', 'subject' => 'S', 'body' => 'B',
        'first_seen_at' => now(), 'last_seen_at' => now()]);

    $console = consoleProps(consoleUser([]));

    expect($console['brief'])->toBeNull()
        ->and($console['decisions'])->toBeNull()
        ->and($console['receipts'])->toBeNull()
        ->and($console['live']['alerts'])->toBeNull()
        ->and($console['live']['cost'])->toBeNull()
        ->and($console['live']['guard'])->toBeNull()
        ->and($console['live']['scheduler']['jobs'])->toBeNull()
        ->and($console['composer']['permitted'])->toBeFalse()
        ->and($console['composer']['usable'])->toBeFalse();
});

it('shows no approval card to a person without the approvals permission, even with every other permission', function () {
    consoleApproval('laladia');
    $all = array_values(array_diff(RolesAndPermissionsSeeder::PERMISSIONS, ['hq.approvals.view', 'hq.approvals.decide', 'hq.approvals.delegate']));

    $console = consoleProps(consoleUser($all));

    expect($console['decisions'])->toBeNull()->and($console['receipts'])->toBeNull();
});

it('scopes decision cards and receipts to the person\'s businesses, as the dashboard counts them', function () {
    $mine = consoleApproval('laladia');
    $theirs = consoleApproval('bionic');
    $group = consoleApproval(null);
    consoleApproval('bionic', ['status' => 'rejected', 'decided_at' => now()->subHour()]);
    $myReceipt = consoleApproval('laladia', ['status' => 'rejected', 'decided_at' => now()->subHours(2)]);

    $restricted = consoleUser(['hq.approvals.view']);
    consoleRestrictTo($restricted, 'laladia');
    $console = consoleProps($restricted);

    expect($console['decisions']['total'])->toBe(1)
        ->and(collect($console['decisions']['cards'])->pluck('id')->all())->toBe([$mine->id])
        ->and(collect($console['receipts'])->pluck('id')->all())->toBe([$myReceipt->id]);

    $everyone = consoleProps(consoleUser(['hq.approvals.view']));
    expect($everyone['decisions']['total'])->toBe(3)
        ->and(collect($everyone['decisions']['cards'])->pluck('id')->sort()->values()->all())->toBe(collect([$mine->id, $theirs->id, $group->id])->sort()->values()->all());
});

it('makes a card a link to the approval and never a decision', function () {
    $approval = consoleApproval('laladia');

    $card = consoleProps(consoleUser())['decisions']['cards'][0];

    expect($card['href'])->toBe("/approvals/{$approval->id}")
        ->and($card)->not->toHaveKey('payload')
        ->and($card)->not->toHaveKey('approve_url')
        ->and($card['risk_tier'])->toBe('r4');
});

it('never turns a store without facts into a zero, and withholds a total over some of the stores', function () {
    // laladia.com has a reconcile and an order; bionic.garden has neither, so it has no figure.
    ToolCall::create(['tool_key' => 'commerce.reconcile_facts', 'status' => 'succeeded', 'input' => ['store_slug' => 'laladia-com'],
        'output' => ['store' => 'laladia-com'], 'started_at' => now()->subHours(2), 'finished_at' => now()->subHours(2)]);
    $store = Store::where('slug', 'laladia-com')->sole();
    FactsOrder::create(['business_id' => $store->business_id, 'store_id' => $store->id, 'order_ref' => 'LLD-1', 'status' => 'delivered',
        'source' => 'checkout', 'subtotal' => 1000, 'discount_total' => 0, 'shipping_cost' => 60, 'gross' => 1060,
        'placed_at' => now()->subDay(), 'synced_at' => now()->subHours(3), 'lines' => []]);

    $brief = consoleProps(consoleUser())['brief'];
    $figures = collect($brief['figures'])->keyBy('id');
    $sources = collect($brief['sources'])->keyBy('key');

    expect($brief['date'])->toBe('2026-09-19')
        ->and($figures['orders.placed.business:laladia']['value'])->toBe(1)
        ->and($figures->has('orders.placed.business:bionic'))->toBeFalse()
        ->and($figures->has('orders.placed.group'))->toBeFalse()
        ->and($sources['facts_orders:laladia-com']['state'])->toBe('fresh')
        ->and($sources['facts_orders:bionic-garden']['state'])->toBe('missing')
        ->and($sources['facts_orders:econic-store']['state'])->toBe('not_connected')
        ->and(collect($brief['notes'])->pluck('status')->all())->toContain('unavailable', 'total_withheld')
        ->and(collect($brief['not_connected'])->pluck('key')->all())->toBe(['marketing', 'finance']);
});

it('reads the brief inside the person\'s restriction, sources and stored briefings alike', function () {
    $owner = consoleUser();
    app(ToolGateway::class)->execute(ToolRequest::forOperator($owner, 'briefing.compose', []));

    expect(consoleProps($owner)['brief']['stored'])->not->toBeNull()
        ->and(consoleProps($owner)['brief']['group_wide'])->toBeTrue();

    $restricted = consoleUser(['hq.tools.facts.read']);
    consoleRestrictTo($restricted, 'laladia');
    $brief = consoleProps($restricted)['brief'];

    // A group-wide briefing is for people who see the group (the briefings screen's own rule).
    expect($brief['stored'])->toBeNull()
        ->and($brief['group_wide'])->toBeFalse()
        ->and(collect($brief['sources'])->pluck('business')->unique()->values()->all())->toBe(['laladia']);
});

it('says the scheduler is stopped when it has no heartbeat, and running when it ticked', function () {
    $owner = consoleUser();

    expect(consoleProps($owner)['live']['scheduler'])->toMatchArray(['last_tick' => null, 'running' => false]);

    Cache::put(SchedulerTick::HEARTBEAT_KEY, now()->subMinute()->toIso8601String(), 600);
    expect(consoleProps($owner)['live']['scheduler']['running'])->toBeTrue();
});

it('opens the composer only with the permission, the ask_hq flag and the AI runtime all on', function () {
    $owner = consoleUser();

    consoleFlag(AskHq::FLAG, true);
    $flagOnly = consoleProps($owner)['composer'];
    expect($flagOnly['flag_on'])->toBeTrue()->and($flagOnly['runtime_on'])->toBeFalse()->and($flagOnly['usable'])->toBeFalse();

    consoleFlag((string) config('hq.ai.enabled_flag', 'ai.runtime'), true);
    $open = consoleProps($owner)['composer'];
    expect($open['usable'])->toBeTrue()
        ->and(collect($open['agents'])->pluck('key')->all())->toBe(app(AskHq::class)->askableAgents());

    $noPermission = consoleProps(consoleUser(['hq.approvals.view']))['composer'];
    expect($noPermission['permitted'])->toBeFalse()->and($noPermission['usable'])->toBeFalse();
});

it('adds no route: the console is the one GET it always was, and its only form posts to the existing Ask HQ route', function () {
    $dashboardRoutes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'dashboard'))->values();
    expect($dashboardRoutes)->toHaveCount(1)
        ->and($dashboardRoutes[0]->methods())->toBe(['GET', 'HEAD']);

    $files = [resource_path('js/pages/dashboard.tsx'), ...glob(resource_path('js/components/console/*.tsx'))];
    expect(count($files))->toBeGreaterThan(1);

    foreach ($files as $file) {
        $source = file_get_contents($file);
        // Every post, put, patch or delete on the console goes to /ask and nowhere else; nothing approves.
        preg_match_all('/\.(post|put|patch|delete)\(\s*[\'"`]([^\'"`]+)/', $source, $m);
        expect(array_unique($m[2]))->each->toBe('/ask');
        expect($source)->not->toContain('/approve')
            ->and($source)->not->toContain('/reject')
            ->and($source)->not->toMatch('/router\.(post|put|patch|delete)/');
    }

    // The reader itself writes nothing.
    $reader = file_get_contents(app_path('Observe/Console/ConsoleReader.php'));
    expect($reader)->not->toMatch('/->(save|update|delete|insert|forceFill|increment|decrement)\(|::create\(|::updateOrCreate\(|->execute\(/');
});

it('renders every switched-off state with its reason, never hidden: the composer, Do and Plan, the stopped scheduler, missing figures', function () {
    $composer = file_get_contents(resource_path('js/components/console/composer.tsx'));
    $live = file_get_contents(resource_path('js/components/console/live-panel.tsx'));
    $brief = file_get_contents(resource_path('js/components/console/brief.tsx'));

    // One reason per missing switch, in the order the server reports them.
    expect($composer)->toContain('needs the permission hq.ask.use')
        ->and($composer)->toContain('switched off (flag ask_hq)')
        ->and($composer)->toContain('The AI runtime is off, so HQ cannot answer a question yet')
        // Do and Plan are drawn and disabled, with the A0 reason as their title and in the text.
        ->and(preg_match_all('/disabled\s+aria-disabled="true"\s+title=\{modeReason\}/', $composer))->toBe(2)
        ->and($composer)->toContain('Do and Plan are off: agents are at A0, so HQ proposes and a person acts.');

    expect($live)->toContain('The scheduler is not ticking')
        ->and($live)->toContain('No new alert is raised while the scheduler is stopped');

    // A missing figure says so; nothing in the brief falls back to a zero.
    expect($brief)->toContain("t('no figure')")
        ->and($brief)->toContain('That is not the same as no orders.')
        ->and($brief)->not->toMatch('/\?\?\s*0\b/');
});
