<?php

namespace App\Scheduling\Tools;

use App\Gateway\Descriptor\ToolDescriptor;

/**
 * A tool is the only unit of capability in HQ. If HQ can do something to the world, there is a
 * class for it, with a schema, a risk tier and tests. If there is no class, the capability does not
 * exist, and no amount of prompting creates one.
 *
 * Phase 0 shipped this interface with the note "and, from Phase 2, an agent". Phase 2 M1 adds
 * descriptor() and outputSchema(): the metadata the control plane needs to decide whether a call
 * may happen at all. Nothing was removed, and the four original methods are unchanged, so the
 * scheduler that has been running these tools in production since 6 September keeps working
 * exactly as it did.
 *
 * ⚠ The namespace still says Scheduling because that is where Phase 0 put it and moving it would
 * mean editing config('hq.tools'), which the live scheduler reads to find these classes. It moves
 * in M2, in the same commit that routes the scheduler through the gateway, where the change is
 * already being made and tested.
 *
 * Contract (ADR-011): execution is at-least-once, so execute() must be idempotent for the same
 * $context->idempotencyKey, and tools whose effect lives outside HQ implement reconcile() to look
 * that effect up before executing again.
 */
interface Tool
{
    public function key(): string;

    /** JSON-schema-like description of accepted payload keys, used for validation and later for model function definitions. */
    public function inputSchema(): array;

    /**
     * What callers may rely on in the result. Read by the registry screen, and by the model
     * function definitions when agents arrive.
     */
    public function outputSchema(): array;

    /**
     * The tool's whole contract with the control plane: risk tier, permission, scope, credentials,
     * idempotency, redaction, audit action (docs/48 D.1).
     *
     * It lives on the class rather than in configuration because these are statements about what
     * the code does. A risk tier that could be edited in a config file would be a risk tier that
     * could be edited to make an expensive action look like a cheap one.
     */
    public function descriptor(): ToolDescriptor;

    public function execute(array $payload, ToolContext $context): ToolResult;

    /**
     * Look up the outcome of a previous, possibly interrupted, execution with the same idempotency key.
     * Return null when the tool has no external state to reconcile.
     */
    public function reconcile(ToolContext $context): ?ToolResult;
}
