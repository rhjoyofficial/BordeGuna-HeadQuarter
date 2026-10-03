<?php

namespace App\Workflow;

use App\Gateway\Approval\ApprovalService;
use App\Gateway\Approval\ApprovalStatus;
use App\Models\Approval;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Models\WorkflowSignal;
use App\Models\WorkflowStep;
use App\Support\Audit\Auditor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * The HQ-native engine (docs/101 §11 ruling 9.1, docs/102): three tables, the scheduler tick for
 * timers and repair, the event drain for signals, Horizon for the work, the Tool Gateway for every
 * effect and the `approvals` table for every human decision. This class owns the run's lifecycle;
 * `WorkflowRunner` owns advancing it.
 */
final class HqWorkflowEngine implements WorkflowEngine
{
    public function __construct(
        private readonly WorkflowDefinitions $definitions,
        private readonly WorkflowRunner $runner,
        private readonly ApprovalService $approvals,
        private readonly Auditor $auditor,
    ) {}

    public function start(string $type, string $instanceId, array $input = [], ?User $requestedBy = null, ?int $businessId = null): WorkflowRunState
    {
        $definition = $this->definitions->get($type);
        if ($requestedBy !== null && ! $requestedBy->is_active) {
            throw new InvalidArgumentException('A workflow cannot be started for an inactive user.');
        }

        $now = CarbonImmutable::now();
        // ON CONFLICT DO NOTHING: a second start of the same key is one run, without aborting a transaction.
        $created = WorkflowInstance::query()->insertOrIgnore([
            'uuid' => (string) Str::uuid(), 'instance_key' => $instanceId, 'type' => $type,
            'definition_version' => $definition->version(), 'status' => WorkflowInstance::PENDING,
            'input' => json_encode($input, JSON_UNESCAPED_UNICODE), 'correlation_key' => $definition->correlationKey($input),
            'correlation_id' => (string) Str::uuid(), 'business_id' => $businessId,
            'requested_by_user_id' => $requestedBy?->getKey(), 'created_at' => $now, 'updated_at' => $now,
        ]);

        $instance = $this->find($instanceId);
        if ($instance->type !== $type) {
            throw new InvalidArgumentException("[{$instanceId}] is already a run of [{$instance->type}].");
        }
        if ($created === 1) {
            $this->auditor->record('workflow.started', $instance, after: ['type' => $type, 'instance_key' => $instanceId, 'business_id' => $businessId],
                actorUserId: $requestedBy?->getKey());
            AdvanceWorkflow::dispatch($instance->id);
        }

        return $this->inspect($instanceId);
    }

    public function resume(string $instanceId): WorkflowRunState
    {
        $instance = $this->find($instanceId);
        if ($instance->isOpen()) {
            AdvanceWorkflow::dispatch($instance->id);
        }

        return $this->inspect($instanceId);
    }

    public function signal(string $instanceId, string $name, array $payload = [], ?string $dedupeKey = null): bool
    {
        $instance = WorkflowInstance::query()->where('instance_key', $instanceId)->first();
        if ($instance === null || ! $instance->isOpen()) {
            return false;
        }

        $now = CarbonImmutable::now();
        $recorded = WorkflowSignal::query()->insertOrIgnore([
            'workflow_instance_id' => $instance->id, 'name' => $name, 'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'dedupe_key' => $dedupeKey ?? (string) Str::uuid(), 'created_at' => $now, 'updated_at' => $now,
        ]);
        if ($recorded === 1) {
            AdvanceWorkflow::dispatch($instance->id);
        }

        return $recorded === 1;
    }

    public function cancel(string $instanceId, ?User $by = null, ?string $reason = null): WorkflowRunState
    {
        $instance = $this->find($instanceId);
        $cancelled = $this->runner->transition($instance, WorkflowInstance::CANCELLED, [
            'wait_kind' => null, 'wait_for' => null, 'wake_at' => null, 'finished_at' => CarbonImmutable::now(),
            'last_error' => $reason === null ? 'cancelled' : mb_substr("cancelled: {$reason}", 0, 2000),
        ]);

        if ($cancelled) {
            // A parked approval is withdrawn through its own lifecycle, never left decidable for a dead run.
            $approvalIds = WorkflowStep::query()->where('workflow_instance_id', $instance->id)->whereNotNull('approval_id')->pluck('approval_id');
            Approval::query()->whereIn('id', $approvalIds)->where('status', ApprovalStatus::Pending->value)->get()
                ->each(fn (Approval $approval) => $this->approvals->cancel($approval, $by, "workflow {$instanceId} cancelled"));
            $this->auditor->record('workflow.cancelled', $instance, after: ['type' => $instance->type, 'instance_key' => $instanceId], reason: $reason,
                actorUserId: $by?->getKey());
        }

        return $this->inspect($instanceId);
    }

    public function retry(string $instanceId, User $by, bool $acceptUncertain = false): WorkflowRunState
    {
        $instance = $this->find($instanceId);
        if ($instance->status !== WorkflowInstance::FAILED) {
            throw new RuntimeException("Only a failed run can be retried; [{$instanceId}] is {$instance->status}.");
        }

        DB::transaction(function () use ($instance, $by, $acceptUncertain) {
            $row = WorkflowStep::query()->where('workflow_instance_id', $instance->id)->where('status', WorkflowStep::FAILED)->first();
            if ($row !== null) {
                $step = $this->definitions->has($instance->type)
                    ? collect($this->definitions->get($instance->type)->steps())->firstWhere('name', $row->step)
                    : null;
                $budget = $step?->maxAttempts ?? (int) config('hq.workflows.default_max_attempts', 3);
                $row->forceFill([
                    'status' => WorkflowStep::PENDING, 'max_attempts' => $row->attempt + $budget, 'wake_at' => null, 'finished_at' => null,
                    // A declined/refused approval step asks again: the gateway parks a NEW approval.
                    'approval_id' => null,
                    'uncertain_accepted_at' => $acceptUncertain ? CarbonImmutable::now() : $row->uncertain_accepted_at,
                ])->save();
            }
            $this->runner->transition($instance, WorkflowInstance::RUNNING, ['last_error' => null, 'finished_at' => null]);
            $this->auditor->record('workflow.retried', $instance, after: ['step' => $row?->step, 'accept_uncertain' => $acceptUncertain],
                actorUserId: $by->getKey());
        });
        AdvanceWorkflow::dispatch($instance->id);

        return $this->inspect($instanceId);
    }

    public function inspect(string $instanceId): WorkflowRunState
    {
        $instance = $this->find($instanceId);
        $steps = WorkflowStep::query()->where('workflow_instance_id', $instance->id)->orderBy('seq')->get();

        return new WorkflowRunState(
            instanceId: $instance->instance_key,
            runId: $instance->uuid,
            type: $instance->type,
            status: $instance->status,
            currentStep: $instance->current_step,
            wait: $instance->wait_kind === null ? null : ['kind' => $instance->wait_kind, 'for' => $instance->wait_for, 'until' => $instance->wake_at?->toIso8601String()],
            output: $instance->output,
            error: $instance->last_error,
            steps: $steps->map(fn (WorkflowStep $row) => [
                'step' => $row->step, 'kind' => $row->kind, 'status' => $row->status, 'attempt' => $row->attempt,
                'max_attempts' => $row->max_attempts, 'idempotency_key' => $row->idempotency_key, 'tool_key' => $row->tool_key,
                'tool_call_id' => $row->tool_call_id, 'approval_id' => $row->approval_id, 'outcome' => $row->outcome,
                'error' => $row->error, 'wake_at' => $row->wake_at?->toIso8601String(),
            ])->all(),
            pendingSignals: WorkflowSignal::query()->where('workflow_instance_id', $instance->id)->whereNull('consumed_at')->orderBy('id')->pluck('name')->all(),
        );
    }

    public function reconcile(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();

        $ids = WorkflowInstance::query()
            ->whereIn('status', WorkflowInstance::OPEN)
            // A live lease means a worker is on it; an expired one means that worker died.
            ->where(fn ($q) => $q->whereNull('lease_expires_at')->orWhere('lease_expires_at', '<', $now))
            ->where(fn ($q) => $q
                // never advanced (a lost dispatch) or left mid-advance by a dead worker
                ->whereIn('status', [WorkflowInstance::PENDING, WorkflowInstance::RUNNING])
                // a sleep, a signal deadline or a retry backoff is due
                ->orWhere('wake_at', '<=', $now)
                // the approval it waits on has been decided
                ->orWhere(fn ($q) => $q->where('wait_kind', 'approval')->whereExists(fn ($e) => $e->selectRaw('1')->from('approvals')
                    ->whereRaw('CAST(approvals.id AS VARCHAR) = workflow_instances.wait_for')
                    ->whereNotIn('approvals.status', [ApprovalStatus::Pending->value, ApprovalStatus::Approved->value])))
                // the signal it waits on arrived while no worker was looking
                ->orWhere(fn ($q) => $q->where('wait_kind', 'signal')->whereExists(fn ($e) => $e->selectRaw('1')->from('workflow_signals')
                    ->whereColumn('workflow_signals.workflow_instance_id', 'workflow_instances.id')
                    ->whereColumn('workflow_signals.name', 'workflow_instances.wait_for')
                    ->whereNull('workflow_signals.consumed_at'))))
            ->orderBy('id')
            ->limit(200)
            ->pluck('id');

        foreach ($ids as $id) {
            AdvanceWorkflow::dispatch((int) $id);
        }

        return $ids->count();
    }

    private function find(string $instanceId): WorkflowInstance
    {
        return WorkflowInstance::query()->where('instance_key', $instanceId)->first()
            ?? throw new InvalidArgumentException("No workflow run [{$instanceId}].");
    }
}
