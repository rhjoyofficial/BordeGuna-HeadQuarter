<?php

namespace App\Scheduling\Jobs;

use App\Gateway\OutcomeStatus;
use App\Gateway\ToolGateway;
use App\Gateway\ToolRequest;
use App\Models\JobRun;
use App\Models\ScheduledJob;
use App\Scheduling\Enums\RunStatus;
use App\Scheduling\EventTriggeredJobs;
use App\Support\Audit\Auditor;
use App\Tools\ToolResult;
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
 *
 * ⭐ The scheduler is an ordinary gateway caller (ADR-016) and nothing else. Every scheduled tool, effectful
 * or not, runs through `ToolGateway::execute()` and inherits every deterministic control: registry, schema,
 * scope, permission, policy, idempotency, credential resolution, `tool_calls`, audit.
 *
 * History, so nobody looks for the switch: M2 (docs/50) gave this class a second, direct path chosen by the
 * feature flag `scheduler.gateway`, as the rollback for the migration onto the gateway. docs/105 (S1) confined
 * the direct path to tools with no effect. docs/116 (ruling 110 §2f) retired it and the flag: a flag row named
 * `scheduler.gateway` governs nothing now, and no flag implies a bypass that does not exist. A job that must
 * not run is PAUSED (`ScheduledJobService::pause()`), which stops its work; nothing routes around the gateway.
 *
 * Everything a scheduler owns stays here: creating the run, the attempt log, backoff, dead-lettering,
 * missed-job behaviour and job state. Nothing the gateway owns is duplicated here, because two
 * implementations of one rule eventually disagree.
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

    public function handle(
        Auditor $auditor,
        ToolGateway $gateway,
        EventTriggeredJobs $eventJobs,
    ): void {
        // The owner is eager-loaded because the gateway reads it to build the acting identity, and
        // lazy loading is an error outside production.
        $run = JobRun::with(['job', 'job.owner'])->find($this->jobRunId);
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

        // The job's payload, plus `event` for a run a domain event started (docs/95 §16 NOW item 4).
        $args = $eventJobs->arguments($job, $run);

        $started = microtime(true);
        try {
            $result = $this->executeThroughGateway($gateway, $job, $run, $args);
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
            'job_run_id' => $run->id, 'attempt' => $run->attempt, 'status' => $run->status->value,
            'duration_ms' => $durationMs,
            // Always true since docs/116; kept so the audit row reads the same as every row before it.
            'via_gateway' => true,
        ], correlationId: (string) $run->correlation_id);

        // A retryable failure stays visibly `failed` while it waits; the delayed retry claims it
        // from that state. A human "retry" on the same row is safe: the claim is atomic.
        if ($run->status === RunStatus::Failed) {
            $delay = $job->retry_backoff_seconds * (2 ** ($run->attempt - 1));
            self::dispatch($run->id)->delay(now()->addSeconds(min($delay, 3600)));
        }
    }

    /**
     * The scheduler as an ordinary caller (ADR-016): registry, schema, scope, permission, policy, idempotency
     * and credential resolution all happen in the gateway, exactly as they do for every other caller.
     *
     * The gateway's own reconcile-on-retry handles a re-attempt, so this method deliberately contains no
     * gateway logic of its own. Duplicating any of it would give the scheduler a second, subtly different
     * set of rules, which is the whole thing ADR-016 forbids.
     *
     * The outcome is translated back into a ToolResult so that everything above (attempt log, retry,
     * dead-lettering, job state) is unchanged. A refusal is a failed run: the job did not do its
     * work, and the reason is on the record in both `job_runs` and `tool_calls`.
     */
    private function executeThroughGateway(ToolGateway $gateway, ScheduledJob $job, JobRun $run, array $args): ToolResult
    {
        $outcome = $gateway->execute(ToolRequest::forScheduledJob($job, $run, $job->owner, $args));

        if ($outcome->status === OutcomeStatus::Uncertain) {
            return ToolResult::uncertain((string) ($outcome->error ?? 'uncertain'), $outcome->providerRef);
        }

        if (! $outcome->ok()) {
            return ToolResult::failed((string) ($outcome->error ?? 'refused by the tool gateway'), $outcome->output);
        }

        return ToolResult::succeeded($outcome->output, $outcome->providerRef);
    }
}
