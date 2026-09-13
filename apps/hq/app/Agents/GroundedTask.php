<?php

namespace App\Agents;

/**
 * What `TaskGrounding` decided for one run (ADR-041): the canonical task, which is the operator's
 * own words or nothing; the model's interpretation, kept as evidence; the authority request as the
 * runtime reads it; and the one-word reason.
 */
final readonly class GroundedTask
{
    public function __construct(
        public string $canonicalTask,
        public string $modelTask,
        public string $authorityRequest,
        public string $reason,
    ) {}

    public function hasTask(): bool
    {
        return $this->canonicalTask !== '';
    }

    public function authorityRefused(): bool
    {
        return $this->authorityRequest === AgentResponseSchema::AUTHORITY_REFUSED;
    }
}
