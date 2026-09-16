<?php

namespace App\Scheduling\Nl;

use App\Models\ScheduledJob;
use App\Models\User;
use App\Scheduling\ScheduledJobService;
use App\Support\Audit\Auditor;
use App\Support\Features\Features;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The scheduler's natural-language front door (docs/48 L, docs/78 B.3), behind the flag `scheduler.nl`.
 *
 * A sentence becomes a preview or a question; a preview becomes a job only when the same person
 * confirms it. The validated attributes wait server-side under a one-time token, so the browser
 * confirms a preview it was shown and can change nothing about it. The job is created through the
 * same `ScheduledJobService::create()` as a job made on the form, with `created_from: nl` and the
 * person's own words in `nl_source_text`; the scheduler, not this class, runs it.
 */
final class NlScheduling
{
    public const FLAG = 'scheduler.nl';

    private const TOKEN_TTL_MINUTES = 15;

    private const PREFIX = 'hq:scheduler:nl:';

    public const MAX_TEXT = 500;

    public function __construct(
        private readonly Features $features,
        private readonly NlJobParser $parser,
        private readonly JobSpecValidator $validator,
        private readonly ScheduledJobService $jobs,
        private readonly Auditor $auditor,
    ) {}

    public function enabled(): bool
    {
        return $this->features->enabled(self::FLAG);
    }

    public function runtimeOn(): bool
    {
        return $this->features->enabled((string) config('hq.ai.enabled_flag', 'ai.runtime'));
    }

    /**
     * @return array{token: string|null, preview: array<string, mixed>, questions: list<string>, ai_run_id: int|null}
     */
    public function preview(User $user, string $text, ?int $businessId = null): array
    {
        $this->assertEnabled();
        $text = trim(mb_substr($text, 0, self::MAX_TEXT));

        if (! $this->runtimeOn()) {
            // Said plainly, and nothing asked: reading a sentence needs a model, and the model is off.
            $this->auditor->record('scheduler.nl_refused', 'scheduled_jobs', after: ['reason' => 'ai_runtime_off', 'text_length' => mb_strlen($text)]);

            return ['token' => null, 'preview' => [], 'questions' => [
                'HQ\'s AI runtime is switched off, so it cannot read a sentence yet. Use the form on the scheduler screen instead.',
            ], 'ai_run_id' => null];
        }

        $now = CarbonImmutable::now()->utc();
        $parsed = $this->parser->parse($text, $businessId, $now);
        $result = $this->validator->validate($parsed['draft'], $user, $now);

        $this->auditor->record('scheduler.nl_previewed', 'scheduled_jobs', after: [
            'ok' => $result->ok,
            'questions' => $result->questions,
            'tool_key' => $result->attributes['tool_key'] ?? null,
            'ai_run_id' => $parsed['ai_run_id'],
            'draft' => $parsed['draft']->toArray(),
        ]);

        if (! $result->ok) {
            return ['token' => null, 'preview' => [], 'questions' => $result->questions, 'ai_run_id' => $parsed['ai_run_id']];
        }

        $token = (string) Str::uuid();
        Cache::put(self::PREFIX.$token, [
            'user_id' => $user->getKey(),
            'text' => $text,
            'attributes' => $result->attributes,
            'ai_run_id' => $parsed['ai_run_id'],
        ], now()->addMinutes(self::TOKEN_TTL_MINUTES));

        return ['token' => $token, 'preview' => $result->preview + ['degraded' => $parsed['degraded']], 'questions' => [], 'ai_run_id' => $parsed['ai_run_id']];
    }

    /** Creates the previewed job. The token works once, for the person it was shown to. */
    public function confirm(User $user, string $token): ScheduledJob
    {
        $this->assertEnabled();

        $held = Cache::get(self::PREFIX.$token);

        if (! is_array($held) || (int) $held['user_id'] !== (int) $user->getKey()) {
            throw new RuntimeException('That preview has expired or is not yours. Describe the job again.');
        }

        // Spent the moment its owner uses it, so a double click cannot make two jobs.
        if (Cache::pull(self::PREFIX.$token) === null) {
            throw new RuntimeException('That preview has already been used. Describe the job again.');
        }

        $job = $this->jobs->create(
            $held['attributes'] + ['nl_source_text' => $held['text'], 'reason' => 'created from a sentence, previewed and confirmed'],
            $user,
            'nl',
        );

        $this->auditor->record('scheduler.nl_confirmed', $job, after: [
            'job_uuid' => $job->uuid,
            'approval_state' => $job->approval_state->value,
            'ai_run_id' => $held['ai_run_id'],
        ]);

        return $job;
    }

    private function assertEnabled(): void
    {
        if (! $this->enabled()) {
            throw new RuntimeException('Scheduling from a sentence is switched off (flag scheduler.nl).');
        }
    }
}
