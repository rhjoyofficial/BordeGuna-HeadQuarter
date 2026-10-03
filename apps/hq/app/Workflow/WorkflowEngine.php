<?php

namespace App\Workflow;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * The seam HQ code depends on for durable, multi-step work (docs/101 §11 ruling 9.1, docs/102).
 *
 * Callers import this interface, `WorkflowRunState`, `WorkflowDefinition` and `Step`, and nothing
 * else. The implementation behind it (`HqWorkflowEngine` today; durable-workflow/workflow is the
 * documented upgrade path) can be swapped without touching them.
 *
 * ⛔ Not an authority bypass. Every effect a run has goes through the Tool Gateway, as the run's named
 * requester, with a `wf:{run}:{step}` idempotency key; an approval-gated step parks the run until the
 * approval in `approvals` is decided. The engine adds ORDER and DURABILITY, never permission.
 */
interface WorkflowEngine
{
    /**
     * Start one run under a caller-chosen business key. Starting the same key again returns the
     * existing run and creates nothing.
     *
     * @param  string  $type  the stable type name a definition declares, never a PHP class name
     * @param  array<string, mixed>  $input  plain scalars and arrays only
     */
    public function start(string $type, string $instanceId, array $input = [], ?User $requestedBy = null, ?int $businessId = null): WorkflowRunState;

    /** Ask a worker to advance the run now (after a restart, or by an operator). Changes no state by itself. */
    public function resume(string $instanceId): WorkflowRunState;

    /**
     * Deliver a named signal. Buffered until the run reaches the wait that takes it.
     *
     * @param  array<string, mixed>  $payload
     * @return bool true when a NEW signal was recorded; false when the run is finished or unknown, or
     *              already holds a signal with this dedupe key
     */
    public function signal(string $instanceId, string $name, array $payload = [], ?string $dedupeKey = null): bool;

    /** Stop the run for good. A pending approval it parked is cancelled through the approval lifecycle. */
    public function cancel(string $instanceId, ?User $by = null, ?string $reason = null): WorkflowRunState;

    /**
     * Give a failed run's failed step a fresh attempt budget. `$acceptUncertain` is the person saying
     * they checked by hand that an uncertain call older than the gateway's replay ceiling may run again.
     */
    public function retry(string $instanceId, User $by, bool $acceptUncertain = false): WorkflowRunState;

    public function inspect(string $instanceId): WorkflowRunState;

    /**
     * The sweep, called from the scheduler tick: runs whose worker died, whose timer or retry is due,
     * whose approval was decided or whose awaited signal arrived are handed to a worker again.
     *
     * @return int how many runs were dispatched
     */
    public function reconcile(?CarbonImmutable $now = null): int;
}
