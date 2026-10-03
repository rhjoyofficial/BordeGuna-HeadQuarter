<?php

namespace App\Workflow;

/** What a step's closures may read: the run's input and earlier steps' persisted results. Nothing else. */
final readonly class WorkflowState
{
    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, array{status: string, outcome: ?array}>  $steps
     */
    public function __construct(public array $input, private array $steps) {}

    public function input(string $key, mixed $default = null): mixed
    {
        return data_get($this->input, $key, $default);
    }

    public function status(string $step): ?string
    {
        return $this->steps[$step]['status'] ?? null;
    }

    public function output(string $step, ?string $key = null, mixed $default = null): mixed
    {
        $outcome = $this->steps[$step]['outcome'] ?? null;

        return $key === null ? $outcome : data_get($outcome, $key, $default);
    }
}
