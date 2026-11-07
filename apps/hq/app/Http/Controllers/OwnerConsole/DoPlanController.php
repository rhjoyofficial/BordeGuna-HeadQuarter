<?php

namespace App\Http\Controllers\OwnerConsole;

use App\Gateway\Descriptor\RiskTier;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\DoPlan;
use App\OwnerConsole\Do\DoModeActivation;
use App\OwnerConsole\Do\DoPlanService;
use App\OwnerConsole\Refused;
use App\Support\Authorization\BusinessScope;
use App\Support\Http\ListPage;
use App\Tools\ToolRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Do" mode screens (docs/143 A). Reading writes nothing. The browser supplies a business, a title and
 * steps that name a tool key and its arguments for a PLAN; it never runs one: starting a plan only lets
 * `DoPlanService` put the first step to the gateway, and every effectful step then waits on its own
 * approval in the Approval Center. A plan of a business the person may not see does not exist for them (404).
 */
final class DoPlanController extends Controller
{
    public function __construct(private readonly DoPlanService $plans, private readonly BusinessScope $scope, private readonly ToolRegistry $tools, private readonly DoModeActivation $activation) {}

    public function index(Request $request): Response
    {
        $plans = $this->scope->restrict(DoPlan::query(), $request->user())->with(['business:id,name', 'startedBy:id,email', 'proposedBy:id,email'])
            ->latest('id');
        $page = ListPage::paginate($plans, $request);

        return Inertia::render('do-plans/index', [
            'enabled' => $this->activation->active(),
            'plans' => $page->getCollection()->map(fn (DoPlan $p) => $this->card($p))->all(),
            'pagination' => $page->meta(),
            'businesses' => $this->scope->restrict(Business::query(), $request->user(), 'id')->orderBy('name')->get(['id', 'name']),
            'tools' => $this->eligibleTools(),
            'max_steps' => DoPlanService::MAX_STEPS,
            'can' => ['propose' => $request->user()->can(DoPlanService::PERMISSION_PROPOSE), 'start' => $request->user()->can(DoPlanService::PERMISSION_START)],
        ]);
    }

    public function show(Request $request, DoPlan $plan): Response
    {
        $this->assertVisible($request, $plan);
        $plan->load(['business:id,name', 'startedBy:id,email', 'proposedBy:id,email', 'steps.approval:id,status,tool_key']);

        return Inertia::render('do-plans/show', [
            'enabled' => $this->activation->active(),
            'plan' => $this->card($plan) + [
                'halt_reason' => $plan->halt_reason,
                'steps' => $plan->steps->map(fn ($s) => [
                    'seq' => $s->seq, 'tool_key' => $s->tool_key, 'summary' => $s->summary, 'state' => $s->state, 'effectful' => $s->effectful,
                    'args' => $s->args, 'approval_id' => $s->approval_id, 'approval_status' => $s->approval?->status?->value,
                    'reason' => data_get($s->outcome, 'reason'),
                ])->all(),
            ],
            'can' => ['start' => $request->user()->can(DoPlanService::PERMISSION_START)],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'business_id' => ['required', 'integer', 'exists:businesses,id'],
            'title' => ['required', 'string', 'max:200'],
            'steps' => ['required', 'array', 'min:1', 'max:'.DoPlanService::MAX_STEPS],
            'steps.*.tool_key' => ['required', 'string', 'max:96'],
            'steps.*.args' => ['nullable', 'array'],
            'steps.*.summary' => ['required', 'string', 'max:300'],
        ]);

        try {
            $plan = $this->plans->propose((int) $data['business_id'], $data['title'], $data['steps'], $request->user());
        } catch (Refused $e) {
            throw ValidationException::withMessages(['plan' => $e->getMessage()]);
        }

        return to_route('do-plans.show', $plan->uuid)->with('status', 'do-plan-proposed');
    }

    public function start(Request $request, DoPlan $plan): RedirectResponse
    {
        $this->assertVisible($request, $plan);

        return $this->act(fn () => $this->plans->start($plan, $request->user()), 'do-plan-started');
    }

    public function advance(Request $request, DoPlan $plan): RedirectResponse
    {
        $this->assertVisible($request, $plan);

        // A named person continues the plan: the only way the next effectful step is put to the gateway (ruling 31 Oct item 1).
        return $this->act(fn () => $this->plans->advance($plan, $request->user()), 'do-plan-refreshed');
    }

    public function cancel(Request $request, DoPlan $plan): RedirectResponse
    {
        $this->assertVisible($request, $plan);
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:500']])['reason'] ?? null;

        return $this->act(fn () => $this->plans->cancel($plan, $request->user(), $reason), 'do-plan-cancelled');
    }

    private function act(callable $do, string $status): RedirectResponse
    {
        try {
            $do();
        } catch (Refused $e) {
            throw ValidationException::withMessages(['plan' => $e->getMessage()]);
        }

        return back()->with('status', $status);
    }

    /** A plan of another business is not there for this person: 404, never 403 (docs/136). */
    private function assertVisible(Request $request, DoPlan $plan): void
    {
        if (! $this->scope->restrict(DoPlan::query()->whereKey($plan->getKey()), $request->user())->exists()) {
            abort(404);
        }
    }

    /** @return array<string, mixed> */
    private function card(DoPlan $p): array
    {
        return [
            'uuid' => $p->uuid, 'title' => $p->title, 'state' => $p->state, 'business' => $p->business?->name, 'business_id' => $p->business_id,
            'proposer_kind' => $p->proposer_kind, 'agent_key' => $p->agent_key, 'proposed_by' => $p->proposedBy?->email, 'started_by' => $p->startedBy?->email,
            'steps_count' => $p->steps()->count(), 'created_at' => $p->created_at?->toIso8601String(), 'finished_at' => $p->finished_at?->toIso8601String(),
        ];
    }

    /** @return list<array{key: string, title: string, tier: string, effectful: bool}> */
    private function eligibleTools(): array
    {
        $out = [];
        foreach ($this->tools->keys() as $key) {
            $d = $this->tools->get($key)->descriptor();
            if (DoPlanService::eligible($d) === null) {
                $out[] = ['key' => $key, 'title' => $d->title, 'tier' => $d->riskTier->value, 'effectful' => $d->riskTier->atLeast(RiskTier::R4)];
            }
        }

        return $out;
    }
}
