<?php

namespace App\Briefing;

use App\Models\Briefing;
use App\Support\Features\Features;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Collect, validate, narrate only if allowed, check the narrative, store (docs/48 M.4, docs/80 C.1).
 *
 * The order is the instruction's: read models -> bundle -> validation -> narration. Each step can
 * only make the stored result more honest, never less:
 *
 * - a bundle that could not be collected is stored as `degraded` with `bundle_missing`, not skipped;
 * - a bundle the validator faults is `degraded` with every reason, and a provenance or completeness
 *   fault means nothing is narrated from it at all;
 * - narration happens only when the flag `briefing.narration` and the runtime flag are both on. With
 *   either off no narrator is resolved, no model can be reached, and the briefing is evidence-only;
 * - a narrative is stored as the narrative only when every figure in it is sourced. One unsourced
 *   figure marks the briefing `degraded`, names the figure, and keeps the rejected text inside
 *   `narrative_check` for the operator to see, never in `narrative`.
 */
final class BriefingComposer
{
    public function __construct(
        private readonly BundleCollector $collector,
        private readonly BundleValidator $validator,
        private readonly NarrativeNumberCheck $numbers,
        private readonly Features $features,
        private readonly Container $container,
    ) {}

    public function compose(BriefingRequest $request, string $idempotencyKey, ?string $correlationId = null, ?int $actorUserId = null): Briefing
    {
        if (($existing = Briefing::query()->where('idempotency_key', $idempotencyKey)->first()) !== null) {
            return $existing;
        }

        $bundle = null;
        try {
            $bundle = $this->collector->collect($request);
            $report = $this->validator->validate($bundle);
        } catch (Throwable $e) {
            $bundle = null;
            Log::warning('briefing: the evidence bundle could not be collected', ['exception' => get_class($e)]);
            $report = ValidationReport::missing('The evidence bundle could not be collected: '.class_basename($e).'.');
        }

        $reasons = $report->reasons;
        $narration = $this->narrate($request, $bundle, $report, $reasons);

        try {
            return Briefing::create([
                'uuid' => (string) Str::uuid(),
                'capability' => ExecutiveBriefingContract::CAPABILITY,
                'capability_version' => ExecutiveBriefingContract::VERSION,
                'bundle_version' => EvidenceBundle::VERSION,
                'briefing_date' => $request->today()['date'],
                'timezone' => $request->timezone,
                'business_id' => $request->businessId,
                'scope_business_ids' => $request->permittedBusinessIds,
                'state' => $reasons === [] ? Briefing::STATE_COMPLETE : Briefing::STATE_DEGRADED,
                'degraded_reasons' => $reasons,
                'bundle' => $bundle?->toArray(),
                'bundle_digest' => $bundle?->digest(),
                'validation' => $report->toArray(),
                'narration_state' => $narration['state'],
                'narrative' => $narration['narrative'],
                'narrative_check' => $narration['check'],
                'narrator' => $narration['narrator'],
                'ai_run_id' => $narration['ai_run_id'],
                'composed_by_user_id' => $actorUserId,
                'correlation_id' => $correlationId !== null && Str::isUuid($correlationId) ? $correlationId : null,
                'idempotency_key' => $idempotencyKey,
                'generated_at' => $request->now,
            ]);
        } catch (UniqueConstraintViolationException) {
            // The same logical composition arrived twice at once; the first one's row is the briefing.
            return Briefing::query()->where('idempotency_key', $idempotencyKey)->firstOrFail();
        }
    }

    /**
     * @param  list<array<string, mixed>>  $reasons  added to when narration itself degrades the briefing
     * @return array{state: string, narrative: ?string, check: ?array, narrator: ?string, ai_run_id: ?int}
     */
    private function narrate(BriefingRequest $request, ?EvidenceBundle $bundle, ValidationReport $report, array &$reasons): array
    {
        $none = static fn (string $state): array => ['state' => $state, 'narrative' => null, 'check' => null, 'narrator' => null, 'ai_run_id' => null];

        if (! $this->features->enabled(ExecutiveBriefingContract::NARRATION_FLAG, $request->businessId)) {
            return $none('off');
        }
        if (! $this->features->enabled((string) config('hq.ai.enabled_flag', 'ai.runtime'), $request->businessId)) {
            return $none('runtime_off');
        }
        if ($bundle === null || $report->blocksNarration()) {
            return $none('blocked');
        }

        try {
            $narration = $this->container->make(BriefingNarrator::class)->narrate(ExecutiveBriefingContract::TRUSTED_TASK, $bundle);
        } catch (Throwable $e) {
            $reasons[] = $this->reason($e instanceof NarratorUnavailable ? 'narrator_unavailable' : 'narrator_failed',
                $e instanceof NarratorUnavailable ? $e->getMessage() : 'The narrator failed: '.class_basename($e).'.');

            return $none('unavailable');
        }

        $base = ['narrator' => $narration->narrator, 'ai_run_id' => $narration->aiRunId];

        if (trim($narration->text) === '') {
            $reasons[] = $this->reason('narrative_empty', 'The narrator returned no text.');

            return ['state' => 'empty', 'narrative' => null, 'check' => null] + $base;
        }

        $check = $this->numbers->check($narration->text, $bundle);

        if (! $check->passed()) {
            foreach ($check->unsourced() as $figure) {
                $reasons[] = $this->reason('unsourced_figure', "The narrative contains the figure [{$figure['figure']}], which no item of the bundle backs. {$figure['why']}", $figure['figure']);
            }

            return ['state' => 'rejected', 'narrative' => null, 'check' => $check->toArray() + ['rejected_narrative' => $narration->text]] + $base;
        }

        return ['state' => 'narrated', 'narrative' => $narration->text, 'check' => $check->toArray()] + $base;
    }

    private function reason(string $code, string $message, ?string $subject = null): array
    {
        return ['code' => $code, 'check' => 'narration', 'subject' => $subject, 'message' => $message, 'blocks_narration' => false];
    }
}
