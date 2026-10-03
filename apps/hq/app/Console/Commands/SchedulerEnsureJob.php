<?php

namespace App\Console\Commands;

use App\Gateway\Descriptor\RiskTier;
use App\Models\ScheduledJob;
use App\Models\User;
use App\Scheduling\Enums\ApprovalState;
use App\Scheduling\Enums\JobState;
use App\Scheduling\ScheduledJobService;
use App\Tools\ToolRegistry;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

/**
 * Declares a recurring job by name: creates it if HQ has none, updates it in place if it does.
 *
 * A scheduled job is configuration, and configuration that can only be created by hand drifts and
 * gets duplicated - two jobs with the same purpose, one of them paused, is the failure this
 * command exists to make impossible. The name is the identity, so running this twice with the same
 * arguments is a no-op, and running it with different arguments moves the one job that exists.
 *
 * Nothing is written until `--apply`, because "what would this change" is the question worth
 * answering before a job starts firing against production every night.
 */
final class SchedulerEnsureJob extends Command
{
    protected $signature = 'hq:scheduler:ensure
        {name : the job name, which is also its identity for this command}
        {--tool= : registered tool key, e.g. commerce.reconcile_facts}
        {--cron= : five-field cron expression, e.g. "0 2 * * *"}
        {--timezone=Asia/Dhaka : the timezone the cron expression is read in}
        {--payload= : tool payload as JSON, e.g. {"store_slug":"laladia-com","hours":48}}
        {--owner= : email of the HQ user to own the job; system-owned when omitted}
        {--created-from=api : the origin recorded on the job: ui, api, nl or skill}
        {--run-now : after ensuring it, trigger one immediate run through the scheduler}
        {--apply : actually write. Without it the command only reports what it would do.}';

    protected $description = 'Create or update one recurring scheduled job by name (idempotent).';

    /** `scheduled_jobs.created_from` values (doc 21). */
    private const ORIGINS = ['ui', 'api', 'nl', 'skill'];

    public function handle(ScheduledJobService $service, ToolRegistry $tools): int
    {
        $name = (string) $this->argument('name');
        $existing = ScheduledJob::where('name', $name)->whereNot('state', JobState::Cancelled)->get();

        if ($existing->count() > 1) {
            $this->error("{$existing->count()} live jobs already share the name [{$name}]. Cancel the duplicates first; this command will not guess which one is real.");

            return self::FAILURE;
        }

        $job = $existing->first();
        $payload = $this->payload();
        if ($payload === null) {
            return self::FAILURE;
        }

        $createdFrom = (string) $this->option('created-from');
        if (! in_array($createdFrom, self::ORIGINS, true)) {
            $this->error('--created-from must be one of: '.implode(', ', self::ORIGINS).'.');

            return self::FAILURE;
        }

        $attributes = array_filter([
            'name' => $name,
            'kind' => 'cron',
            'tool_key' => $this->option('tool'),
            'cron_expression' => $this->option('cron'),
            'timezone' => $this->option('timezone'),
            'payload' => $payload,
        ], fn ($v) => $v !== null);

        $this->line($job ? "job [{$name}] exists (id {$job->id}); it would be updated" : "job [{$name}] does not exist; it would be created");
        foreach ($attributes as $key => $value) {
            $this->line(sprintf('  %-16s %s', $key, is_array($value) ? json_encode($value) : $value));
        }

        if (! $this->option('apply')) {
            $this->info('dry run: nothing was written. Add --apply to save it.');

            return self::SUCCESS;
        }

        try {
            $job = $job
                ? $service->update($job, $attributes, reason: 'hq:scheduler:ensure')
                : $service->create($attributes + ['approval_state' => $this->approvalFor($tools, (string) ($attributes['tool_key'] ?? ''))], $this->owner(), createdFrom: $createdFrom);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("job [{$name}] saved as id {$job->id}, uuid {$job->uuid}");
        $this->table(
            ['id', 'name', 'tool', 'cron', 'timezone', 'state', 'approval', 'next run (UTC)'],
            [[$job->id, $job->name, $job->tool_key, $job->cron_expression, $job->timezone, $job->state->value, $job->approval_state->value, $job->next_run_at?->toDateTimeString() ?? '-']],
        );

        if ($this->option('run-now')) {
            try {
                $run = $service->runNow($job);
                $this->info("run-now dispatched: job_run id {$run->id}, slot {$run->scheduled_for?->toDateTimeString()}");
            } catch (Throwable $e) {
                $this->error('run-now failed: '.$e->getMessage());

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    /** @return array<string, mixed>|null null when the JSON is unusable, which is a failure, not an empty payload. */
    private function payload(): ?array
    {
        $raw = $this->option('payload');
        if ($raw === null || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            $this->error('--payload must be a JSON object.');

            return null;
        }

        return $decoded;
    }

    /**
     * docs/48 L.5, the rule Scheduling in words already applies: a NEW job that runs an R3 or higher
     * tool is created waiting for a person's approval and nothing runs it until then. An existing job
     * keeps the approval it has; updating it in place never re-opens or skips that decision. An
     * unregistered tool is left to the service, which refuses it.
     */
    private function approvalFor(ToolRegistry $tools, string $toolKey): string
    {
        if (! $tools->has($toolKey)) {
            return ApprovalState::Approved->value;
        }

        return $tools->get($toolKey)->descriptor()->riskTier->atLeast(RiskTier::R3)
            ? ApprovalState::Pending->value
            : ApprovalState::Approved->value;
    }

    private function owner(): ?User
    {
        $email = $this->option('owner');

        return $email ? User::where('email', $email)->first() : null;
    }
}
