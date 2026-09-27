<?php

namespace App\Conversation;

/**
 * What `SupportWorkflow` decided for one run: the workflow, the hand-over kind when it is a silent
 * hand-over, the rules version, who decided (the runtime's reading or a declared contract), and why.
 */
final readonly class SupportWorkflowVerdict
{
    public function __construct(
        public string $workflow,
        public ?string $kind,
        public string $version,
        public string $decidedBy,
        public string $reason,
        /**
         * docs/91 A.2: what the content classifier would have recommended for this thread, recorded and
         * never enforced when it differs from the verdict (`{workflow, kind}`, or null for no reading).
         *
         * @var array{workflow: string, kind: ?string}|null
         */
        public ?array $recommended = null,
    ) {}

    public function isSilentHandover(): bool
    {
        return $this->workflow === SupportWorkflow::SILENT_HANDOVER;
    }

    public function isGreetingFirst(): bool
    {
        return $this->workflow === SupportWorkflow::GREETING_FIRST;
    }

    /** The only tools a run under this workflow may name, or null when it is not restricted. */
    public function allowedTools(): ?array
    {
        return $this->isGreetingFirst() ? [SupportWorkflow::REPLY_TOOL] : null;
    }

    /** @return array{workflow: string, kind: ?string, version: string, decided_by: string, reason: string, recommended: ?array} */
    public function toArray(): array
    {
        return ['workflow' => $this->workflow, 'kind' => $this->kind, 'version' => $this->version, 'decided_by' => $this->decidedBy, 'reason' => $this->reason, 'recommended' => $this->recommended];
    }
}
