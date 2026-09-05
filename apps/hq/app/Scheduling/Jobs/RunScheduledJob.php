<?php

namespace App\Scheduling\Jobs;

use App\Models\JobRun;
use App\Scheduling\Enums\RunStatus;
use App\Scheduling\Tools\ToolContext;
use App\Scheduling\Tools\ToolRegistry;
use App\Scheduling\Tools\ToolResult;
use App\Support\Audit\Auditor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Executes one logical run. Horizon may deliver this job more than once (at-least-once);
 * the run row is the single record and the tool is idempotent on the run's correlation id.
 * Retries are our own (attempt counter + backoff), so the queue job itself never retries.
 */
final class RunScheduledJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public readonly int $jobRunId)
    {
        $this->onQueue('scheduler');
    }

    public function handle(ToolRegistry $tools, Auditor $auditor): void
    {
        $run = JobRun::with('job')->find($this->jobRunId);
        if (! $run || $run->isTerminal() || $run->status === RunStatus::Running) {
            return; // already done, or another worker holds it: at-least-once made harmless
        }

        // Claim the run atomically; a second delivery sees status=running and returns above.
        $claimed = DB::table('job_runs')->where('id', $run->id)->whereIn('status', ['queued', 'failed'])
            ->update(['status' => 'running', 'started_at' => now(), 'attempt' => DB::raw('attempt + 1')]);
        if ($claimed === 0) {
            return;
        }
        $run->refresh();
        $job = $run->job;

        Context::add(['run_id' => $run->id, 'job_id' => $job->id, 'correlation_id' => $run->correlation_id]);

        $context = new ToolContext(
            idempotencyKey: "job-run:{$run->correlation_id}",
            correlationId: (string) $run->correlation_id,
            businessId: $job->business_id,
            brandId: $job->brand_id,
            jobRunId: $run->id,
            actorUserId: $job->owner_user_id,
            attempt: $run->attempt,
            environment: (string) app()->environment(),
        );

        $started = microtime(true);
        try {
            $tool = $tools->get($job->tool_key);
            // ADR-011: on a re-attempt, ask the tool whether the previous attempt already took effect.
            $result = $run->attempt > 1 ? $tool->reconcile($context) : null;
            $result ??= $tool->execute($job->payload ?? [], $context);
        } catch (Throwable $e) {
            $result = ToolResult::failed(get_class($e).': '.$e->getMessage());
            Log::error('scheduled tool threw', ['tool' => $job->tool_key, 'run_id' => $run->id, 'exception' => $e]);
        }
        $durationMs = (int) ((microtime(true) - $started) * 1000);

        $run->appendAttempt([
            'attempt' => $run->attempt,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => now()->toIso8601String(),
            'duration_ms' => $durationMs,
            'status' => $result->status,
            'error' => $result->error,
        ]);
        $run->output = $result->toArray();
        $run->error = $result->error;
        $run->finished_at = now();

        if ($result->ok()) {
            $run->status = RunStatus::Succeeded;
        } elseif ($run->attempt <= $job->retry_max) {
            $run->status = RunStatus::Failed; // retryable
        } else {
            $run->status = RunStatus::Dead; // out of attempts; needs a human (retry button)
        }
        $run->save();

        $job->last_status = $run->status->value;
        $job->saveQuietly();

        $auditor->recordSystem('job_run.finished', $job, after: [
            'job_run_id' => $run->id, 'attempt' => $run->attempt, 'status' => $run->status->value, 'duration_ms' => $durationMs,
        ], correlationId: (string) $run->correlation_id);

        // A retryable failure stays visibly `failed` while it waits; the delayed retry claims it
        // from that state. A human "retry" on the same row is safe: the claim is atomic.
        if ($run->status === RunStatus::Failed) {
            $delay = $job->retry_backoff_seconds * (2 ** ($run->attempt - 1));
            self::dispatch($run->id)->delay(now()->addSeconds(min($delay, 3600)));
        }
    }
}
