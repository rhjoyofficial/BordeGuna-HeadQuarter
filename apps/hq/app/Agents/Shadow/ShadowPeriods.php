<?php

namespace App\Agents\Shadow;

use App\Agents\PromptLibrary;
use App\Gateway\Descriptor\AutonomyLevel;
use App\Gateway\Descriptor\RiskTier;
use App\Gateway\ToolGateway;
use App\Http\Controllers\Webhooks\MetaWebhookController;
use App\Models\Agent;
use App\Models\AutonomyGrant;
use App\Models\EvaluationRun;
use App\Models\EvaluationSuite;
use App\Models\FeatureFlag;
use App\Models\ShadowPeriod;
use App\Platform\PlatformRegistry;
use App\Support\Audit\Auditor;
use App\Support\Features\Features;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Opening, closing and stopping an agent's A0 shadow period (docs/77 C.2, ChatGPT's points 6 to 8).
 *
 * ⭐ **Every precondition is read from the database at the moment of opening, never from a report.**
 * "Gate 11 passed" is a sentence; the evaluation rows are the fact. The open step reads the latest
 * run of every suite that applies to the agent's role and refuses unless each one passed with no
 * unmeasured case on the prompt stamp in force now, so a release that moved the prompt after the gate
 * cannot be activated on the old gate's evidence.
 *
 * ⛔ **Nothing here widens anything.** The agent's ceiling must already be A0 and stays A0; its tool
 * set must already hold nothing above R3 and nothing customer-facing; no grant is created; no other
 * agent is touched except to check that it is paused. Opening flips two switches that already exist
 * (`ai.runtime`, the agent's status) and writes the record; closing flips them back.
 *
 * ⛔ **A stop needs no one's permission.** When the daily check finds an A0 safety condition broken,
 * `stop()` pauses the agent and turns the runtime off at once. Switching off is the safe direction.
 */
final class ShadowPeriods
{
    public const RUNTIME_FLAG = 'ai.runtime';

    public const DAYS = 7;

    public function __construct(
        private readonly Auditor $auditor,
        private readonly Features $features,
        private readonly PromptLibrary $prompts,
        private readonly ToolGateway $gateway,
        private readonly PlatformRegistry $platforms,
        private readonly ShadowProbes $probes,
    ) {}

    /**
     * Every reason the agent may not enter a shadow period now. An empty list means it may.
     *
     * @return list<string>
     */
    public function refusals(string $agentKey): array
    {
        $out = [];
        $allowed = (array) config('hq.shadow.agents', ['executive']);
        if (! in_array($agentKey, $allowed, true)) {
            return ["[{$agentKey}] is not an agent this milestone may shadow; only ".implode(', ', $allowed).' may.'];
        }

        $agent = Agent::query()->where('key', $agentKey)->first();
        if ($agent === null) {
            return ["There is no agent [{$agentKey}]."];
        }

        if ($agent->max_autonomy_level !== AutonomyLevel::A0) {
            $out[] = "The agent's ceiling is {$agent->max_autonomy_level?->value}, not a0; a shadow period only runs at A0.";
        }
        if ($agent->enabling_user_id === null) {
            $out[] = 'The agent names no enabling user, so it could not run.';
        }
        if ($agent->status !== 'paused') {
            $out[] = "The agent is [{$agent->status}], not paused; something else has it on.";
        }
        if (ShadowPeriod::openFor($agentKey) !== null) {
            $out[] = 'A shadow period is already open for this agent.';
        }

        $out = [...$out, ...$this->toolRefusals((array) $agent->allowed_tools)];
        $out = [...$out, ...$this->evidenceRefusals($agent)];

        if (AutonomyGrant::query()->count() > 0) {
            $out[] = 'An autonomy grant exists; the shadow period starts from zero grants.';
        }

        $others = Agent::query()->where('key', '!=', $agentKey)->where('status', '!=', 'paused')->pluck('key')->all();
        if ($others !== []) {
            $out[] = 'Other agents are not paused: '.implode(', ', $others).'.';
        }

        $this->features->forget();
        if ($this->features->enabled(self::RUNTIME_FLAG)) {
            $out[] = 'ai.runtime is already on; a gate may be running. It must be off before a period opens.';
        }
        if ($this->features->enabled(MetaWebhookController::FLAG)) {
            $out[] = 'The Meta inbound flag is on; customer traffic must be off during this milestone.';
        }
        if ($this->platforms->platforms() !== []) {
            $out[] = 'A messaging provider is registered ('.implode(', ', $this->platforms->platforms()).'); outbound must not exist.';
        }

        return $out;
    }

    /**
     * The evaluation runs a period would rest on, keyed by suite, read the same way `refusals()` reads them.
     *
     * @return array<string, array{run_id: ?int, status: ?string, unmeasured: ?int, prompt_version: ?string, triggered_by: ?string, finished_at: ?string}>
     */
    public function evidenceFor(Agent $agent): array
    {
        $role = (string) ($agent->role_key ?: $agent->key);
        $out = [];

        $suites = EvaluationSuite::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('role_key')->orWhere('role_key', $role))
            ->orderBy('key')
            ->get();

        foreach ($suites as $suite) {
            $run = EvaluationRun::query()
                ->where('suite_id', $suite->id)
                ->where('agent_key', $agent->key)
                ->whereNot('status', 'running')
                ->orderByDesc('id')
                ->first();

            $out[$suite->key] = [
                'run_id' => $run?->id,
                'status' => $run?->status,
                'unmeasured' => $run === null ? null : (int) $run->cases_unmeasured,
                'prompt_version' => $run?->prompt_version,
                'triggered_by' => $run?->triggered_by,
                'finished_at' => $run?->finished_at?->toIso8601String(),
            ];
        }

        return $out;
    }

    /**
     * Open the period: runtime on, agent active, record written, all three audited, in one transaction.
     *
     * `$scheduled = false` opens it with no Shadow Probes, so the only runs are the ones a person asks
     * for. ChatGPT kept the probes (docs/77 E.1) and made them mandatory on production: there this is
     * refused, because a production week with no evidence workload is not a shadow period.
     *
     * @param  list<string>  $questions
     */
    public function open(string $agentKey, string $reason, array $questions = [], ?int $actorUserId = null, string $actorLabel = 'hq:shadow:open', bool $scheduled = true): ShadowPeriod
    {
        $refusals = $this->refusals($agentKey);
        if ($refusals !== []) {
            throw new RuntimeException("The shadow period for [{$agentKey}] cannot open:\n- ".implode("\n- ", $refusals));
        }
        if (trim($reason) === '') {
            throw new RuntimeException('A shadow period opens for a stated reason; none was given.');
        }

        if (! $scheduled && app()->isProduction()) {
            throw new RuntimeException('A production shadow period requires its scheduled Shadow Probes; --no-schedule is for manual and test use only (docs/77 E.1).');
        }

        $probes = $scheduled ? ShadowProbes::define($questions) : [];
        $agent = Agent::query()->where('key', $agentKey)->firstOrFail();
        $role = (string) ($agent->role_key ?: $agent->key);

        $period = DB::transaction(function () use ($agent, $role, $reason, $probes, $scheduled, $actorUserId, $actorLabel) {
            $flag = $this->runtimeFlag();
            $flag->forceFill(['enabled' => true])->save();
            $this->audit('feature_flag.toggled', $flag, ['enabled' => false], ['enabled' => true],
                "Shadow period for [{$agent->key}]: ai.runtime enabled for the A0 shadow only. {$reason}", $actorUserId);

            $agent->forceFill(['status' => 'active'])->save();
            $this->audit('agent.status_changed', $agent, ['status' => 'paused'], ['status' => 'active'],
                "Shadow period: [{$agent->key}] active at A0, ceiling unchanged, enabling user unchanged. {$reason}", $actorUserId);

            $now = now();
            $period = ShadowPeriod::create([
                'uuid' => (string) Str::uuid(),
                'agent_id' => $agent->id,
                'agent_key' => $agent->key,
                'autonomy_level' => AutonomyLevel::A0->value,
                'status' => ShadowPeriod::STATUS_OPEN,
                'opened_at' => $now,
                'planned_end_at' => $now->copy()->addDays(self::DAYS),
                'opened_by_user_id' => $actorUserId,
                'opened_by_label' => $actorLabel,
                'reason' => $reason,
                'evidence' => [
                    'prompt_version' => $this->prompts->has($role) ? $this->prompts->version($role) : null,
                    'suites' => $this->evidenceFor($agent),
                ],
                'allowed_tools' => array_values((array) $agent->allowed_tools),
                'questions' => $probes,
                'probes_required' => $scheduled,
            ]);

            $this->audit('shadow_period.opened', $period, null,
                ['agent_key' => $agent->key, 'level' => 'a0', 'planned_end_at' => $period->planned_end_at->toIso8601String(), 'probes' => array_column($probes, 'key'), 'probes_required' => $scheduled],
                $reason, $actorUserId);

            return $period;
        });

        $this->features->forget();

        return $period;
    }

    /** Close a period normally: agent paused, runtime off when no other period needs it, record closed. */
    public function close(ShadowPeriod $period, string $reason, ?int $actorUserId = null): ShadowPeriod
    {
        return $this->end($period, ShadowPeriod::STATUS_CLOSED, $reason, null, $actorUserId);
    }

    /**
     * Stop a period on a broken safety condition. Same switches as a close, recorded as a stop.
     *
     * @param  list<array<string, mixed>>  $violations
     */
    public function stop(ShadowPeriod $period, array $violations, string $reason): ShadowPeriod
    {
        return $this->end($period, ShadowPeriod::STATUS_STOPPED, $reason, $violations, null);
    }

    /** The tool-set conditions: known tools only, nothing above R3, nothing customer-facing. */
    private function toolRefusals(array $tools): array
    {
        $out = [];
        $descriptors = $this->gateway->descriptors();
        foreach ($tools as $key) {
            $descriptor = $descriptors[$key] ?? null;
            if ($descriptor === null) {
                $out[] = "The agent's tool [{$key}] is not registered.";

                continue;
            }
            if ($descriptor->riskTier->atLeast(RiskTier::R4)) {
                $out[] = "The agent's tool [{$key}] is {$descriptor->riskTier->value}; A0 shadow allows nothing above r3.";
            }
            if ($descriptor->customerFacing) {
                $out[] = "The agent's tool [{$key}] is customer-facing; the executive shadow has none.";
            }
        }

        return $out;
    }

    /** The evidence conditions: every applicable suite's latest run passed, fully measured, on today's prompt. */
    private function evidenceRefusals(Agent $agent): array
    {
        $role = (string) ($agent->role_key ?: $agent->key);
        $current = $this->prompts->has($role) ? $this->prompts->version($role) : null;
        $evidence = $this->evidenceFor($agent);
        $out = [];

        if ($evidence === []) {
            return ["No active evaluation suite applies to role [{$role}], so there is no evidence to open on."];
        }

        foreach ($evidence as $suite => $row) {
            if ($row['run_id'] === null) {
                $out[] = "Suite [{$suite}] has never been run against [{$agent->key}].";

                continue;
            }
            if ($row['status'] !== 'passed') {
                $out[] = "Suite [{$suite}]'s latest run #{$row['run_id']} is [{$row['status']}], not passed.";
            }
            if ($row['unmeasured'] !== 0) {
                $out[] = "Suite [{$suite}]'s latest run #{$row['run_id']} holds {$row['unmeasured']} unmeasured case(s).";
            }
            if ($current !== null && $row['prompt_version'] !== $current) {
                $out[] = "Suite [{$suite}]'s latest run #{$row['run_id']} measured prompt [{$row['prompt_version']}], but [{$current}] is in force now.";
            }
        }

        return $out;
    }

    private function end(ShadowPeriod $period, string $status, string $reason, ?array $violations, ?int $actorUserId): ShadowPeriod
    {
        if (! $period->isOpen()) {
            throw new RuntimeException("Shadow period #{$period->id} is already {$period->status}.");
        }

        $period = DB::transaction(function () use ($period, $status, $reason, $violations, $actorUserId) {
            $agent = Agent::query()->whereKey($period->agent_id)->lockForUpdate()->firstOrFail();
            $before = $agent->status;
            $agent->forceFill(['status' => 'paused'])->save();
            $this->audit('agent.status_changed', $agent, ['status' => $before], ['status' => 'paused'],
                "Shadow period #{$period->id} {$status}: [{$agent->key}] paused. {$reason}", $actorUserId);

            // docs/77 E.1: a period is complete only with its probe evidence; a stopped one never is.
            $summary = $this->probes->summary($period, CarbonImmutable::now());
            $complete = $status === ShadowPeriod::STATUS_STOPPED ? false : $summary['complete'];
            $reason .= $summary['required']
                ? " Probe evidence: {$summary['evidence']} of {$summary['expected']}".($complete ? ', complete.' : ', NOT complete.')
                : ' No scheduled probes were required.';

            $period->forceFill([
                'status' => $status,
                'closed_at' => now(),
                'end_reason' => $reason,
                'violations' => $violations,
                'probe_summary' => $summary,
                'evidence_complete' => $complete,
            ])->save();

            if (! ShadowPeriod::anyOpen()) {
                $flag = $this->runtimeFlag();
                $was = (bool) $flag->enabled;
                $flag->forceFill(['enabled' => false])->save();
                $this->audit('feature_flag.toggled', $flag, ['enabled' => $was], ['enabled' => false],
                    "Shadow period #{$period->id} {$status}: ai.runtime off, no other period is open. {$reason}", $actorUserId);
            }

            $this->audit("shadow_period.{$status}", $period, ['status' => ShadowPeriod::STATUS_OPEN],
                ['status' => $status, 'violations' => $violations === null ? 0 : count($violations)], $reason, $actorUserId);

            return $period;
        });

        $this->features->forget();

        return $period;
    }

    private function runtimeFlag(): FeatureFlag
    {
        // The same row the gate runner toggles: the first `ai.runtime` row, whatever its environment.
        return FeatureFlag::query()->where('key', self::RUNTIME_FLAG)->orderBy('id')->firstOrFail();
    }

    private function audit(string $action, $target, ?array $before, ?array $after, string $reason, ?int $actorUserId): void
    {
        $actorUserId === null
            ? $this->auditor->recordSystem($action, $target, $before, $after, $reason)
            : $this->auditor->record($action, $target, $before, $after, $reason, $actorUserId);
    }
}
