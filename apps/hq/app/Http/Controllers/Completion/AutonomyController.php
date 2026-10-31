<?php

namespace App\Http\Controllers\Completion;

use App\Ai\Pause\AiPause;
use App\Autonomy\Ladder\LadderGrants;
use App\Autonomy\Ladder\ToolEvidenceLadder;
use App\Http\Controllers\Controller;
use App\Models\AutonomyGrant;
use App\Models\AutonomyLadderThreshold;
use App\Models\Business;
use App\Support\Authorization\BusinessScope;
use App\Tools\ToolRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Controlled autonomy (docs/89 §3.16, docs/145-d): the evidence ladder per tool per business, Joy's
 * thresholds, the grants and their instant revocation. Reading writes nothing.
 *
 * Setting a threshold and granting need `hq.autonomy.grant` AND group-wide authority (route middleware and
 * the service both check); revoking needs only `hq.autonomy.revoke` and the business in scope. Pause All AI
 * overrides every grant shown here, and the screen says so.
 */
final class AutonomyController extends Controller
{
    use RefusesPolitely;

    public function __construct(
        private readonly ToolEvidenceLadder $ladder,
        private readonly LadderGrants $grants,
        private readonly BusinessScope $scope,
        private readonly ToolRegistry $tools,
        private readonly AiPause $pause,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $businesses = Business::query()->orderBy('name')->get(['id', 'name'])->filter(fn ($b) => $this->scope->allows($user, (int) $b->id))->values();

        $toolKeys = [];
        foreach ($this->tools->keys() as $key) {
            try {
                $d = $this->tools->get($key)->descriptor();
            } catch (Throwable) {
                continue;
            }
            if (! $d->permanentlyHumanOnly) {
                $toolKeys[$key] = $d->title;
            }
        }
        ksort($toolKeys);

        $rows = [];
        foreach ($businesses as $business) {
            foreach ($toolKeys as $key => $title) {
                $judged = $this->ladder->judge((int) $business->id, $key);
                $rows[] = [
                    'business_id' => $business->id, 'business' => $business->name, 'tool_key' => $key, 'tool' => $title,
                    'evidence' => $judged['evidence'], 'threshold' => $judged['threshold'], 'met' => $judged['met'], 'level' => $judged['level'], 'reasons' => $judged['reasons'],
                ];
            }
        }

        $restricted = $this->scope->isRestricted($user);
        $grants = AutonomyGrant::query()->when($restricted, fn ($q) => $q->whereIn('business_id', $this->scope->all($user) ?? []))
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")->orderByDesc('id')->limit(100)->get()
            ->map(fn (AutonomyGrant $g) => [
                'uuid' => $g->uuid, 'name' => $g->name, 'business_id' => $g->business_id, 'tool_key' => $g->tool_key, 'agent_key' => $g->agent_key,
                'level' => $g->max_level?->value, 'status' => $g->status, 'via_ladder' => $g->ladder_threshold_id !== null,
                'granted_at' => $g->granted_at?->toIso8601String(), 'revoked_at' => $g->revoked_at?->toIso8601String(), 'revoke_reason' => $g->revoke_reason,
            ]);

        $thresholds = AutonomyLadderThreshold::query()->when($restricted, fn ($q) => $q->whereIn('business_id', $this->scope->all($user) ?? []))
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")->orderByDesc('id')->limit(100)->get()
            ->map(fn (AutonomyLadderThreshold $t) => [
                'uuid' => $t->uuid, 'business_id' => $t->business_id, 'tool_key' => $t->tool_key, 'max_level' => $t->max_level,
                'min_approved_succeeded' => $t->min_approved_succeeded, 'max_error_permille' => $t->max_error_permille,
                'max_rejection_permille' => $t->max_rejection_permille, 'window_days' => $t->window_days, 'status' => $t->status, 'reason' => $t->reason,
            ]);

        return Inertia::render('autonomy/index', [
            'rows' => $rows,
            'grants' => $grants,
            'thresholds' => $thresholds,
            'paused' => $this->pause->isPaused(),
            'options' => ['levels' => LadderGrants::LEVELS, 'businesses' => $businesses->map(fn ($b) => ['id' => $b->id, 'name' => $b->name])->values(), 'tools' => array_keys($toolKeys)],
            'can' => [
                'grant' => $user->can('hq.autonomy.grant') && ! $restricted,
                'revoke' => $user->can('hq.autonomy.revoke'),
            ],
        ]);
    }

    public function storeThreshold(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'business_id' => ['required', 'integer'],
            'tool_key' => ['required', 'string', 'max:64'],
            'max_level' => ['required', Rule::in(LadderGrants::LEVELS)],
            'min_approved_succeeded' => ['required', 'integer', 'min:1', 'max:100000'],
            'max_error_permille' => ['required', 'integer', 'min:0', 'max:1000'],
            'max_rejection_permille' => ['required', 'integer', 'min:0', 'max:1000'],
            'window_days' => ['required', 'integer', 'min:7', 'max:365'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        $business = Business::query()->find((int) $data['business_id']);
        $this->inScope($business, $business?->id);

        return $this->attempt(fn () => $this->grants->setThreshold($request->user(), $business, $data), 'autonomy-threshold-set');
    }

    public function retireThreshold(Request $request, AutonomyLadderThreshold $threshold): RedirectResponse
    {
        $this->inScope($threshold, (int) $threshold->business_id);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        return $this->attempt(fn () => $this->grants->retireThreshold($request->user(), $threshold, $data['reason']), 'autonomy-threshold-retired');
    }

    public function grant(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'business_id' => ['required', 'integer'],
            'tool_key' => ['required', 'string', 'max:64'],
            'level' => ['required', Rule::in(LadderGrants::LEVELS)],
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        $business = Business::query()->find((int) $data['business_id']);
        $this->inScope($business, $business?->id);

        return $this->attempt(fn () => $this->grants->grant($request->user(), $business, $data['tool_key'], $data['level'], $data['reason']), 'autonomy-granted');
    }

    public function revoke(Request $request, AutonomyGrant $grant): RedirectResponse
    {
        if ($grant->business_id === null ? $this->scope->isRestricted($request->user()) : ! $this->scope->allows($request->user(), (int) $grant->business_id)) {
            abort(404);
        }
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        return $this->attempt(fn () => $this->grants->revoke($request->user(), $grant, $data['reason']), 'autonomy-revoked');
    }
}
