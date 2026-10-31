<?php

use App\Autonomy\AutonomyRequest;
use App\Autonomy\AutonomyResolver;
use App\Autonomy\Ladder\ToolEvidenceLadder;
use App\Gateway\Descriptor\AutonomyLevel;
use App\Models\AutonomyGrant;
use App\Models\AutonomyLadderThreshold;
use App\Models\Business;
use App\Tools\ToolRegistry;
use Database\Seeders\DomainSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Completion\M4;

/*
 | docs/145-d (Phase 10, ADR-048): the evidence ladder per tool per business. No threshold ships, so nothing
 | is grantable by default; the numbers are data Joy sets (group-wide authority only); the grant is a person's
 | act and only when the evidence meets the numbers NOW; revoking is instant; retiring a threshold revokes its
 | grants; evidence that falls away, or Pause All AI, makes a ladder grant absent at the very next decision.
 */

const AL_TOOL = 'facts.query_orders';

beforeEach(function () {
    $this->seed(DomainSeeder::class);
    $this->owner = $this->hqUser('owner');
    $this->laladia = Business::where('slug', 'laladia')->sole();
    $this->bionic = Business::where('slug', 'bionic')->sole();
});

function alThreshold(array $over = []): array
{
    return $over + ['business_id' => test()->laladia->id, 'tool_key' => AL_TOOL, 'max_level' => 'a2', 'min_approved_succeeded' => 3,
        'max_error_permille' => 100, 'max_rejection_permille' => 250, 'window_days' => 30, 'reason' => 'my numbers'];
}

/** Three approved, run and succeeded; one rejected: 250 per 1,000 refusals, 0 errors. */
function alEvidence(Business $business): void
{
    foreach (range(1, 3) as $i) {
        M4::proposal($business->id, AL_TOOL, 'consumed', 'succeeded');
    }
    M4::proposal($business->id, AL_TOOL, 'rejected');
}

function alLevel(int $businessId): AutonomyLevel
{
    $descriptor = app(ToolRegistry::class)->get(AL_TOOL)->descriptor();

    return app(AutonomyResolver::class)->resolve(new AutonomyRequest(agentKey: 'executive', descriptor: $descriptor, businessId: $businessId))->level;
}

it('registers the ladder behind auth, 2FA, its permissions and group-wide authority for thresholds and grants', function () {
    foreach (['autonomy.thresholds.store', 'autonomy.thresholds.retire', 'autonomy.grants.store'] as $name) {
        expect(Route::getRoutes()->getByName($name)->gatherMiddleware())->toContain('auth', '2fa.enabled', '2fa.verified', 'permission:hq.autonomy.grant', 'group.wide:hq.autonomy.grant');
    }
    expect(Route::getRoutes()->getByName('autonomy.grants.revoke')->gatherMiddleware())->toContain('permission:hq.autonomy.revoke')->not->toContain('group.wide:hq.autonomy.grant');
});

it('ships with no threshold: the evidence is shown, never met, and no grant can be made', function () {
    alEvidence($this->laladia);
    $judged = app(ToolEvidenceLadder::class)->judge($this->laladia->id, AL_TOOL);
    expect($judged['met'])->toBeFalse()->and($judged['evidence']['succeeded'])->toBe(3)->and($judged['evidence']['rejection_permille'])->toBe(250)
        ->and(AutonomyLadderThreshold::query()->count())->toBe(0)->and(AutonomyGrant::query()->count())->toBe(0);

    $this->actingAsHq($this->owner)->post('/autonomy/grants', ['business_id' => $this->laladia->id, 'tool_key' => AL_TOOL, 'level' => 'a1', 'reason' => 'try'])->assertSessionHasErrors('form');
    expect(AutonomyGrant::query()->count())->toBe(0);

    $this->actingAsHq($this->owner)->get('/autonomy')->assertOk()->assertInertia(fn (Assert $p) => $p->component('autonomy/index')
        ->where('rows', fn ($rows) => collect($rows)->contains(fn ($r) => $r['tool_key'] === AL_TOOL && $r['business_id'] === $this->laladia->id && $r['evidence']['succeeded'] === 3 && $r['met'] === false)));
});

it('lets only a group-wide person set numbers and grant; a person limited to one business may still revoke there', function () {
    $limited = M4::limit($this->hqUser('operator'), 'laladia');
    $this->actingAsHq($limited)->post('/autonomy/thresholds', alThreshold())->assertForbidden();
    $this->actingAsHq($this->hqUser('brand_manager'))->post('/autonomy/thresholds', alThreshold())->assertForbidden();

    alEvidence($this->laladia);
    $this->actingAsHq($this->owner)->post('/autonomy/thresholds', alThreshold())->assertSessionHasNoErrors();
    $this->actingAsHq($this->owner)->post('/autonomy/grants', ['business_id' => $this->laladia->id, 'tool_key' => AL_TOOL, 'level' => 'a2', 'reason' => 'earned'])->assertSessionHasNoErrors();
    $grant = AutonomyGrant::query()->sole();
    expect($grant->ladder_threshold_id)->not->toBeNull()->and(alLevel($this->laladia->id))->toBe(AutonomyLevel::A2)
        // The grant is about laladia only.
        ->and(alLevel($this->bionic->id))->toBe(AutonomyLevel::A0);

    $this->actingAsHq($limited)->post("/autonomy/grants/{$grant->uuid}/revoke", ['reason' => 'not comfortable'])->assertSessionHasNoErrors();
    expect($grant->fresh()->status)->toBe('revoked')->and($grant->fresh()->revoked_by)->toBe($limited->id)
        ->and(alLevel($this->laladia->id))->toBe(AutonomyLevel::A0);
});

it('refuses a grant above the threshold\'s level, for a human-only tool, or while AI is paused; and Pause All AI overrides a grant at once', function () {
    alEvidence($this->laladia);
    $this->actingAsHq($this->owner)->post('/autonomy/thresholds', alThreshold())->assertSessionHasNoErrors();
    $this->actingAsHq($this->owner)->post('/autonomy/grants', ['business_id' => $this->laladia->id, 'tool_key' => AL_TOOL, 'level' => 'a3', 'reason' => 'more'])->assertSessionHasErrors('form');
    $this->actingAsHq($this->owner)->post('/autonomy/thresholds', alThreshold(['tool_key' => 'customers.identity_conflicts.resolve']))->assertSessionHasErrors('form');

    M4::pause();
    $this->actingAsHq($this->owner)->post('/autonomy/grants', ['business_id' => $this->laladia->id, 'tool_key' => AL_TOOL, 'level' => 'a1', 'reason' => 'x'])->assertSessionHasErrors('form');
    M4::pause(false);
    $this->actingAsHq($this->owner)->post('/autonomy/grants', ['business_id' => $this->laladia->id, 'tool_key' => AL_TOOL, 'level' => 'a1', 'reason' => 'x'])->assertSessionHasNoErrors();
    expect(alLevel($this->laladia->id))->toBe(AutonomyLevel::A1);

    M4::pause();
    expect(alLevel($this->laladia->id))->toBe(AutonomyLevel::A0);
    DB::table('ai_pause_state')->delete(); // an unreadable state is a pause too
    expect(alLevel($this->laladia->id))->toBe(AutonomyLevel::A0);
});

it('makes a ladder grant absent the moment the evidence stops meeting the numbers, and revokes it when the threshold is retired', function () {
    alEvidence($this->laladia);
    $this->actingAsHq($this->owner)->post('/autonomy/thresholds', alThreshold())->assertSessionHasNoErrors();
    $this->actingAsHq($this->owner)->post('/autonomy/grants', ['business_id' => $this->laladia->id, 'tool_key' => AL_TOOL, 'level' => 'a2', 'reason' => 'earned'])->assertSessionHasNoErrors();
    expect(alLevel($this->laladia->id))->toBe(AutonomyLevel::A2);

    // One failed run: 1 error in 4 executed is 250 per 1,000, above the 100 allowed.
    M4::proposal($this->laladia->id, AL_TOOL, 'consumed', 'failed');
    expect(alLevel($this->laladia->id))->toBe(AutonomyLevel::A0);

    foreach (range(1, 20) as $i) {
        M4::proposal($this->laladia->id, AL_TOOL, 'consumed', 'succeeded');
    }
    expect(alLevel($this->laladia->id))->toBe(AutonomyLevel::A2);

    $threshold = AutonomyLadderThreshold::query()->sole();
    expect(fn () => DB::transaction(fn () => AutonomyLadderThreshold::query()->whereKey($threshold->id)->update(['min_approved_succeeded' => 1])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => AutonomyLadderThreshold::query()->whereKey($threshold->id)->delete()))->toThrow(QueryException::class);

    $this->actingAsHq($this->owner)->post("/autonomy/thresholds/{$threshold->uuid}/retire", ['reason' => 'rethink'])->assertSessionHasNoErrors();
    expect(AutonomyGrant::query()->sole()->status)->toBe('revoked')->and(alLevel($this->laladia->id))->toBe(AutonomyLevel::A0);
});

it('counts only agent proposals of this tool in this business inside the window; a person\'s own requests are not evidence', function () {
    alEvidence($this->laladia);
    alEvidence($this->bionic);
    // A request a person made directly has no agent_key: not a proposal, not evidence.
    M4::proposal($this->laladia->id, AL_TOOL, 'consumed', 'succeeded')->forceFill(['agent_key' => null])->save();
    // Outside the window.
    M4::proposal($this->laladia->id, AL_TOOL, 'consumed', 'succeeded')->forceFill(['created_at' => now()->subDays(200)])->save();

    $e = app(ToolEvidenceLadder::class)->evidence($this->laladia->id, AL_TOOL);
    expect($e['proposed'])->toBe(4)->and($e['succeeded'])->toBe(3)->and($e['rejected'])->toBe(1)->and($e['error_permille'])->toBe(0);
});
