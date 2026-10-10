<?php

namespace App\Outcomes;

/**
 * A claim that one HQ action played a role in an outcome, made by a named method with a confidence
 * (docs/107 section 4.3). `actionRef` is the action's durable identity, readable without a join
 * (`tool_call:{tool_key}:{idempotency_key}`, `approval:{uuid}`, `agent_run:{uuid}`).
 */
final readonly class AttributionSpec
{
    /** @param  array<string, mixed>|null  $evidence */
    public function __construct(
        public ActionType $actionType,
        public int $actionId,
        public string $actionRef,
        public AttributionRole $role,
        public AttributionMethod $method,
        public string $methodVersion,
        public float $confidence,
        public string $idempotencyKey,
        public ?string $proposalRef = null,
        public ?array $evidence = null,
        public ?OutcomeActor $actor = null,
        public ?string $correlationId = null,
        public ?string $reason = null,
    ) {}
}
