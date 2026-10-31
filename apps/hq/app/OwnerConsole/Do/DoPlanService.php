<?php

namespace App\OwnerConsole\Do;

use App\Gateway\Approval\ApprovalService;
use App\Gateway\Approval\ApprovalStatus;
use App\Gateway\CallerKind;
use App\Gateway\Descriptor\ReadWrite;
use App\Gateway\Descriptor\RiskTier;
use App\Gateway\Descriptor\ToolDescriptor;
use App\Gateway\Identity\ActingIdentity;
use App\Gateway\ToolGateway;
use App\Gateway\ToolRequest;
use App\Gateway\Validation\ArgumentValidator;
use App\Models\Approval;
use App\Models\DoPlan;
use App\Models\DoPlanStep;
use App\Models\User;
use App\OwnerConsole\Flag;
use App\OwnerConsole\Refused;
use App\Support\Audit\Auditor;
use App\Support\Authorization\BusinessScope;
use App\Tools\ToolRegistry;
use App\Workflow\WorkflowEngine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * "Do" mode (docs/143 A, docs/137 section 6 Q1): the Executive, or a person, proposes a plan of gateway tool
 * steps; nothing runs until a NAMED PERSON starts it, and then each effectful step still waits for its own
 * approval by a person. The plan, its steps, the approvals and the outcomes are rows a person can read.
 *
 * Inert twice over: the flag `console.do_mode` is OFF (docs/138 ruling 1 says NOT NOW; this ships the
 * mechanism, not the activation), and the gateway still decides every step.
 *
 * Held by tests:
 *  - it has no execute path of its own: every step is `ToolGateway::execute()` as the person who started
 *    the plan, with the caller kind `workflow`, so registry, schema, scope, permission, consent, policy,
 *    idempotency and audit apply unchanged;
 *  - an effectful step is only submitted when the gateway's read-only `authorize()` says policy AWAITS
 *    APPROVAL; a step policy would run on its own is refused when the plan is proposed (by tier) and again
 *    when it is reached (by policy);
 *  - starting a plan approves nothing, and approval stays the Approval Center's (`ApprovalExecutor`):
 *    this class never decides an approval and never imports the executor;
 *  - steps run strictly in order; a rejected, expired, cancelled or failed step HALTS the plan and the
 *    remaining steps are marked skipped, never run.
 */
final class DoPlanService
{
    public const MAX_STEPS = 5;

    public const PERMISSION_PROPOSE = 'hq.do.propose';

    public const PERMISSION_START = 'hq.do.start';

    public function __construct(
        private readonly BusinessScope $scope,
        private readonly ToolRegistry $tools,
        private readonly ArgumentValidator $validator,
        private readonly ToolGateway $gateway,
        private readonly WorkflowEngine $workflows,
        private readonly ApprovalService $approvals,
        private readonly Auditor $auditor,
    ) {}

    /** Which tools a plan may name: reads that run on their own, and effects that need approval. R3 writes would run unasked. */
    public static function eligible(ToolDescriptor $d): ?string
    {
        if ($d->readWrite === ReadWrite::Read && ! $d->riskTier->atLeast(RiskTier::R3)) {
            return null;
        }
        if ($d->riskTier->atLeast(RiskTier::R4)) {
            return null;
        }

        return "[{$d->key}] is an internal write that policy runs without asking a person, so a plan may not contain it.";
    }

    /**
     * @param  list<array{tool_key: string, args?: array<string, mixed>, summary: string}>  $steps
     * @param  User|null  $by  the person proposing, or the person whose authority the agent acts under
     */
    public function propose(int $businessId, string $title, array $steps, ?User $by, ?string $agentKey = null): DoPlan
    {
        $this->assertOn();
        if ($by === null) {
            throw new Refused('A plan is proposed by a named person, or by an agent acting under one.');
        }
        if (! $this->scope->allows($by, $businessId)) {
            throw new Refused('You may not plan for that business.');
        }
        $title = trim($title);
        if ($title === '' || mb_strlen($title) > 200) {
            throw new Refused('A plan needs a title of 1 to 200 characters.');
        }
        if ($steps === [] || count($steps) > self::MAX_STEPS) {
            throw new Refused('A plan has 1 to '.self::MAX_STEPS.' steps.');
        }

        $rows = [];
        foreach (array_values($steps) as $i => $step) {
            $key = (string) ($step['tool_key'] ?? '');
            if (! $this->tools->has($key)) {
                throw new Refused('Step '.($i + 1).": HQ has no tool [{$key}].");
            }
            $tool = $this->tools->get($key);
            $descriptor = $tool->descriptor();
            if (($why = self::eligible($descriptor)) !== null) {
                throw new Refused('Step '.($i + 1).': '.$why);
            }
            $validation = $this->validator->validate((array) ($step['args'] ?? []), $tool->inputSchema());
            if (! $validation->valid) {
                throw new Refused('Step '.($i + 1).': '.$validation->message());
            }
            $summary = trim((string) ($step['summary'] ?? ''));
            if ($summary === '' || mb_strlen($summary) > 300) {
                throw new Refused('Step '.($i + 1).' needs a summary of 1 to 300 characters saying what it is for.');
            }
            $rows[] = [
                'seq' => $i + 1, 'tool_key' => $key, 'args' => $validation->normalised, 'summary' => $summary,
                'state' => 'pending', 'effectful' => $descriptor->riskTier->atLeast(RiskTier::R3) || $descriptor->readWrite === ReadWrite::Write,
            ];
        }

        return DB::transaction(function () use ($businessId, $title, $rows, $by, $agentKey) {
            $plan = DoPlan::query()->create([
                'uuid' => (string) Str::uuid(), 'business_id' => $businessId, 'title' => $title, 'state' => 'proposed',
                'proposer_kind' => $agentKey === null ? 'human' : 'agent', 'agent_key' => $agentKey,
                'proposed_by_user_id' => $by->getKey(), 'correlation_id' => (string) Str::uuid(),
            ]);
            $plan->steps()->createMany($rows);
            $this->audit('do_plan.proposed', $plan, $by, ['steps' => array_column($rows, 'tool_key'), 'agent' => $agentKey]);

            return $plan;
        });
    }

    /** A named person starts the plan. This approves NO step: it only lets the first one be put to the gateway. */
    public function start(DoPlan $plan, User $by): DoPlan
    {
        $this->assertOn();
        $this->assertMayAct($plan, $by, self::PERMISSION_START);

        $claimed = DoPlan::query()->whereKey($plan->getKey())->where('state', 'proposed')->update([
            'state' => 'running', 'started_by_user_id' => $by->getKey(), 'started_at' => now(), 'updated_at' => now(),
        ]);
        if ($claimed === 0) {
            throw new Refused('This plan is not waiting to be started.');
        }
        $plan->refresh();

        $key = 'do-plan:'.$plan->uuid;
        $plan->forceFill(['workflow_instance_key' => $key])->save();
        $this->workflows->start(DoPlanWorkflow::TYPE, $key, ['plan_uuid' => $plan->uuid], $by, $plan->business_id);
        $this->audit('do_plan.started', $plan, $by, ['steps' => $plan->steps()->count()]);

        return $this->advance($plan);
    }

    /** Moves a running plan as far as it can go without a person: reads run, an effect is put up for approval, a decided approval is read back. */
    public function advance(DoPlan $plan): DoPlan
    {
        if (! Flag::on(Flag::DO_MODE)) {
            return $plan; // switched off: a running plan stands still, nothing more is put to the gateway
        }

        for ($guard = 0; $guard <= self::MAX_STEPS + 1; $guard++) {
            $plan->refresh();
            if ($plan->state !== 'running') {
                return $plan;
            }
            $step = $plan->steps()->whereIn('state', ['pending', 'awaiting_approval'])->first();
            if ($step === null) {
                return $this->finish($plan, 'completed', null);
            }

            $moved = $step->state === 'awaiting_approval' ? $this->settle($plan, $step) : $this->submit($plan, $step);
            if (! $moved) {
                return $plan->refresh();
            }
        }

        return $plan->refresh();
    }

    public function cancel(DoPlan $plan, User $by, ?string $reason = null): DoPlan
    {
        $this->assertMayAct($plan, $by, self::PERMISSION_START);
        $moved = DoPlan::query()->whereKey($plan->getKey())->whereIn('state', ['proposed', 'running'])
            ->update(['state' => 'cancelled', 'finished_at' => now(), 'halt_reason' => $reason, 'updated_at' => now()]);
        if ($moved === 0) {
            throw new Refused('This plan is already finished.');
        }
        $plan->refresh();
        $this->closeOpenWork($plan, "plan cancelled by {$by->email}", $by);
        $this->audit('do_plan.cancelled', $plan, $by, ['reason' => $reason]);

        return $plan;
    }

    // ------------------------------------------------------------------ internals

    /** @return bool true when the plan can go on to the next step */
    private function submit(DoPlan $plan, DoPlanStep $step): bool
    {
        $starter = User::query()->find($plan->started_by_user_id);
        if ($starter === null || ! $starter->is_active || ! $this->scope->allows($starter, $plan->business_id)) {
            $this->halt($plan, $step, 'failed', 'The person who started the plan is no longer allowed to act for this business.');

            return false;
        }

        $request = new ToolRequest(
            toolKey: $step->tool_key, args: (array) $step->args, caller: CallerKind::Workflow, identity: ActingIdentity::human($starter),
            correlationId: (string) $plan->correlation_id, businessId: $plan->business_id,
            idempotencyKey: "do:{$plan->uuid}:{$step->seq}",
        );

        if ($step->effectful) {
            // Read-only: the same authorization steps the gateway runs, nothing recorded and nothing run.
            $auth = $this->gateway->authorize($request);
            if (! $auth->awaitsApproval) {
                $this->halt($plan, $step, 'failed', $auth->authorized
                    ? 'Policy would run this step without a person approving it, so the plan refuses it.'
                    : 'The gateway refuses this step: '.($auth->reason ?? 'no reason recorded'));

                return false;
            }
        }

        try {
            $outcome = $this->gateway->execute($request);
        } catch (Throwable $e) {
            $this->halt($plan, $step, 'failed', 'The gateway call failed: '.class_basename($e));

            return false;
        }

        if ($outcome->status->pending()) {
            $step->forceFill(['state' => 'awaiting_approval', 'approval_id' => $outcome->approvalId, 'tool_call_id' => $outcome->toolCallId])->save();
            $this->audit('do_plan.step_awaiting_approval', $plan, null, ['seq' => $step->seq, 'tool_key' => $step->tool_key, 'approval_id' => $outcome->approvalId]);

            return false;
        }
        if ($outcome->ok()) {
            if ($step->effectful) {
                // An effectful step that ran with no approval breaks the plan's contract: say so and stop.
                $this->halt($plan, $step, 'failed', 'An effectful step ran without an approval; the plan stops.');

                return false;
            }
            $step->forceFill(['state' => 'succeeded', 'tool_call_id' => $outcome->toolCallId, 'outcome' => ['status' => $outcome->status->value], 'finished_at' => now()])->save();
            $this->audit('do_plan.step_succeeded', $plan, null, ['seq' => $step->seq, 'tool_key' => $step->tool_key]);

            return true;
        }

        $this->halt($plan, $step, 'failed', $outcome->error ?? $outcome->policyReason ?? 'The gateway refused the step.');

        return false;
    }

    /** @return bool true when the approval is decided and the step settled so the plan can go on */
    private function settle(DoPlan $plan, DoPlanStep $step): bool
    {
        $approval = Approval::query()->with('resultingToolCall')->find($step->approval_id);
        if ($approval === null) {
            $this->halt($plan, $step, 'failed', 'The approval for this step is gone.');

            return false;
        }

        switch ($approval->status) {
            case ApprovalStatus::Consumed:
                if ($approval->resultingToolCall?->status === 'succeeded') {
                    $step->forceFill(['state' => 'succeeded', 'tool_call_id' => $approval->resulting_tool_call_id, 'outcome' => ['status' => 'succeeded', 'approval_id' => $approval->id], 'finished_at' => now()])->save();
                    $this->audit('do_plan.step_succeeded', $plan, null, ['seq' => $step->seq, 'tool_key' => $step->tool_key, 'approval_id' => $approval->id]);

                    return true;
                }
                $this->halt($plan, $step, 'failed', 'The step was approved but the action did not run.');

                return false;
            case ApprovalStatus::Rejected:
            case ApprovalStatus::Cancelled:
            case ApprovalStatus::Expired:
                $this->halt($plan, $step, 'rejected', "Step {$step->seq} was not approved ({$approval->status->value}).");

                return false;
            default:
                return false; // still waiting for a person
        }
    }

    private function halt(DoPlan $plan, DoPlanStep $step, string $stepState, string $reason): void
    {
        $step->forceFill(['state' => $stepState, 'outcome' => ['reason' => $reason], 'finished_at' => now()])->save();
        $plan->steps()->whereIn('state', ['pending', 'awaiting_approval'])->where('id', '!=', $step->id)->update(['state' => 'skipped', 'finished_at' => now()]);
        $this->finish($plan, 'halted', $reason);
    }

    private function finish(DoPlan $plan, string $state, ?string $reason): DoPlan
    {
        DoPlan::query()->whereKey($plan->getKey())->where('state', 'running')
            ->update(['state' => $state, 'halt_reason' => $reason, 'finished_at' => now(), 'updated_at' => now()]);
        $plan->refresh();
        if ($plan->workflow_instance_key !== null) {
            $this->workflows->signal($plan->workflow_instance_key, DoPlanWorkflow::SIGNAL_FINISHED, ['state' => $state], 'finished');
        }
        $this->audit('do_plan.'.$state, $plan, null, ['reason' => $reason]);

        return $plan;
    }

    private function closeOpenWork(DoPlan $plan, string $reason, User $by): void
    {
        foreach ($plan->steps()->whereIn('state', ['pending', 'awaiting_approval'])->get() as $step) {
            if ($step->approval_id !== null && ($approval = Approval::query()->find($step->approval_id)) !== null && $approval->status === ApprovalStatus::Pending) {
                $this->approvals->cancel($approval, $by, $reason);
            }
            $step->forceFill(['state' => 'skipped', 'finished_at' => now()])->save();
        }
        if ($plan->workflow_instance_key !== null) {
            try {
                $this->workflows->cancel($plan->workflow_instance_key, $by, $reason);
            } catch (Throwable) {
                // A run that already finished needs nothing.
            }
        }
    }

    private function assertOn(): void
    {
        if (! Flag::on(Flag::DO_MODE)) {
            throw new Refused('Do mode is switched off.');
        }
    }

    private function assertMayAct(DoPlan $plan, User $by, string $permission): void
    {
        if (! $by->is_active || ! $by->can($permission)) {
            throw new Refused("You do not hold {$permission}.");
        }
        if (! $this->scope->allows($by, $plan->business_id)) {
            throw new Refused('You may not act for that business.');
        }
    }

    /** @param  array<string, mixed>  $after */
    private function audit(string $action, DoPlan $plan, ?User $by, array $after): void
    {
        $this->auditor->record($action, $plan, after: $after + ['plan' => $plan->uuid, 'business_id' => $plan->business_id],
            actorUserId: $by?->getKey(), actorLabel: $by === null ? 'do-plan' : null);
    }
}
