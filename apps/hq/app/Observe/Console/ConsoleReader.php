<?php

namespace App\Observe\Console;

use App\Ai\Budget\AiBudget;
use App\Ask\AskHq;
use App\Briefing\BriefingRequest;
use App\Briefing\EvidenceItem;
use App\Briefing\ExecutiveBriefingContract;
use App\Briefing\Sections\SourceReader;
use App\Briefing\Sections\YesterdayOrders;
use App\Briefing\SourceFreshness;
use App\Models\Agent;
use App\Models\Alert;
use App\Models\Approval;
use App\Models\Briefing;
use App\Models\Business;
use App\Models\JobRun;
use App\Models\ScheduledJob;
use App\Models\Store;
use App\Models\User;
use App\Observe\Dashboards\ControlPlaneDashboard;
use App\Observe\Dashboards\DashboardWindow;
use App\Scheduling\Commands\SchedulerTick;
use App\Support\Authorization\BusinessScope;
use App\Support\Features\Features;
use App\Tools\ToolRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * The console on `/dashboard` (docs/135 (c) and W-UI2): the morning brief, the decision cards, the
 * live panel and the composer, assembled from readers HQ already has.
 *
 * ⛔ Read-only, and it adds no capability. It composes no briefing, calls no tool, asks no model,
 * approves nothing and writes no row. Every block is behind the permission of the screen it
 * summarises and inside the person's business restriction exactly as that screen reads it; a block
 * the person may not see is `null`, never an empty list that looks like "nothing there".
 *
 * ⛔ Unavailable is not zero. A store HQ holds no facts for gives no figure; a total over some stores
 * is withheld (the briefing's own `YesterdayOrders` rule); a scheduler that has not ticked says so.
 */
final class ConsoleReader
{
    /** How many decision cards the console shows; the rest are a count and a link to the queue. */
    public const MAX_CARDS = 6;

    public const MAX_ALERTS = 5;

    public const MAX_RECEIPTS = 5;

    public function __construct(
        private readonly BusinessScope $scope,
        private readonly Features $features,
        private readonly AskHq $ask,
        private readonly AiBudget $budget,
        private readonly SourceReader $sources,
        private readonly YesterdayOrders $yesterday,
        private readonly ControlPlaneDashboard $controlPlane,
        private readonly ToolRegistry $registry,
    ) {}

    public function build(User $user): array
    {
        $now = CarbonImmutable::now();

        return [
            'generated_at' => $now->toIso8601String(),
            'timezone' => (string) config('hq.timezone', 'Asia/Dhaka'),
            'restricted' => $this->scope->isRestricted($user),
            'brief' => $this->brief($user, $now),
            'decisions' => $user->can('hq.approvals.view') ? $this->decisions($user) : null,
            'receipts' => $user->can('hq.approvals.view') ? $this->receipts($user, $now) : null,
            'live' => [
                'scheduler' => $this->scheduler($user, $now),
                'alerts' => $user->can('hq.audit.view') ? $this->alerts() : null,
                'cost' => $user->can('hq.ai.cost.view') ? $this->cost() : null,
                'guard' => $user->can('hq.tools.view') ? $this->guard($user) : null,
                'posture' => $this->posture(),
            ],
            'composer' => $this->composer($user),
        ];
    }

    /**
     * The brief: the newest stored briefing this person may open, and yesterday's order figures with
     * the sources they rest on, read by the briefing's own deterministic readers. Both behind
     * `hq.tools.facts.read`, the permission of the briefings screen and of `briefing.compose`.
     */
    private function brief(User $user, CarbonImmutable $now): ?array
    {
        if (! $user->can('hq.tools.facts.read')) {
            return null;
        }

        $permitted = $this->scope->all($user);
        $timezone = (string) config('hq.timezone', 'Asia/Dhaka');
        $request = new BriefingRequest(
            date: $now->setTimezone($timezone)->startOfDay(),
            timezone: $timezone,
            now: $now,
            permittedBusinessIds: $permitted,
        );

        $sources = $this->sources->read($request);
        $section = $this->yesterday->collect($request, $sources);

        // Only the business and group totals: a per-store figure is one click away on the briefing.
        $figures = [];
        foreach ($section->items as $item) {
            /** @var EvidenceItem $item */
            if ($item->scope->store !== null) {
                continue;
            }
            $figures[] = [
                'id' => $item->id,
                'figure' => explode('.', $item->id)[1] ?? $item->id,
                'business' => $item->scope->business,
                'business_name' => $item->scope->business === null ? null : (Business::query()->where('slug', $item->scope->business)->value('name') ?? $item->scope->business),
                'value' => $item->value,
                'unit' => $item->unit,
                'as_of' => $item->asOf?->utc()->toIso8601String(),
                'freshness' => $item->freshness->toArray(),
            ];
        }

        $stored = $this->visibleBriefings(Briefing::query()->with('business:id,name'), $permitted)->latest('id')->first();

        return [
            'date' => $request->yesterday()['date'],
            'group_wide' => $request->isGroupWide(),
            'figures' => $figures,
            'notes' => $section->section->notes,
            'empty_reason' => $figures === [] ? $section->section->reason : null,
            'sources' => array_map(fn (SourceFreshness $s) => $this->source($s), $sources),
            'not_connected' => collect(ExecutiveBriefingContract::NOT_CONNECTED)
                ->map(fn (string $reason, string $key) => ['key' => $key, 'reason' => $reason])->values()->all(),
            'stored' => $stored === null ? null : [
                'uuid' => $stored->uuid,
                'briefing_date' => $stored->briefing_date->toDateString(),
                'business' => $stored->business?->name,
                'state' => $stored->state,
                'narration_state' => $stored->narration_state,
                'narrative' => $stored->narrative,
                'degraded_reasons' => $stored->degraded_reasons,
                'generated_at' => $stored->generated_at->toIso8601String(),
            ],
        ];
    }

    private function source(SourceFreshness $source): array
    {
        $store = $source->scope->store;
        $business = $source->scope->business;

        return [
            'key' => $source->key,
            'kind' => match ($source->readModel) {
                'facts_orders' => 'orders',
                'facts_erp_snapshots' => 'erp_stock',
                default => 'integrity',
            },
            'business' => $business,
            'store' => $store,
            'label' => $store !== null
                ? (Store::query()->where('slug', $store)->value('name') ?? $store)
                : ($business !== null ? (Business::query()->where('slug', $business)->value('name') ?? $business) : null),
            'state' => $source->freshness->state->value,
            'age_minutes' => $source->freshness->ageMinutes,
            'budget_minutes' => $source->freshness->budgetMinutes,
            'in_future' => $source->freshness->inFuture,
            'as_of' => $source->asOf?->utc()->toIso8601String(),
            'note' => $source->note,
        ];
    }

    /**
     * The briefings screen's own visibility rule (`BriefingController::visible`): a restricted person
     * sees a briefing composed for one of their businesses, or one composed under a restriction that
     * sits inside their own; a group-wide briefing is for people who see the group.
     *
     * @param  list<int>|null  $permitted
     */
    private function visibleBriefings(Builder $query, ?array $permitted): Builder
    {
        if ($permitted === null) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($permitted) {
            $q->whereIn('business_id', $permitted)
                ->orWhere(fn (Builder $r) => $r->whereNotNull('scope_business_ids')
                    ->whereRaw('scope_business_ids <@ ?::jsonb', [json_encode(array_values($permitted))]));
        });
    }

    /**
     * Pending approvals as cards, scoped exactly as the dashboard has always counted them. A card
     * carries what the approvals list shows about the row and links to it; it never carries a way to
     * decide (docs/135 (f) decision 5): the payload a person approves is read on its own page.
     */
    private function decisions(User $user): array
    {
        $pending = $this->scope->restrict(Approval::pending(), $user);
        $total = (clone $pending)->count();
        $overdue = $this->scope->restrict(Approval::pastSla(), $user)->count();

        $cards = (clone $pending)->with(['resultingToolCall:id,status,error'])
            ->orderByRaw('sla_due_at nulls last')->orderBy('id')
            ->limit(self::MAX_CARDS)->get()
            ->map(fn (Approval $a) => [
                'id' => $a->id,
                'tool_key' => $a->tool_key,
                'tool_title' => $this->toolTitle($a->tool_key),
                'risk_tier' => $a->risk_tier?->value,
                'risk_label' => $a->risk_tier?->label(),
                'business' => $a->business_id === null ? null : Business::query()->whereKey($a->business_id)->value('name'),
                'store' => $a->store_id === null ? null : Store::query()->whereKey($a->store_id)->value('name'),
                'agent_key' => $a->agent_key,
                'requester_kind' => $a->requester_kind,
                'reason' => $a->reason,
                'created_at' => $a->created_at?->toIso8601String(),
                'sla_due_at' => $a->sla_due_at?->toIso8601String(),
                'expires_at' => $a->expires_at?->toIso8601String(),
                'decidable' => $a->isDecidableBy($user),
                'href' => "/approvals/{$a->id}",
            ])->values()->all();

        return ['total' => $total, 'overdue' => $overdue, 'cards' => $cards];
    }

    /** Approvals decided in the last day, with the outcome the approvals screen states for them. */
    private function receipts(User $user, CarbonImmutable $now): array
    {
        return $this->scope->restrict(Approval::query(), $user)
            ->whereNotNull('decided_at')->where('decided_at', '>=', $now->subDay())
            ->with('resultingToolCall:id,status,error')
            ->latest('decided_at')->limit(self::MAX_RECEIPTS)->get()
            ->map(fn (Approval $a) => [
                'id' => $a->id,
                'tool_key' => $a->tool_key,
                'tool_title' => $this->toolTitle($a->tool_key),
                'status' => $a->status->value,
                'execution_status' => $a->resultingToolCall?->status,
                'decided_at' => $a->decided_at?->toIso8601String(),
                'href' => "/approvals/{$a->id}",
            ])->values()->all();
    }

    private function toolTitle(string $key): ?string
    {
        return $this->registry->has($key) ? $this->registry->get($key)->descriptor()->title : null;
    }

    /**
     * The scheduler's heartbeat, read the way the platform screen reads it, and the jobs for people
     * who may open the scheduler. No heartbeat means the scheduler is not running (containment stops
     * it on purpose), which pauses alerts, the event drain, sweeps and Telegram alerting.
     */
    private function scheduler(User $user, CarbonImmutable $now): array
    {
        $last = Cache::get(SchedulerTick::HEARTBEAT_KEY);
        $threshold = (int) config('hq.scheduler.heartbeat_stale_after_seconds', 180);
        $age = $last === null ? null : (int) CarbonImmutable::parse($last)->diffInSeconds($now, false);

        $jobs = null;
        if ($user->can('hq.jobs.view')) {
            // A restricted person sees the jobs of their businesses and the group-level ones.
            $scoped = function () use ($user): Builder {
                $permitted = $this->scope->all($user);

                return ScheduledJob::query()->when($permitted !== null,
                    fn (Builder $q) => $q->where(fn (Builder $w) => $w->whereIn('business_id', $permitted)->orWhereNull('business_id')));
            };

            $jobs = [
                'active' => $scoped()->where('state', 'active')->count(),
                'paused' => $scoped()->where('state', 'paused')->count(),
                'failed_24h' => JobRun::query()->whereIn('status', ['failed', 'dead'])->where('created_at', '>=', $now->subDay())
                    ->whereIn('scheduled_job_id', $scoped()->select('id'))->count(),
                'next' => $scoped()->where('state', 'active')->whereNotNull('next_run_at')->orderBy('next_run_at')->limit(3)
                    ->get(['id', 'name', 'next_run_at'])
                    ->map(fn (ScheduledJob $j) => ['id' => $j->id, 'name' => $j->name, 'next_run_at' => $j->next_run_at?->toIso8601String()])
                    ->all(),
            ];
        }

        return [
            'last_tick' => $last,
            'age_seconds' => $age,
            'stale_after_seconds' => $threshold,
            'running' => $age !== null && $age <= $threshold,
            'jobs' => $jobs,
        ];
    }

    /** Open alerts, ordered as the alerts screen orders them. Alerts are group-level rows. */
    private function alerts(): array
    {
        $open = Alert::open()
            ->orderByRaw("case severity when 'critical' then 0 when 'warning' then 1 else 2 end")
            ->orderBy('first_seen_at');

        return [
            'total' => (clone $open)->count(),
            'items' => $open->limit(self::MAX_ALERTS)->get()
                ->map(fn (Alert $a) => [
                    'id' => $a->id,
                    'rule' => $a->rule,
                    'severity' => $a->severity,
                    'subject' => $a->subject,
                    'last_seen_at' => $a->last_seen_at->toIso8601String(),
                ])->all(),
        ];
    }

    /** The monthly AI budget, read by the same reader the cost screen's budget block uses. */
    private function cost(): array
    {
        $pressure = $this->budget->pressure();

        return [
            'scope' => $pressure['scope'],
            'spent_usd' => round($pressure['spent'], 6),
            'cap_usd' => is_finite($pressure['cap']) ? $pressure['cap'] : null,
            'share_percent' => $pressure['share_percent'],
            'downgrade' => $pressure['downgrade'],
            'refuse' => $pressure['refuse'],
        ];
    }

    /** What policy decided in the last day, from the control plane's own reader and its scoping. */
    private function guard(User $user): array
    {
        $plane = $this->controlPlane->build(DashboardWindow::from('24h'), $user);

        return [
            'window' => '24h',
            'calls_total' => $plane['calls_total'],
            'policy_outcomes' => $plane['policy_outcomes'],
            'approvals_pending' => $plane['approvals_pending'],
            'tools_disabled' => count($plane['tools_disabled']),
            'active_grants' => $plane['active_grants'],
            'open_shadow_periods' => count($plane['open_shadow_periods']),
        ];
    }

    /** The AI runtime switch and each agent with its status and level, as the dashboard has always shown. */
    private function posture(): array
    {
        return [
            'ai_runtime' => $this->features->enabled((string) config('hq.ai.enabled_flag', 'ai.runtime')),
            'agents' => Agent::query()->orderBy('key')->get(['key', 'name', 'status', 'max_autonomy_level'])
                ->map(fn (Agent $a) => ['key' => $a->key, 'name' => $a->name, 'status' => $a->status, 'autonomy' => $a->max_autonomy_level?->value])
                ->values()->all(),
        ];
    }

    /**
     * Ask HQ's composer. It posts to the existing `POST /ask` and nowhere else. It is usable only
     * when the person holds `hq.ask.use`, the flag `ask_hq` is on and the AI runtime is on; the
     * screen names whichever is missing. Do and Plan are not capabilities today (docs/135 (e) 1).
     */
    private function composer(User $user): array
    {
        $permitted = $user->can('hq.ask.use');
        $flag = $this->ask->enabled();
        $runtime = $this->ask->runtimeOn();
        $usable = $permitted && $flag && $runtime;
        $businessScope = $this->scope->all($user);

        return [
            'permitted' => $permitted,
            'flag_on' => $flag,
            'runtime_on' => $runtime,
            'usable' => $usable,
            'agents' => $usable ? collect($this->ask->askableAgents())->map(fn (string $key) => [
                'key' => $key,
                'refusal' => $this->ask->refusalFor($user, $key),
            ])->values()->all() : [],
            'businesses' => $usable ? Business::query()->where('is_active', true)
                ->when($businessScope !== null, fn ($q) => $q->whereIn('id', $businessScope))
                ->orderBy('name')->get(['id', 'name'])->map(fn (Business $b) => ['id' => $b->id, 'name' => $b->name])->all() : [],
            'max_question' => AskHq::MAX_QUESTION,
        ];
    }
}
