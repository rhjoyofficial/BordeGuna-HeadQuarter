<?php

namespace App\Scheduling\Jobs;

use App\Gateway\OutcomeStatus;
use App\Gateway\ToolGateway;
use App\Gateway\ToolRequest;
use App\Models\JobRun;
use App\Models\ScheduledJob;
use App\Scheduling\Enums\RunStatus;
use App\Support\Audit\Auditor;
use App\Support\Features\Features;
use App\Tools\ToolContext;
use App\Tools\ToolRegistry;
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
 * ⚠ M2: this class now has TWO execution paths, chosen by a feature flag.
 *
 *   gateway mode (ADR-016)  the scheduler is an ordinary gateway caller and inherits every
 *                           deterministic control: registry, schema, scope, permission, policy,
 *                           idempotency, credential resolution, tool_calls, audit.
 *   direct mode             the Phase 1 path, unchanged, kept as the immediate rollback switch.
 *
 * Everything a scheduler owns stays here in both modes: creating the run, the attempt log, backoff,
 * dead-lettering, missed-job behaviour and job state. Nothing the gateway owns is duplicated here,
 * because two implementations of one rule eventually disagree.
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

    public function handle(ToolRegistry $tools, Auditor $auditor, ToolGateway $gateway, Features $features): void
    {
        // The owner is eager-loaded because gateway mode reads it to build the acting identity, and
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

        $viaGateway = $features->enabled((string) config('hq.scheduler_gateway_flag', 'scheduler.gateway'), $job->business_id);

        $started = microtime(true);
        try {
            $result = $viaGateway
                ? $this->executeThroughGateway($gateway, $job, $run)
                : $this->executeDirectly($tools, $job, $context, $run);
        } catch (Throwable $e) {
            $result = ToolResult::failed(get_class($e).': '.$e->getMessage());
            Log::error('scheduled tool threw', ['tool' => $job->tool_key, 'run_id' => $run->id, 'via_gateway' => $viaGateway, 'exception' => $e]);
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
            'duration_ms' => $durationMs, 'via_gateway' => $viaGateway,
        ], correlationId: (string) $run->correlation_id);

        // A retryable failure stays visibly `failed` while it waits; the delayed retry claims it
        // from that state. A human "retry" on the same row is safe: the claim is atomic.
        if ($run->status === RunStatus::Failed) {
            $delay = $job->retry_backoff_seconds * (2 ** ($run->attempt - 1));
            self::dispatch($run->id)->delay(now()->addSeconds(min($delay, 3600)));
        }
    }

    /**
     * Gateway mode (ADR-016). The scheduler becomes an ordinary caller: registry, schema, scope,
     * permission, policy, idempotency and credential resolution all happen in the gateway, exactly
     * as they do for every other caller.
     *
     * The gateway's own reconcile-on-retry handles what the direct path did by hand here, so this
     * method deliberately contains no gateway logic of its own. Duplicating any of it would give the
     * scheduler a second, subtly different set of rules, which is the whole thing ADR-016 forbids.
     *
     * The outcome is translated back into a ToolResult so that everything below (attempt log, retry,
     * dead-lettering, job state) is unchanged. A refusal is a failed run: the job did not do its
     * work, and the reason is on the record in both `job_runs` and `tool_calls`.
     */
    private function executeThroughGateway(ToolGateway $gateway, ScheduledJob $job, JobRun $run): ToolResult
    {
        $outcome = $gateway->execute(ToolRequest::forScheduledJob($job, $run, $job->owner));

        if ($outcome->status === OutcomeStatus::Uncertain) {
            return ToolResult::uncertain((string) ($outcome->error ?? 'uncertain'), $outcome->providerRef);
        }

        if (! $outcome->ok()) {
            return ToolResult::failed((string) ($outcome->error ?? 'refused by the tool gateway'), $outcome->output);
        }

        return ToolResult::succeeded($outcome->output, $outcome->providerRef);
    }

    /**
     * Direct mode: the Phase 1 path, byte for byte.
     *
     * Kept as the immediate rollback switch for the migration. It is not a permanent fork in the
     * road, and removing it is a separately approved step rather than a tidy-up.
     */
    private function executeDirectly(ToolRegistry $tools, ScheduledJob $job, ToolContext $context, JobRun $run): ToolResult
    {
        $tool = $tools->get($job->tool_key);
        // ADR-011: on a re-attempt, ask the tool whether the previous attempt already took effect.
        $result = $run->attempt > 1 ? $tool->reconcile($context) : null;

        return $result ?? $tool->execute($job->payload ?? [], $context);
    }
}
