<?php

use App\Ai\Pause\AiPauseSwitch;
use App\Autonomy\AutonomyRequest;
use App\Autonomy\AutonomyResolver;
use App\Autonomy\Ladder\LadderGrantLapse;
use App\Gateway\Descriptor\AutonomyLevel;
use App\Models\AuditLog;
use App\Models\AutonomyGrant;
use App\Models\AutonomyLadderThreshold;
use App\Models\Business;
use App\Tools\ToolRegistry;
use Database\Seeders\DomainSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Completion\M4;

/*
 | ChatGPT ruling 31 Oct 2026, item 17: an autonomy grant made through the evidence ladder LAPSES, one-way
 | and fail-closed, when the evidence falls below its threshold, AI is paused, the pause state cannot be read,
 | the threshold is retired, or another governing safety condition fails. Restoring the condition never brings
 | it back: only a new, explicit grant by a person after the conditions are verified again.
 */

const R3_TOOL = 'facts.query_orders';

beforeEach(function () {
    $this->seed(DomainSeeder::class);
    $this->owner = $this->hqUser('owner');
    $this->laladia = Business::where('slug', 'laladia')->sole();
    M4::pause(false);
});

function r3Evidence(int $businessId, int $succeeded = 3): void
{
    foreach (range(1, $succeeded) as $i) {
        M4::proposal($businessId, R3_TOOL, 'consumed', 'succeeded');
    }
}

function r3Level(int $businessId): AutonomyLevel
{
    $descriptor = app(ToolRegistry::class)->get(R3_TOOL)->descriptor();

    return app(AutonomyResolver::class)->resolve(new AutonomyRequest(agentKey: 'executive', descriptor: $descriptor, businessId: $businessId))->level;
}

/** Threshold + evidence + a grant at A2 for laladia, through the real screens. */
function r3Granted(): AutonomyGrant
{
    $t = test();
    r3Evidence($t->laladia->id);
    $t->actingAsHq($t->owner)->post('/autonomy/thresholds', ['business_id' => $t->laladia->id, 'tool_key' => R3_TOOL, 'max_level' => 'a2', 'min_approved_succeeded' => 3,
        'max_error_permille' => 100, 'max_rejection_permille' => 250, 'window_days' => 30, 'reason' => 'numbers'])->assertSessionHasNoErrors();
    $t->actingAsHq($t->owner)->post('/autonomy/grants', ['business_id' => $t->laladia->id, 'tool_key' => R3_TOOL, 'level' => 'a2', 'reason' => 'earned'])->assertSessionHasNoErrors();
    expect(r3Level($t->laladia->id))->toBe(AutonomyLevel::A2);

    return AutonomyGrant::query()->latest('id')->firstOrFail();
}

function r3ExpectLapsed(AutonomyGrant $grant, string $code): void
{
    $fresh = $grant->fresh();
    expect($fresh->status)->toBe('lapsed')->and($fresh->lapse_code)->toBe($code)->and($fresh->lapsed_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'autonomy.grant.lapsed')->where('target_id', (string) $grant->id)->count())->toBe(1);
}

it('lapses on evidence falling below the threshold, and the grant stays lapsed when the evidence recovers', function () {
    $grant = r3Granted();
    M4::proposal($this->laladia->id, R3_TOOL, 'consumed', 'failed');
    expect(r3Level($this->laladia->id))->toBe(AutonomyLevel::A0);
    r3ExpectLapsed($grant, 'evidence_below_threshold');
    expect(AuditLog::query()->where('action', 'autonomy.grant.lapsed')->sole()->actor_type)->toBe('system');

    r3Evidence($this->laladia->id, 30);
    expect(r3Level($this->laladia->id))->toBe(AutonomyLevel::A0)->and($grant->fresh()->status)->toBe('lapsed')
        ->and(AutonomyGrant::query()->where('status', 'active')->count())->toBe(0);

    // Only a new, explicit grant by a person, which re-verifies every condition, gives autonomy again.
    $this->actingAsHq($this->owner)->post('/autonomy/grants', ['business_id' => $this->laladia->id, 'tool_key' => R3_TOOL, 'level' => 'a1', 'reason' => 'verified again'])->assertSessionHasNoErrors();
    expect(r3Level($this->laladia->id))->toBe(AutonomyLevel::A1)->and(AutonomyGrant::query()->count())->toBe(2)->and($grant->fresh()->status)->toBe('lapsed');
});

it('lapses every ladder grant the moment AI is paused, and turning AI back on restores none', function () {
    $grant = r3Granted();
    app(AiPauseSwitch::class)->pause($this->owner, 'incident');
    // Lapsed by the pause itself, before any decision is asked for.
    r3ExpectLapsed($grant, 'ai_paused');

    app(AiPauseSwitch::class)->resume($this->owner, 'fixed');
    expect(r3Level($this->laladia->id))->toBe(AutonomyLevel::A0)->and($grant->fresh()->status)->toBe('lapsed');
});

it('lapses when the pause state cannot be read, and a readable state again restores nothing', function () {
    $grant = r3Granted();
    DB::table('ai_pause_state')->delete();
    expect(r3Level($this->laladia->id))->toBe(AutonomyLevel::A0);
    r3ExpectLapsed($grant, 'pause_unreadable');

    M4::pause(false);
    expect(r3Level($this->laladia->id))->toBe(AutonomyLevel::A0)->and($grant->fresh()->status)->toBe('lapsed');
});

it('lapses when the threshold is retired, and a new threshold restores nothing', function () {
    $grant = r3Granted();
    $threshold = AutonomyLadderThreshold::query()->sole();
    $this->actingAsHq($this->owner)->post("/autonomy/thresholds/{$threshold->uuid}/retire", ['reason' => 'rethink'])->assertSessionHasNoErrors();
    r3ExpectLapsed($grant, 'threshold_retired');

    $this->actingAsHq($this->owner)->post('/autonomy/thresholds', ['business_id' => $this->laladia->id, 'tool_key' => R3_TOOL, 'max_level' => 'a2', 'min_approved_succeeded' => 3,
        'max_error_permille' => 100, 'max_rejection_permille' => 250, 'window_days' => 30, 'reason' => 'again'])->assertSessionHasNoErrors();
    expect(r3Level($this->laladia->id))->toBe(AutonomyLevel::A0)->and($grant->fresh()->status)->toBe('lapsed');
});

it('lapses when another governing safety condition is invalid, and the sweep lapses without any decision being asked', function () {
    $grant = r3Granted();
    // Its level above what the threshold allows (as if the row were tampered with).
    DB::table('autonomy_grants')->where('id', $grant->id)->update(['max_level' => 'a3']);
    expect(r3Level($this->laladia->id))->toBe(AutonomyLevel::A0);
    r3ExpectLapsed($grant, 'safety_condition_invalid');

    // A second business's grant, never asked about, lapses through the sweep.
    $bionic = Business::where('slug', 'bionic')->sole();
    r3Evidence($bionic->id);
    $this->actingAsHq($this->owner)->post('/autonomy/thresholds', ['business_id' => $bionic->id, 'tool_key' => R3_TOOL, 'max_level' => 'a2', 'min_approved_succeeded' => 3,
        'max_error_permille' => 100, 'max_rejection_permille' => 250, 'window_days' => 30, 'reason' => 'numbers'])->assertSessionHasNoErrors();
    $this->actingAsHq($this->owner)->post('/autonomy/grants', ['business_id' => $bionic->id, 'tool_key' => R3_TOOL, 'level' => 'a1', 'reason' => 'earned'])->assertSessionHasNoErrors();
    $other = AutonomyGrant::query()->latest('id')->firstOrFail();
    M4::proposal($bionic->id, R3_TOOL, 'consumed', 'failed');
    $this->artisan('hq:autonomy:lapse-sweep')->expectsOutput('lapsed 1')->assertSuccessful();
    r3ExpectLapsed($other, 'evidence_below_threshold');
    $this->artisan('hq:autonomy:lapse-sweep')->expectsOutput('lapsed 0')->assertSuccessful();
});

it('makes the lapse one-way in the database, and leaves grants not made through the ladder alone', function () {
    $grant = r3Granted();
    app(LadderGrantLapse::class)->lapse($grant, 'ai_paused', 'test');
    expect(fn () => DB::transaction(fn () => DB::table('autonomy_grants')->where('id', $grant->id)->update(['status' => 'active'])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('autonomy_grants')->where('id', $grant->id)->update(['lapse_code' => 'evidence_below_threshold'])))->toThrow(QueryException::class);
    // A revoked ladder grant cannot be revived either.
    $this->actingAsHq($this->owner)->post('/autonomy/grants', ['business_id' => $this->laladia->id, 'tool_key' => R3_TOOL, 'level' => 'a1', 'reason' => 'again'])->assertSessionHasNoErrors();
    $second = AutonomyGrant::query()->latest('id')->firstOrFail();
    $this->actingAsHq($this->owner)->post("/autonomy/grants/{$second->uuid}/revoke", ['reason' => 'stop'])->assertSessionHasNoErrors();
    expect(fn () => DB::transaction(fn () => DB::table('autonomy_grants')->where('id', $second->id)->update(['status' => 'active'])))->toThrow(QueryException::class);

    // A grant not made through the ladder is not this rule's: the pause and the sweep leave it as it is.
    $plain = AutonomyGrant::query()->create(['name' => 'plain', 'reason' => 'r', 'tool_key' => R3_TOOL, 'max_level' => 'a1', 'status' => 'active', 'granted_at' => now()]);
    app(AiPauseSwitch::class)->pause($this->owner, 'incident');
    $this->artisan('hq:autonomy:lapse-sweep')->assertSuccessful();
    expect($plain->fresh()->status)->toBe('active');
});
