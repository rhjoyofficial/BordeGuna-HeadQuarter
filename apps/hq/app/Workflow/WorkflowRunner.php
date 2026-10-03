<?php

namespace App\Workflow;

use App\Gateway\Approval\ApprovalStatus;
use App\Gateway\CallerKind;
use App\Gateway\FailureClass;
use App\Gateway\Identity\ActingIdentity;
use App\Gateway\OutcomeClass;
use App\Gateway\OutcomeStatus;
use App\Gateway\Recording\ToolCallRecorder;
use App\Gateway\ToolGateway;
use App\Gateway\ToolRequest;
use App\Models\Approval;
use App\Models\ToolCall;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Models\WorkflowSignal;
use App\Models\WorkflowStep;
use App\Support\Audit\Auditor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * Advances one run as far as it can go, then stops at a wait, a failure or the end (docs/102 §4).
 *
 * The run's lease makes one worker the only writer; every status change is also a conditional update,
 * so a cancel that lands mid-advance wins. Steps are read from the ledger first: a step recorded as
 * done is never run again, and a tool step's earlier call is looked up by its idempotency key before
 * the gateway is asked, so a resumed run neither repeats an effect nor depends on the gateway's 24 h
 * replay window to avoid it.
 *
 * ⛔ The only door to an effect is `ToolGateway::execute()`. This class never touches a tool, a
 * connector or a credential.
 */
final class WorkflowRunner
{
    /** Gateway statuses that mean the effect happened (a replay returns the recorded result). */
    private const OK = [OutcomeStatus::Succeeded->value, OutcomeStatus::Replayed->value];

    public function __construct(
        private readonly WorkflowDefinitions $definitions,
        private readonly ToolGateway $gateway,
        private readonly ToolCallRecorder $recorder,
        private readonly Auditor $auditor,
    ) {}

    public function advance(int $instanceId): void
    {
        $token = (string) Str::uuid();
        $now = CarbonImmutable::now();
        $claimed = WorkflowInstance::query()->whereKey($instanceId)->whereIn('status', WorkflowInstance::OPEN)
            ->where(fn ($q) => $q->whereNull('lease_expires_at')->orWhere('lease_expires_at', '<', $now))
            ->update(['lease_token' => $token, 'lease_expires_at' => $now->addSeconds((int) config('hq.workflows.lease_seconds', 300)), 'updated_at' => $now]);
        if ($claimed === 0) {
            return; // finished, unknown, or another worker holds it
        }

        try {
            $this->run(WorkflowInstance::query()->findOrFail($instanceId));
        } finally {
            WorkflowInstance::query()->whereKey($instanceId)->where('lease_token', $token)->update(['lease_token' => null, 'lease_expires_at' => null]);
        }
    }

    /**
     * Moves the status along WorkflowInstance::TRANSITIONS with one conditional update. False when the
     * row was no longer in a state that may make this move (cancelled meanwhile, already finished).
     *
     * @param  array<string, mixed>  $extra
     */
    public function transition(WorkflowInstance $instance, string $to, array $extra = []): bool
    {
        $from = array_keys(array_filter(WorkflowInstance::TRANSITIONS, fn (array $next) => in_array($to, $next, true)));
        if (array_key_exists('output', $extra)) {
            $extra['output'] = json_encode($extra['output'], JSON_UNESCAPED_UNICODE);
        }
        $moved = WorkflowInstance::query()->whereKey($instance->getKey())->whereIn('status', $from)
            ->update([...$extra, 'status' => $to, 'updated_at' => CarbonImmutable::now()]);
        $instance->refresh();

        return $moved === 1;
    }

    private function run(WorkflowInstance $instance): void
    {
        $this->transition($instance, WorkflowInstance::RUNNING, [
            'wait_kind' => null, 'wait_for' => null, 'wake_at' => null,
            'started_at' => $instance->started_at ?? CarbonImmutable::now(),
        ]);

        if (! $this->definitions->has($instance->type)) {
            $this->failRun($instance, "No definition is registered for workflow type [{$instance->type}].");

            return;
        }
        $definition = $this->definitions->get($instance->type);
        if ($definition->version() !== (int) $instance->definition_version) {
            $this->failRun($instance, "Definition drift: the run started under version {$instance->definition_version}, the code is version {$definition->version()}.");

            return;
        }

        /** @var Collection<string, WorkflowStep> $ledger */
        $ledger = WorkflowStep::query()->where('workflow_instance_id', $instance->id)->get()->keyBy('step');

        foreach ($definition->steps() as $seq => $step) {
            $row = $ledger->get($step->name);
            if ($row?->isDone()) {
                continue;
            }
            if (WorkflowInstance::query()->whereKey($instance->id)->value('status') !== WorkflowInstance::RUNNING) {
                return; // cancelled while this worker was busy: stop here, write nothing more
            }

            $row ??= $this->openRow($instance, $step, $seq);
            $state = $this->state($instance, $ledger);

            if ($step->when !== null && ! ($step->when)($state)) {
                $this->finish($row, WorkflowStep::SKIPPED, null);
            } else {
                $result = match ($step->kind) {
                    Step::TOOL => $this->toolStep($instance, $step, $row, $state),
                    Step::SIGNAL => $this->signalStep($instance, $step, $row),
                    default => $this->sleepStep($step, $row, $state),
                };

                if (($result['fail'] ?? null) !== null) {
                    $this->failRun($instance, "step [{$step->name}]: {$result['fail']}", $step->name);

                    return;
                }
                if (($result['wait'] ?? null) !== null) {
                    $this->transition($instance, WorkflowInstance::WAITING, [
                        'current_step' => $step->name, 'wait_kind' => $result['wait'], 'wait_for' => $result['for'], 'wake_at' => $result['until'],
                    ]);

                    return;
                }
            }
            $ledger->put($step->name, $row->refresh());
        }

        $output = $ledger->map(fn (WorkflowStep $row) => ['status' => $row->status, 'outcome' => $row->outcome])->all();
        if ($this->transition($instance, WorkflowInstance::COMPLETED, ['current_step' => null, 'output' => $output, 'finished_at' => CarbonImmutable::now()])) {
            $this->auditor->recordSystem('workflow.completed', $instance, after: ['type' => $instance->type, 'instance_key' => $instance->instance_key], correlationId: $instance->correlation_id);
        }
    }

    /** @return array<string, mixed>|null null = done, continue; ['wait' => kind, ...] or ['fail' => reason] */
    private function toolStep(WorkflowInstance $instance, Step $step, WorkflowStep $row, WorkflowState $state): ?array
    {
        if ($row->status === WorkflowStep::WAITING && $row->approval_id !== null) {
            return $this->approvalWait($row);
        }
        if ($row->status === WorkflowStep::RUNNING) {
            // This worker holds the lease, so the one that wrote `running` is gone: it died mid-call.
            $this->markOrphan($row);
        }
        if ($row->wake_at !== null && $row->wake_at->isFuture()) {
            return ['wait' => 'timer', 'for' => $step->name, 'until' => $row->wake_at];
        }

        // What the gateway already did under this step's key decides, before the gateway is asked again.
        $prior = ToolCall::query()->where('idempotency_key', $row->idempotency_key)->where('tool_key', $step->toolKey)->orderByDesc('id')->first();
        if ($prior !== null && in_array($prior->status, self::OK, true)) {
            return $this->succeed($row, $prior->output ?? [], $prior->id);
        }
        // A worker that died after the gateway parked the call but before the ledger said so: take up that
        // approval while it is still open. A decided one is not re-read here (a retry asks afresh).
        if ($prior?->status === OutcomeStatus::AwaitingApproval->value && $prior->approval_id !== null
            && Approval::query()->whereKey($prior->approval_id)->whereIn('status', [ApprovalStatus::Pending->value, ApprovalStatus::Approved->value])->exists()) {
            $row->forceFill(['status' => WorkflowStep::WAITING, 'approval_id' => $prior->approval_id, 'tool_call_id' => $prior->id])->save();

            return $this->approvalWait($row);
        }
        $ceiling = (int) config('hq.gateway.replay_ceiling_seconds', 86400);
        if ($prior?->status === OutcomeStatus::Uncertain->value && $row->uncertain_accepted_at === null
            && $prior->created_at->lt(CarbonImmutable::now()->subSeconds($ceiling))) {
            return $this->fail($row, "tool call #{$prior->id} is uncertain and older than the gateway's replay ceiling ({$ceiling} s), so the gateway would no longer reconcile it. A person must check and retry with acceptUncertain.");
        }

        $requester = $instance->requested_by_user_id === null ? null : User::query()->find($instance->requested_by_user_id);
        if ($instance->requested_by_user_id !== null && ($requester === null || ! $requester->is_active)) {
            return $this->fail($row, 'the requester this run acts for is no longer an active user.');
        }

        try {
            $args = ($step->args)($state);
        } catch (Throwable $e) {
            return $this->fail($row, 'arguments could not be built: '.$e->getMessage());
        }

        $row->forceFill(['status' => WorkflowStep::RUNNING, 'attempt' => $row->attempt + 1, 'started_at' => CarbonImmutable::now(), 'wake_at' => null, 'error' => null])->save();

        $outcome = $this->gateway->execute(new ToolRequest(
            toolKey: (string) $step->toolKey,
            args: $args,
            caller: CallerKind::Workflow,
            identity: $requester === null ? ActingIdentity::system() : ActingIdentity::human($requester),
            correlationId: $instance->correlation_id,
            businessId: $instance->business_id,
            idempotencyKey: $row->idempotency_key,
            attempt: $row->attempt,
        ));
        $row->tool_call_id = $outcome->toolCallId;

        if ($outcome->ok()) {
            return $this->succeed($row, $outcome->output, $outcome->toolCallId);
        }
        if ($outcome->status === OutcomeStatus::AwaitingApproval) {
            $row->forceFill(['status' => WorkflowStep::WAITING, 'approval_id' => $outcome->approvalId])->save();

            return $this->approvalWait($row);
        }
        $error = "{$outcome->status->value}: {$outcome->error}";
        if (in_array($outcome->status, [OutcomeStatus::Failed, OutcomeStatus::Uncertain], true) && $row->attempt < $row->max_attempts) {
            $backoff = ($step->backoffSeconds ?? (int) config('hq.workflows.default_backoff_seconds', 60)) * (2 ** ($row->attempt - 1));
            $row->forceFill([
                'status' => $outcome->status === OutcomeStatus::Uncertain ? WorkflowStep::UNCERTAIN : WorkflowStep::PENDING,
                'error' => $error, 'wake_at' => CarbonImmutable::now()->addSeconds(min($backoff, 86400)),
            ])->save();

            return ['wait' => 'timer', 'for' => $step->name, 'until' => $row->wake_at];
        }

        // A refusal (denied, invalid, unavailable) is never retried blindly; neither is a spent budget.
        return $this->fail($row, $error);
    }

    /** The approval the gateway parked decides the step: still open waits; consumed reads the call; anything else declines. */
    private function approvalWait(WorkflowStep $row): ?array
    {
        $approval = Approval::query()->find($row->approval_id);
        if ($approval === null) {
            return $this->fail($row, "approval #{$row->approval_id} no longer exists.");
        }

        return match ($approval->status) {
            ApprovalStatus::Pending, ApprovalStatus::Approved => ['wait' => 'approval', 'for' => (string) $approval->id, 'until' => null],
            ApprovalStatus::Consumed => $this->consumed($row, $approval),
            default => $this->finish($row, WorkflowStep::DECLINED, [
                'approval_id' => $approval->id, 'approval_status' => $approval->status->value, 'note' => $approval->decision_note,
            ]),
        };
    }

    private function consumed(WorkflowStep $row, Approval $approval): ?array
    {
        $call = $approval->resulting_tool_call_id === null ? null : ToolCall::query()->find($approval->resulting_tool_call_id);
        if ($call === null || ! in_array($call->status, self::OK, true)) {
            return $this->fail($row, "approval #{$approval->id} was approved, but the approved call ended ".($call->status ?? 'unrecorded').'.');
        }

        return $this->succeed($row, [...($call->output ?? []), 'approval_id' => $approval->id], $call->id);
    }

    private function signalStep(WorkflowInstance $instance, Step $step, WorkflowStep $row): ?array
    {
        if ($row->status === WorkflowStep::PENDING) {
            $row->forceFill([
                'status' => WorkflowStep::WAITING, 'started_at' => CarbonImmutable::now(),
                'wake_at' => is_int($step->seconds) ? CarbonImmutable::now()->addSeconds($step->seconds) : null,
            ])->save();
        }

        $signal = WorkflowSignal::query()->where('workflow_instance_id', $instance->id)->where('name', $step->signal)
            ->whereNull('consumed_at')->orderBy('id')->first();
        if ($signal !== null && WorkflowSignal::query()->whereKey($signal->id)->whereNull('consumed_at')
            ->update(['consumed_at' => CarbonImmutable::now(), 'consumed_by_step' => $step->name, 'updated_at' => CarbonImmutable::now()]) === 1) {
            return $this->succeed($row, ['signal' => $signal->name, 'payload' => $signal->payload, 'signal_id' => $signal->id]);
        }
        if ($row->wake_at !== null && ! $row->wake_at->isFuture()) {
            return $this->finish($row, WorkflowStep::TIMED_OUT, ['timed_out' => true]);
        }

        return ['wait' => 'signal', 'for' => $step->signal, 'until' => $row->wake_at];
    }

    private function sleepStep(Step $step, WorkflowStep $row, WorkflowState $state): ?array
    {
        if ($row->wake_at === null) {
            $seconds = $step->seconds instanceof \Closure ? (int) ($step->seconds)($state) : (int) $step->seconds;
            $row->forceFill(['status' => WorkflowStep::WAITING, 'started_at' => CarbonImmutable::now(), 'wake_at' => CarbonImmutable::now()->addSeconds(max(0, $seconds))])->save();
        }
        if ($row->wake_at->isFuture()) {
            return ['wait' => 'timer', 'for' => $step->name, 'until' => $row->wake_at];
        }

        return $this->succeed($row, ['slept_until' => $row->wake_at->toIso8601String()]);
    }

    /**
     * A `started` call under this step's key was left by a dead worker: the effect may have landed. It is
     * recorded as `uncertain` through the gateway's own recorder, so the next gateway call with the same
     * key reconciles it (ADR-011) instead of executing blindly.
     */
    private function markOrphan(WorkflowStep $row): void
    {
        ToolCall::query()->where('idempotency_key', $row->idempotency_key)->where('status', OutcomeStatus::Started->value)->get()
            ->each(fn (ToolCall $call) => $this->recorder->complete($call, OutcomeStatus::Uncertain,
                error: 'The worker running this workflow step stopped mid-call; recorded as uncertain by the workflow engine (docs/102 §5).',
                failure: FailureClass::Uncertain, class: OutcomeClass::Uncertain));
        $row->forceFill(['status' => WorkflowStep::UNCERTAIN, 'error' => 'the worker stopped mid-step'])->save();
    }

    private function openRow(WorkflowInstance $instance, Step $step, int $seq): WorkflowStep
    {
        return WorkflowStep::query()->create([
            'workflow_instance_id' => $instance->id, 'step' => $step->name, 'seq' => $seq, 'kind' => $step->kind,
            'status' => WorkflowStep::PENDING, 'attempt' => 0, 'tool_key' => $step->toolKey,
            'max_attempts' => $step->kind === Step::TOOL ? ($step->maxAttempts ?? (int) config('hq.workflows.default_max_attempts', 3)) : 1,
            'idempotency_key' => "wf:{$instance->uuid}:{$step->name}",
        ]);
    }

    /** @param  Collection<string, WorkflowStep>  $ledger */
    private function state(WorkflowInstance $instance, Collection $ledger): WorkflowState
    {
        return new WorkflowState($instance->input ?? [], $ledger->map(fn (WorkflowStep $row) => ['status' => $row->status, 'outcome' => $row->outcome])->all());
    }

    private function succeed(WorkflowStep $row, array $outcome, ?int $toolCallId = null): ?array
    {
        $row->tool_call_id = $toolCallId ?? $row->tool_call_id;

        return $this->finish($row, WorkflowStep::SUCCEEDED, $outcome);
    }

    private function finish(WorkflowStep $row, string $status, ?array $outcome): ?array
    {
        $row->forceFill(['status' => $status, 'outcome' => $outcome, 'error' => null, 'wake_at' => null, 'finished_at' => CarbonImmutable::now()])->save();

        return null;
    }

    private function fail(WorkflowStep $row, string $reason): array
    {
        $row->forceFill(['status' => WorkflowStep::FAILED, 'error' => $reason, 'wake_at' => null, 'finished_at' => CarbonImmutable::now()])->save();

        return ['fail' => $reason];
    }

    private function failRun(WorkflowInstance $instance, string $reason, ?string $step = null): void
    {
        if ($this->transition($instance, WorkflowInstance::FAILED, [
            'current_step' => $step, 'wait_kind' => null, 'wait_for' => null, 'wake_at' => null,
            'last_error' => mb_substr($reason, 0, 2000), 'finished_at' => CarbonImmutable::now(),
        ])) {
            $this->auditor->recordSystem('workflow.failed', $instance, after: ['type' => $instance->type, 'instance_key' => $instance->instance_key, 'step' => $step],
                reason: $reason, correlationId: $instance->correlation_id);
        }
    }
}
