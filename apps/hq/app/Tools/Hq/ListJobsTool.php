<?php

namespace App\Tools\Hq;

use App\Gateway\Descriptor\ReadWrite;
use App\Gateway\Descriptor\RiskTier;
use App\Gateway\Descriptor\ToolDescriptor;
use App\Gateway\Descriptor\ToolScope;
use App\Models\Business;
use App\Models\JobRun;
use App\Models\ScheduledJob;
use App\Tools\Tool;
use App\Tools\ToolContext;
use App\Tools\ToolResult;
use Carbon\CarbonImmutable;

/**
 * The scheduled work, and whether it is actually running (docs/48 D.6).
 *
 * The other half of "what needs attention": work that was supposed to happen. A failed poll is why
 * a snapshot is stale, and a snapshot being stale is why a number is wrong, so an agent that can
 * see the job history can explain a bad figure instead of merely reporting it.
 *
 * ⚠ It reports; it cannot pause, resume, cancel or run a job. Those are `schedule.*` tools and they
 * are M6, deliberately not here: the first agent release holds nothing that changes anything.
 */
final class ListJobsTool implements Tool
{
    private const MAX = 50;

    public function key(): string
    {
        return 'hq.list_jobs';
    }

    public function descriptor(): ToolDescriptor
    {
        return new ToolDescriptor(
            key: $this->key(),
            version: '1.0.0',
            title: 'List scheduled jobs',
            purpose: 'Lists scheduled jobs with their state, last outcome and next run. Read-only: it cannot start, pause or cancel anything.',
            riskTier: RiskTier::R1,
            permission: 'hq.jobs.view',
            auditAction: 'tool.hq_list_jobs',
            scope: ToolScope::Hq,
            readWrite: ReadWrite::Read,
            agentExecutable: true,
            idempotent: true,
            timeoutSeconds: 20,
            outputSchema: $this->outputSchema(),
        );
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'business_slug' => ['type' => 'string', 'maxLength' => 64],
                'only_failing' => ['type' => 'boolean'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX],
            ],
            'additionalProperties' => false,
        ];
    }

    public function outputSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'jobs' => ['type' => 'array'],
            'failing_count' => ['type' => 'integer'],
            'recent_failures' => ['type' => 'array'],
        ]];
    }

    public function execute(array $payload, ToolContext $context): ToolResult
    {
        $timezone = (string) config('hq.timezone', 'Asia/Dhaka');
        $now = CarbonImmutable::now($timezone);

        $query = ScheduledJob::query();
        $context->restrictBusinesses($query, 'business_id');

        if (($slug = $payload['business_slug'] ?? null) !== null) {
            $businessId = Business::where('slug', $slug)->value('id');
            if ($businessId === null) {
                return ToolResult::failed("HQ has no business with the slug [{$slug}].");
            }
            $query->where('business_id', $businessId);
        }

        if ((bool) ($payload['only_failing'] ?? false)) {
            $query->where('last_status', 'failed');
        }

        $jobs = [];
        // The id after the name: a name is not unique, and a limit must not cut between two jobs
        // of the same name by chance (docs/81 P1-1).
        foreach ($query->orderBy('name')->orderBy('id')->limit(min((int) ($payload['limit'] ?? 25), self::MAX))->get() as $job) {
            $jobs[] = [
                'name' => $job->name,
                'tool' => $job->tool_key,
                'state' => $job->state,
                'schedule' => $job->cron_expression ?? ($job->interval_seconds !== null ? "every {$job->interval_seconds}s" : $job->kind),
                'timezone' => $job->timezone,
                'last_status' => $job->last_status,
                'last_run_at' => $job->last_run_at?->setTimezone($timezone)->toIso8601String(),
                'next_run_at' => $job->next_run_at?->setTimezone($timezone)->toIso8601String(),
                // A job that is active and overdue is a different problem from one that failed, and
                // the two are told apart here rather than left for a reader to infer from a date.
                'overdue' => $job->state === 'active' && $job->next_run_at !== null
                    && CarbonImmutable::parse($job->next_run_at)->lessThan($now->subMinutes(15)),
            ];
        }

        return ToolResult::succeeded([
            'jobs' => $jobs,
            // docs/136: a count is a disclosure, so it is narrowed to the caller's businesses like the list.
            'failing_count' => $context->restrictBusinesses(ScheduledJob::query(), 'business_id')->where('last_status', 'failed')->count(),
            'recent_failures' => $this->recentFailures($timezone, $context),
            'as_of' => $now->toIso8601String(),
        ]);
    }

    public function reconcile(ToolContext $context): ?ToolResult
    {
        return null;
    }

    /** @return list<array<string, mixed>> */
    private function recentFailures(string $timezone, ToolContext $context): array
    {
        $out = [];

        // docs/136: only runs of jobs the caller may see; a job with no business only for a group-wide caller.
        $runs = JobRun::query()
            ->when($context->permittedBusinessIds !== null, fn ($q) => $q->whereHas('job', fn ($job) => $context->restrictBusinesses($job, 'business_id')))
            ->where('status', 'failed')
            ->where('created_at', '>=', CarbonImmutable::now()->subDays(7))
            ->latest('id')
            ->limit(10)
            ->get();

        foreach ($runs as $run) {
            $out[] = [
                'job' => $run->job?->name ?? "job:{$run->scheduled_job_id}",
                'at' => $run->finished_at?->setTimezone($timezone)->toIso8601String(),
                'attempt' => $run->attempt,
                // Trimmed: an error message is evidence, not a place for a stack trace to reach a prompt.
                'error' => $run->error === null ? null : mb_substr((string) $run->error, 0, 200),
            ];
        }

        return $out;
    }
}
