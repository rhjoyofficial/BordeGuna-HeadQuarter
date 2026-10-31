<?php

use App\Avatar\AvatarProvider;
use App\Avatar\FakeAvatarProvider;
use App\Gateway\OutcomeClass;
use App\Media\Budget\MediaBudgetRefused;
use App\Media\Budget\MediaBudgets;
use App\Media\Budget\MediaCost;
use App\Models\AuditLog;
use App\Models\AvatarConsent;
use App\Models\Brand;
use App\Models\Business;
use App\Models\MediaBudget;
use App\Models\MediaBudgetEntry;
use App\Models\UserBusinessScope;
use App\Tools\Avatar\GenerateAvatarTool;
use App\Tools\ToolContext;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DomainSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Support\Completion\M4;
use Tests\Support\Media\Budgets;

/*
 | Ruling 31 Oct 2026 item 12 (docs/147-r2): a per-business monthly media spending cap in BDT. Default BDT 0 = no paid media;
 | owner-controlled; Asia/Dhaka month; reservation before the provider, transaction-safe under concurrency; reconciliation keeps
 | estimate and actual; conservative conversion; refuse on unknown cost or rate; no borrowing between businesses; no AI increase.
 */

beforeEach(function () {
    $this->seed(DomainSeeder::class);
    $this->laladia = Business::where('slug', 'laladia')->sole();
    $this->bionic = Business::where('slug', 'bionic')->sole();
    $this->budgets = app(MediaBudgets::class);
});

it('ships no budget row and a limit of BDT 0 for every business, and BDT 0 refuses paid media before anything is written', function () {
    $this->seed(DatabaseSeeder::class);
    expect(MediaBudget::query()->count())->toBe(0)->and(MediaBudgetEntry::query()->count())->toBe(0);

    foreach (Business::all() as $business) {
        expect($this->budgets->limitBdt($business->id))->toBe('0.00');
        expect(fn () => $this->budgets->reserve($business->id, 'video', 'p', 't', 'r-'.$business->id, new MediaCost('1.00', 'BDT')))
            ->toThrow(MediaBudgetRefused::class, 'BDT 0');
    }
    expect(MediaBudgetEntry::query()->count())->toBe(0);
});

it('reserves within the limit, refuses what would exceed it, gives back a release, and counts a reconciled line at its actual', function () {
    Budgets::grant($this->laladia->id, '100.00');

    $a = $this->budgets->reserve($this->laladia->id, 'video', 'p', 't', 'r1', new MediaCost('60.00', 'BDT'));
    expect($a->state)->toBe('reserved')->and($this->budgets->usedBdt($this->laladia->id))->toBe('60.00');
    expect(fn () => $this->budgets->reserve($this->laladia->id, 'video', 'p', 't', 'r2', new MediaCost('40.01', 'BDT')))->toThrow(MediaBudgetRefused::class);

    // Same reference: the line already reserved, never a second reservation.
    expect($this->budgets->reserve($this->laladia->id, 'video', 'p', 't', 'r1', new MediaCost('60.00', 'BDT'))->id)->toBe($a->id)
        ->and($this->budgets->usedBdt($this->laladia->id))->toBe('60.00');

    $this->budgets->release($a);
    expect($this->budgets->usedBdt($this->laladia->id))->toBe('0.00');

    $b = $this->budgets->reserve($this->laladia->id, 'voice', 'p', 't', 'r3', new MediaCost('50.00', 'BDT'));
    $this->budgets->commit($b);
    $owner = $this->hqUser('owner');
    $this->budgets->reconcileActual($b->fresh(), new MediaCost('20.00', 'BDT'), $owner);
    $b->refresh();
    expect($b->state)->toBe('reconciled')->and((string) $b->estimate_bdt)->toBe('50.00')->and((string) $b->actual_bdt)->toBe('20.00')
        ->and($this->budgets->usedBdt($this->laladia->id))->toBe('20.00')
        ->and(AuditLog::query()->where('action', 'media.budget.reconciled')->count())->toBe(1);
});

it('converts conservatively (rate plus margin, rounded up) and refuses an unknown price, currency or rate', function () {
    Budgets::grant($this->laladia->id, '1000.00');
    config(['hq.media.budget.fx_to_bdt' => ['USD' => '130.00'], 'hq.media.budget.fx_safety_margin_percent' => '5']);

    expect($this->budgets->toBdt(new MediaCost('1.00', 'USD')))->toBe(['rate' => '136.500000', 'bdt' => '136.50'])
        ->and($this->budgets->toBdt(new MediaCost('0.001', 'USD'))['bdt'])->toBe('0.14');

    expect(fn () => $this->budgets->reserve($this->laladia->id, 'video', 'p', 't', 'eur', new MediaCost('1.00', 'EUR')))->toThrow(MediaBudgetRefused::class, 'EUR')
        ->and(fn () => $this->budgets->reserve($this->laladia->id, 'video', 'veo', 't', 'np', $this->budgets->estimate('veo', 8)))->toThrow(MediaBudgetRefused::class, 'not known');
    config(['hq.media.budget.fx_to_bdt' => ['USD' => '']]);
    expect(fn () => $this->budgets->reserve($this->laladia->id, 'video', 'p', 't', 'usd', new MediaCost('1.00', 'USD')))->toThrow(MediaBudgetRefused::class, 'rate');

    // The shipped real-provider prices are unknown, so every real paid provider is refused until Joy sets a price.
    foreach (['veo', 'azure_speech', 'elevenlabs', 'openai_compatible_tts'] as $provider) {
        expect($this->budgets->estimate($provider, 10))->toBeNull();
    }
    expect(MediaBudgetEntry::query()->count())->toBe(0);
});

it('counts the month in Asia/Dhaka business dates', function () {
    // 31 Oct 19:30 UTC is 1 Nov 01:30 in Dhaka.
    expect($this->budgets->period(CarbonImmutable::parse('2026-10-31 19:30:00', 'UTC')))->toBe('2026-11')
        ->and($this->budgets->period(CarbonImmutable::parse('2026-10-31 17:59:00', 'UTC')))->toBe('2026-10');

    Budgets::grant($this->laladia->id, '100.00');
    $this->travelTo(CarbonImmutable::parse('2026-10-31 17:00:00', 'UTC'));
    $this->budgets->reserve($this->laladia->id, 'video', 'p', 't', 'oct', new MediaCost('90.00', 'BDT'));
    // A new Dhaka month starts with the whole limit, though it is still October in UTC.
    $this->travelTo(CarbonImmutable::parse('2026-10-31 18:30:00', 'UTC'));
    expect($this->budgets->usedBdt($this->laladia->id))->toBe('0.00');
    expect($this->budgets->reserve($this->laladia->id, 'video', 'p', 't', 'nov', new MediaCost('90.00', 'BDT'))->period)->toBe('2026-11');
});

it('never lets one business borrow another\'s budget', function () {
    Budgets::grant($this->laladia->id, '500.00');

    expect(fn () => $this->budgets->reserve($this->bionic->id, 'video', 'p', 't', 'b1', new MediaCost('1.00', 'BDT')))->toThrow(MediaBudgetRefused::class, 'BDT 0');
    $this->budgets->reserve($this->laladia->id, 'video', 'p', 't', 'l1', new MediaCost('500.00', 'BDT'));
    expect($this->budgets->usedBdt($this->bionic->id))->toBe('0.00')->and($this->budgets->usedBdt($this->laladia->id))->toBe('500.00');
});

it('lets only the owner set a limit, for a business in scope, with a reason, audited; an operator cannot raise it', function () {
    $owner = $this->hqUser('owner');
    $operator = $this->hqUser('operator');

    $this->actingAsHq($operator)->post("/connectors/media-budget/{$this->laladia->slug}", ['monthly_limit_bdt' => '1000', 'reason' => 'more reels'])
        ->assertSessionHasErrors('media_budget');
    $this->actingAsHq($this->hqUser('support_agent'))->post("/connectors/media-budget/{$this->laladia->slug}", ['monthly_limit_bdt' => '1000', 'reason' => 'x'])
        ->assertForbidden();
    expect($this->budgets->limitBdt($this->laladia->id))->toBe('0.00');

    $this->actingAsHq($owner)->post("/connectors/media-budget/{$this->laladia->slug}", ['monthly_limit_bdt' => '2500.50', 'reason' => 'November reels'])
        ->assertSessionHasNoErrors();
    expect($this->budgets->limitBdt($this->laladia->id))->toBe('2500.50')
        ->and(AuditLog::query()->where('action', 'media.budget.set')->where('actor_id', $owner->id)->count())->toBe(1);

    // An owner limited to one business cannot see or set another's.
    $limited = $this->hqUser('owner');
    UserBusinessScope::create(['user_id' => $limited->id, 'business_id' => $this->laladia->id]);
    $this->actingAsHq($limited)->post("/connectors/media-budget/{$this->bionic->slug}", ['monthly_limit_bdt' => '10', 'reason' => 'x'])->assertNotFound();
    $this->actingAsHq($limited)->get('/connectors')->assertOk()
        ->assertInertia(fn ($p) => $p->where('media_budget.businesses', fn ($b) => collect($b)->pluck('slug')->all() === ['laladia']));
    expect($this->budgets->limitBdt($this->bionic->id))->toBe('0.00');
});

it('keeps another business\'s ledger lines out of reach when reconciling', function () {
    Budgets::grant($this->bionic->id, '100.00');
    $line = $this->budgets->reserve($this->bionic->id, 'video', 'p', 't', 'x', new MediaCost('10.00', 'BDT'));
    $this->budgets->commit($line);
    $limited = $this->hqUser('owner');
    UserBusinessScope::create(['user_id' => $limited->id, 'business_id' => $this->laladia->id]);

    $this->actingAsHq($limited)->post("/connectors/media-budget/entries/{$line->uuid}/reconcile", ['actual_amount' => '0', 'actual_currency' => 'BDT'])->assertNotFound();
    expect($line->fresh()->state)->toBe('committed');
});

it('gives no tool, job, agent or model path a way to change a budget', function () {
    $offenders = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if (! str_ends_with($file->getFilename(), '.php')) {
            continue;
        }
        $path = str_replace(DIRECTORY_SEPARATOR, '/', $file->getPathname());
        if (str_ends_with($path, '/Media/Budget/MediaBudgets.php') || str_ends_with($path, '/Http/Controllers/Connectors/MediaBudgetController.php')) {
            continue;
        }
        $source = (string) file_get_contents($file->getPathname());
        // setLimit and reconcile are the only writers of a limit or an actual; MediaBudget is written nowhere else.
        if (preg_match('/->setLimit\s*\(|->reconcileActual\s*\(|MediaBudget::(query\(\)->)?(create|update|updateOrCreate|insert)/', $source) === 1) {
            $offenders[] = $path;
        }
    }
    expect($offenders)->toBe([]);
});

it('reserves avatar generation against the business budget: BDT 0 refuses before the provider, a budget commits the estimate', function () {
    M4::on('avatar.generation');
    app()->instance(AvatarProvider::class, $provider = new FakeAvatarProvider);
    $owner = $this->hqUser('owner');
    $lld = Brand::where('code', 'LLD')->sole();
    $consent = AvatarConsent::query()->create(['uuid' => (string) Str::uuid(), 'business_id' => $this->laladia->id, 'person_label' => 'Presenter A',
        'kind' => 'synthetic_persona', 'modalities' => ['face'], 'permitted_uses' => ['product_promo'], 'brand_ids' => [$lld->id],
        'expires_at' => now()->addMonths(6)->toDateString(), 'recorded_by' => $owner->id]);
    $args = ['business_slug' => $this->laladia->slug, 'consent_uuid' => $consent->uuid, 'brand_id' => $lld->id, 'use' => 'product_promo', 'modality' => 'face', 'script' => 'হ্যালো'];
    $tool = app(GenerateAvatarTool::class);

    $zero = $tool->execute($args, new ToolContext('a0', 'c0', $this->laladia->id, actorUserId: $owner->id));
    expect($zero->ok())->toBeFalse()->and($zero->class)->toBe(OutcomeClass::PolicyDenied)->and($provider->requests)->toBe([]);

    Budgets::grant($this->laladia->id, '500.00');
    $done = $tool->execute($args, new ToolContext('a1', 'c1', $this->laladia->id, actorUserId: $owner->id));
    expect($done->ok())->toBeTrue()->and(count($provider->requests))->toBe(1)
        ->and(MediaBudgetEntry::sole()->kind)->toBe('avatar')->and(MediaBudgetEntry::sole()->state)->toBe('committed')
        ->and((string) MediaBudgetEntry::sole()->estimate_bdt)->toBe('136.50');
});

it('never lets concurrent reservations from separate processes add up to more than the limit', function () {
    // Real concurrency: committed rows (outside the test transaction) and eight separate PHP processes reserving at one instant.
    $name = 'r2_committed';
    config(["database.connections.{$name}" => config('database.connections.'.config('database.default'))]);
    $db = DB::connection($name);
    $database = $db->getDatabaseName();
    $slug = 'r2-race-'.Str::lower(Str::random(6));
    $businessId = (int) $db->table('businesses')->insertGetId(['name' => 'Race test', 'slug' => $slug, 'created_at' => now(), 'updated_at' => now()]);
    $db->table('media_budgets')->insert(['business_id' => $businessId, 'monthly_limit_bdt' => '100.00', 'created_at' => now(), 'updated_at' => now()]);

    try {
        $php = (new PhpExecutableFinder)->find(false) ?: 'php';
        $startAt = microtime(true) + 4.0;
        $processes = [];
        foreach (range(1, 8) as $i) {
            $p = new Process([$php, base_path('tests/Support/Media/reserve-probe.php'), (string) $businessId, '30.00', "race-{$i}", (string) $startAt], base_path(),
                ['APP_ENV' => 'testing', 'DB_CONNECTION' => (string) config('database.default'), 'DB_DATABASE' => $database, 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync']);
            $p->setTimeout(120);
            $p->start();
            $processes[] = $p;
        }
        $results = [];
        foreach ($processes as $p) {
            $p->wait();
            $results[] = trim($p->getOutput()) ?: 'ERROR: '.trim($p->getErrorOutput());
        }

        $ok = count(array_filter($results, fn ($r) => $r === 'OK'));
        $refused = count(array_filter($results, fn ($r) => $r === 'REFUSED'));
        expect($ok + $refused)->toBe(8, implode(' | ', $results))
            ->and($ok)->toBe(3);
        $sum = (string) $db->table('media_budget_ledger')->where('business_id', $businessId)->whereIn('state', ['reserved', 'committed'])->sum('estimate_bdt');
        expect(bccomp($sum, '100.00', 2) <= 0)->toBeTrue("reserved BDT {$sum} over a BDT 100 limit")->and(bccomp($sum, '90.00', 2))->toBe(0);
    } finally {
        $db->table('media_budget_ledger')->where('business_id', $businessId)->delete();
        $db->table('media_budgets')->where('business_id', $businessId)->delete();
        $db->table('businesses')->where('id', $businessId)->delete();
        DB::purge($name);
    }
});
