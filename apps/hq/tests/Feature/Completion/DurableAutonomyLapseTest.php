<?php

use App\Autonomy\AutonomyRequest;
use App\Autonomy\AutonomyResolver;
use App\Autonomy\Ladder\DurableLapseWriter;
use App\Autonomy\Ladder\LadderGrants;
use App\Autonomy\Ladder\PendingLapses;
use App\Gateway\Descriptor\AutonomyLevel;
use App\Models\AuditLog;
use App\Models\AutonomyGrant;
use App\Models\AutonomyLadderThreshold;
use App\Models\Business;
use App\Support\Audit\Auditor;
use App\Tools\ToolRegistry;
use Database\Seeders\DomainSeeder;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\Completion\M4;

/*
 | docs/150, ChatGPT ruling 1 Nov 2026: a ladder autonomy grant becoming invalid is an immediate authorization
 | failure whether or not the caller's transaction later rolls back. The lapse is written on its own database
 | session (outside the caller's rollback boundary); if it cannot be written the grant stays refused and the
 | sweep reconciles it; nothing ever reactivates a lapsed grant.
 |
 | The first two tests need the grant COMMITTED (the dedicated session cannot see the test's own open
 | transaction), so they leave the per-test transaction and rebuild the database afterwards.
 */

const M13_TOOL = 'facts.query_orders';

beforeEach(function () {
    $this->seed(DomainSeeder::class);
    $this->owner = $this->hqUser('owner');
    $this->laladia = Business::where('slug', 'laladia')->sole();
    M4::pause(false);
    $this->m13Committed = false;
});

afterEach(function () {
    if ($this->m13Committed) {
        DB::purge(DurableLapseWriter::CONNECTION);
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        Artisan::call('migrate:fresh', ['--force' => true]);
    }
});

/** Leaves the per-test transaction so what follows is committed and visible to the dedicated session. */
function m13Commit(): void
{
    while (DB::transactionLevel() > 0) {
        DB::commit();
    }
    $manager = new DatabaseTransactionsManager;
    app()->instance('db.transactions', $manager);
    DB::connection()->setTransactionManager($manager);
    test()->m13Committed = true;
}

function m13Level(int $businessId): AutonomyLevel
{
    $descriptor = app(ToolRegistry::class)->get(M13_TOOL)->descriptor();

    return app(AutonomyResolver::class)->resolve(new AutonomyRequest(agentKey: 'executive', descriptor: $descriptor, businessId: $businessId))->level;
}

/** Threshold + evidence + a grant at A2 for laladia, through the real screens. */
function m13Granted(): AutonomyGrant
{
    $t = test();
    foreach (range(1, 3) as $i) {
        M4::proposal($t->laladia->id, M13_TOOL, 'consumed', 'succeeded');
    }
    $t->actingAsHq($t->owner)->post('/autonomy/thresholds', ['business_id' => $t->laladia->id, 'tool_key' => M13_TOOL, 'max_level' => 'a2', 'min_approved_succeeded' => 3,
        'max_error_permille' => 100, 'max_rejection_permille' => 250, 'window_days' => 30, 'reason' => 'numbers'])->assertSessionHasNoErrors();
    $t->actingAsHq($t->owner)->post('/autonomy/grants', ['business_id' => $t->laladia->id, 'tool_key' => M13_TOOL, 'level' => 'a2', 'reason' => 'earned'])->assertSessionHasNoErrors();
    expect(m13Level($t->laladia->id))->toBe(AutonomyLevel::A2);

    return AutonomyGrant::query()->latest('id')->firstOrFail();
}

function m13LapseAudits(AutonomyGrant $grant): int
{
    return AuditLog::query()->where('action', 'autonomy.grant.lapsed')->where('target_id', (string) $grant->id)->count();
}

/** Both write paths fail, as when the database refuses the write. */
function m13BrokenWriter(): void
{
    test()->mock(DurableLapseWriter::class, function ($mock) {
        $mock->shouldReceive('writeDurably')->andThrow(new RuntimeException('the lapse write failed'));
        $mock->shouldReceive('writeInCaller')->andThrow(new RuntimeException('the lapse write failed'));
    });
}

function m13RealWriter(): void
{
    app()->instance(DurableLapseWriter::class, new DurableLapseWriter(app(Auditor::class)));
}

it('keeps the lapse written, audited and the grant refused when the caller\'s transaction rolls back', function () {
    m13Commit();
    $grant = m13Granted();
    M4::proposal($this->laladia->id, M13_TOOL, 'consumed', 'failed');

    try {
        DB::transaction(function () {
            // The decision inside the caller's transaction refuses at once.
            expect(m13Level($this->laladia->id))->toBe(AutonomyLevel::A0);
            Business::query()->whereKey($this->laladia->id)->update(['name' => 'caller work that must roll back']);
            throw new RuntimeException('the caller fails after the decision');
        });
    } catch (RuntimeException) {
    }

    // The caller's own work is gone; the lapse is not.
    expect($this->laladia->fresh()->name)->not->toBe('caller work that must roll back');
    $fresh = $grant->fresh();
    expect($fresh->status)->toBe('lapsed')->and($fresh->lapse_code)->toBe('evidence_below_threshold')->and($fresh->lapsed_at)->not->toBeNull()
        ->and(m13LapseAudits($grant))->toBe(1)
        ->and(AuditLog::query()->where('action', 'autonomy.grant.lapsed')->sole()->after['durable'])->toBeTrue()
        ->and(app(PendingLapses::class)->all())->toBe([]);

    // Still refused, and the lapse is recorded once.
    expect(m13Level($this->laladia->id))->toBe(AutonomyLevel::A0)->and(m13LapseAudits($grant))->toBe(1);
});

it('keeps the lapse when the worker dies after the decision, before its transaction ends', function () {
    m13Commit();
    $grant = m13Granted();
    M4::pause(true);

    DB::beginTransaction();
    expect(m13Level($this->laladia->id))->toBe(AutonomyLevel::A0);
    // The crash: the session is dropped with its transaction open (the server rolls it back), and the next
    // process starts with no memory and an empty cache.
    DB::purge();
    app()->forgetInstance(PendingLapses::class);
    Cache::flush();

    $fresh = $grant->fresh();
    expect($fresh->status)->toBe('lapsed')->and($fresh->lapse_code)->toBe('ai_paused')->and(m13LapseAudits($grant))->toBe(1);

    // Turning AI back on restores nothing.
    M4::pause(false);
    expect(m13Level($this->laladia->id))->toBe(AutonomyLevel::A0)->and($grant->fresh()->status)->toBe('lapsed');
});

it('refuses when the lapse cannot be written, keeps refusing after the evidence recovers, and the sweep then writes it', function () {
    $grant = m13Granted();
    m13BrokenWriter();
    M4::proposal($this->laladia->id, M13_TOOL, 'consumed', 'failed');

    expect(m13Level($this->laladia->id))->toBe(AutonomyLevel::A0)
        ->and($grant->fresh()->status)->toBe('active')
        ->and(app(PendingLapses::class)->get($grant->id)['code'])->toBe('evidence_below_threshold');

    // The evidence recovers: the conditions alone would hold again, but a decided lapse stays decided.
    foreach (range(1, 40) as $i) {
        M4::proposal($this->laladia->id, M13_TOOL, 'consumed', 'succeeded');
    }
    expect(m13Level($this->laladia->id))->toBe(AutonomyLevel::A0)->and($grant->fresh()->status)->toBe('active');

    // The sweep is recovery: once the write works it writes the decided lapse, with the original reason.
    m13RealWriter();
    $this->artisan('hq:autonomy:lapse-sweep')->expectsOutput('lapsed 1')->assertSuccessful();
    $fresh = $grant->fresh();
    expect($fresh->status)->toBe('lapsed')->and($fresh->lapse_code)->toBe('evidence_below_threshold')->and(m13LapseAudits($grant))->toBe(1)
        ->and(app(PendingLapses::class)->all())->toBe([])
        ->and(m13Level($this->laladia->id))->toBe(AutonomyLevel::A0);
    $this->artisan('hq:autonomy:lapse-sweep')->expectsOutput('lapsed 0')->assertSuccessful();
});

it('refuses when the pause state is unreadable even if the lapse cannot be written, and a readable state restores nothing', function () {
    $grant = m13Granted();
    m13BrokenWriter();
    DB::table('ai_pause_state')->delete();
    expect(m13Level($this->laladia->id))->toBe(AutonomyLevel::A0)->and($grant->fresh()->status)->toBe('active');

    M4::pause(false);
    expect(m13Level($this->laladia->id))->toBe(AutonomyLevel::A0);

    m13RealWriter();
    expect(m13Level($this->laladia->id))->toBe(AutonomyLevel::A0);
    $fresh = $grant->fresh();
    expect($fresh->status)->toBe('lapsed')->and($fresh->lapse_code)->toBe('pause_unreadable')->and(m13LapseAudits($grant))->toBe(1);
});

it('lapses when the threshold is changed, while a retirement that rolls back leaves the grant standing', function () {
    $grant = m13Granted();
    $threshold = AutonomyLadderThreshold::query()->sole();

    // A retirement inside a transaction that rolls back never happened: threshold and grant both stand.
    try {
        DB::transaction(function () use ($threshold) {
            app(LadderGrants::class)->retireThreshold($this->owner, $threshold, 'second thoughts');
            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
    }
    expect($threshold->fresh()->status)->toBe('active')->and($grant->fresh()->status)->toBe('active')
        ->and(m13Level($this->laladia->id))->toBe(AutonomyLevel::A2);

    // Changing the numbers retires the old threshold: the grant lapses and the new threshold restores nothing.
    $this->actingAsHq($this->owner)->post('/autonomy/thresholds', ['business_id' => $this->laladia->id, 'tool_key' => M13_TOOL, 'max_level' => 'a3', 'min_approved_succeeded' => 3,
        'max_error_permille' => 100, 'max_rejection_permille' => 250, 'window_days' => 30, 'reason' => 'new numbers'])->assertSessionHasNoErrors();
    $fresh = $grant->fresh();
    expect($fresh->status)->toBe('lapsed')->and($fresh->lapse_code)->toBe('threshold_retired')->and(m13LapseAudits($grant))->toBe(1)
        ->and(m13Level($this->laladia->id))->toBe(AutonomyLevel::A0);
});

it('refuses an expired grant at once and the sweep records it as expired, never to return', function () {
    $grant = m13Granted();
    DB::table('autonomy_grants')->where('id', $grant->id)->update(['expires_at' => now()->subMinute()]);
    expect(m13Level($this->laladia->id))->toBe(AutonomyLevel::A0);

    $this->artisan('hq:autonomy:lapse-sweep')->expectsOutput('lapsed 1')->assertSuccessful();
    $fresh = $grant->fresh();
    expect($fresh->status)->toBe('lapsed')->and($fresh->lapse_code)->toBe('grant_expired');

    // Extending the expiry cannot bring it back: the database refuses, and the resolver stays at A0.
    DB::table('autonomy_grants')->where('id', $grant->id)->update(['expires_at' => now()->addDay()]);
    expect(m13Level($this->laladia->id))->toBe(AutonomyLevel::A0)
        ->and(fn () => DB::transaction(fn () => DB::table('autonomy_grants')->where('id', $grant->id)->update(['status' => 'active'])))->toThrow(QueryException::class);
});
