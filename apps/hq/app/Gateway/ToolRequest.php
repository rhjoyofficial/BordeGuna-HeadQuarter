<?php

namespace App\Gateway;

use App\Gateway\Identity\ActingIdentity;
use App\Models\JobRun;
use App\Models\ScheduledJob;
use App\Models\User;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

/**
 * One invocation, fully typed.
 *
 * A tool invocation is a typed tool key plus typed arguments plus a typed identity and context. It
 * is never a natural-language command, and there is nowhere in this object to put prose. That is
 * the line the whole control plane rests on: by the time a request exists, the model (if there was
 * one) has finished, and everything downstream reads structure rather than language.
 */
final readonly class ToolRequest
{
    public function __construct(
        public string $toolKey,
        public array $args,
        public CallerKind $caller,
        public ActingIdentity $identity,
        public string $correlationId,
        public ?int $businessId = null,
        public ?int $storeId = null,
        public ?int $brandId = null,
        /**
         * Supplied when the caller KNOWS two invocations are the same logical operation, which is
         * the only case where replaying a recorded result is unambiguously right. When it is null
         * the gateway derives one, and a derived key only replays inside the tool's declared
         * window (usually not at all).
         */
        public ?string $idempotencyKey = null,
        public ?int $jobRunId = null,
        public ?int $aiRunId = null,
        public int $attempt = 1,
    ) {}

    /**
     * The M2 seam, written now so that milestone is a small change rather than a redesign.
     *
     * M1 does NOT route the scheduler through the gateway: RunScheduledJob still calls the tool
     * directly, exactly as it does in production today, and the six live jobs are untouched. This
     * factory is what M2 will hand to the gateway, and it is tested here so the shape is known to
     * be right before anything depends on it.
     */
    public static function forScheduledJob(ScheduledJob $job, JobRun $run, ?User $owner = null): self
    {
        return new self(
            toolKey: $job->tool_key,
            args: $job->payload ?? [],
            caller: CallerKind::Scheduler,
            identity: ActingIdentity::forJob($owner ?? $job->owner),
            correlationId: (string) $run->correlation_id,
            businessId: $job->business_id,
            brandId: $job->brand_id,
            // A redelivered queue message for the same run is the same logical operation, so the
            // run's own identity is the key. That is what makes at-least-once delivery harmless.
            idempotencyKey: "job-run:{$run->correlation_id}",
            jobRunId: $run->id,
            attempt: $run->attempt ?: 1,
        );
    }

    public static function forOperator(User $user, string $toolKey, array $args = [], ?int $businessId = null, ?int $storeId = null): self
    {
        return new self(
            toolKey: $toolKey,
            args: $args,
            caller: CallerKind::HumanUi,
            identity: ActingIdentity::human($user),
            correlationId: (string) (Context::get('correlation_id') ?? Str::uuid()),
            businessId: $businessId,
            storeId: $storeId,
        );
    }

    public static function forConsole(string $toolKey, array $args = [], ?User $actor = null, ?int $businessId = null): self
    {
        return new self(
            toolKey: $toolKey,
            args: $args,
            caller: CallerKind::Console,
            identity: $actor ? ActingIdentity::human($actor) : ActingIdentity::system(),
            correlationId: (string) (Context::get('correlation_id') ?? Str::uuid()),
            businessId: $businessId,
        );
    }
}
