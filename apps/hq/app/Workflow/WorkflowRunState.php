<?php

namespace App\Workflow;

use App\Models\WorkflowInstance;

/**
 * What `inspect()` returns: engine-neutral and in HQ's vocabulary (the WorkflowInstance statuses),
 * never an engine's internal rows.
 */
final readonly class WorkflowRunState
{
    /**
     * @param  array{kind: string, for: ?string, until: ?string}|null  $wait
     * @param  array<string, mixed>|null  $output
     * @param  list<array<string, mixed>>  $steps
     * @param  list<string>  $pendingSignals
     */
    public function __construct(
        public string $instanceId,
        public string $runId,
        public string $type,
        public string $status,
        public ?string $currentStep = null,
        public ?array $wait = null,
        public ?array $output = null,
        public ?string $error = null,
        public array $steps = [],
        public array $pendingSignals = [],
    ) {}

    public function isTerminal(): bool
    {
        return in_array($this->status, [WorkflowInstance::COMPLETED, WorkflowInstance::CANCELLED], true);
    }

    /** @return array<string, mixed>|null */
    public function step(string $name): ?array
    {
        foreach ($this->steps as $step) {
            if ($step['step'] === $name) {
                return $step;
            }
        }

        return null;
    }
}
