<?php

namespace App\Scheduling\Tools;

/**
 * A tool is the only thing a scheduled job (and, from Phase 2, an agent) can execute.
 * Phase 0 ships two: a harmless no-op and a deliberately failing tool for tests.
 *
 * Contract (ADR-011): execution is at-least-once, so execute() must be idempotent for the
 * same $context->idempotencyKey, and tools whose effect lives outside HQ implement
 * reconcile() to look that effect up before executing again.
 */
interface Tool
{
    public function key(): string;

    /** JSON-schema-like description of accepted payload keys, used for validation and later for model function definitions. */
    public function inputSchema(): array;

    public function execute(array $payload, ToolContext $context): ToolResult;

    /**
     * Look up the outcome of a previous, possibly interrupted, execution with the same idempotency key.
     * Return null when the tool has no external state to reconcile.
     */
    public function reconcile(ToolContext $context): ?ToolResult;
}
