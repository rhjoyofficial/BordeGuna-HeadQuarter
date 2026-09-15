<?php

namespace App\Agents;

/**
 * What `TaskGrounding` decided for one run (ADR-041, ADR-042): the canonical task, which is the
 * operator's own words or nothing; the model's interpretation, kept as evidence; the authority
 * request as the runtime holds it; where that authority state came from (the runtime's own
 * detector, the model's field, or neither); the security-intent signal; and the one-word reason.
 */
final readonly class GroundedTask
{
    /** The runtime's detector set the state; the model cannot lower it. */
    public const SOURCE_RUNTIME = 'runtime';

    /** Only the model's field said so; the detector found nothing. */
    public const SOURCE_MODEL = 'model';

    /** Nobody raised it. */
    public const SOURCE_NONE = 'none';

    public function __construct(
        public string $canonicalTask,
        public string $modelTask,
        public string $authorityRequest,
        public string $reason,
        public string $authoritySource = self::SOURCE_NONE,
        public ?SecurityIntentSignal $securityIntent = null,
        /** Which targets the trusted text is about (ADR-043); null when no resolver read it. */
        public ?TaskScope $scope = null,
    ) {}

    /** The same grounding with the runtime's scope reading attached. */
    public function withScope(TaskScope $scope): self
    {
        return new self($this->canonicalTask, $this->modelTask, $this->authorityRequest, $this->reason,
            $this->authoritySource, $this->securityIntent, $scope);
    }

    public function hasTask(): bool
    {
        return $this->canonicalTask !== '';
    }

    public function authorityRefused(): bool
    {
        return $this->authorityRequest === AgentResponseSchema::AUTHORITY_REFUSED;
    }

    /** The runtime, not the model, found the message security-sensitive. */
    public function runtimeFlagged(): bool
    {
        return $this->authoritySource === self::SOURCE_RUNTIME;
    }
}
