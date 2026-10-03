<?php

namespace App\Workflow;

/**
 * A workflow is a small PHP class that declares ordered steps (docs/102 §2). No DSL and no graph: a
 * step may be skipped by its `when()` condition, and that is the only control flow.
 *
 * ⛔ Determinism: `steps()` and every closure in it read only the `WorkflowState` they are given (the
 * input and earlier steps' persisted outcomes). They never read the clock, the database, the network
 * or a model. Every effect is a tool step, executed by the engine through the Tool Gateway; a
 * definition never calls a tool, a connector or HTTP itself (held by WorkflowStructureTest).
 *
 * ⚠ Versioning: a run records `version()` at start and the engine refuses to advance it under another
 * version (the run fails, visibly). Raise `version()` whenever the steps change shape, and let old runs
 * finish, or cancel them, before deploying.
 */
abstract class WorkflowDefinition
{
    /** Stable name, e.g. "commerce.order_follow_up". Stored on the run; never a class name. */
    abstract public function type(): string;

    /** @return list<Step> */
    abstract public function steps(): array;

    public function version(): int
    {
        return 1;
    }

    /**
     * Domain event types delivered to a run as signals named "event:{type}", when the event's
     * "{aggregate_type}:{aggregate_id}" equals the run's correlation key.
     *
     * @return list<string>
     */
    public function listensTo(): array
    {
        return [];
    }

    /** @param  array<string, mixed>  $input */
    public function correlationKey(array $input): ?string
    {
        return null;
    }
}
