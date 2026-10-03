<?php

namespace App\Workflow;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Horizon's part: advance one run as far as it can go. Carries only the id. Delivered at least once and
 * made harmless by the run's lease; a lost delivery is re-sent by `reconcile()` from the tick.
 */
final class AdvanceWorkflow implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public readonly int $instanceId)
    {
        $this->onQueue('scheduler');
    }

    public function handle(WorkflowRunner $runner): void
    {
        $runner->advance($this->instanceId);
    }
}
