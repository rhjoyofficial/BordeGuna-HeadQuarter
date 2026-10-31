<?php

namespace App\Avatar;

use App\Ai\Pause\AiPause;
use App\Models\AvatarConsent;
use App\Models\AvatarGeneration;
use App\Models\User;
use App\Research\ResearchRefused;
use App\Support\Audit\Auditor;
use App\Support\Features\Features;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Asks a provider for one avatar video or voice clip, only on a valid consent (docs/20, docs/145-b).
 *
 * Reached ONLY through the gateway tool `avatar.generate` (R4, permanently human-only, approval required),
 * which is declared and registered nowhere (`PhaseEightTools`). In order, every step refusing:
 *
 *  1. Pause All AI (generation is AI work; an unreadable pause state is a pause);
 *  2. the flag `avatar.generation` (no row: off);
 *  3. the consent, read under a row lock: not revoked, not expired, signed document for a real person,
 *     covers this business, brand, use and modality; a refusal is recorded as a `refused` row;
 *  4. a provider is bound (none is);
 *  5. the provider is called with a request built from that row;
 *  6. the consent is read AGAIN after the call: a revocation that landed meanwhile withdraws the result.
 */
final class AvatarGenerations
{
    public const FLAG = 'avatar.generation';

    public function __construct(
        private readonly AvatarConsents $consents,
        private readonly Features $features,
        private readonly AiPause $pause,
        private readonly Auditor $auditor,
    ) {}

    public function generate(?User $by, int $consentId, int $brandId, string $use, string $modality, string $script): AvatarGeneration
    {
        if ($this->pause->isPaused()) {
            throw new ResearchRefused('Pause All AI is on (or its state cannot be read), so nothing is generated.');
        }

        $consent = AvatarConsent::query()->find($consentId);
        if ($consent === null) {
            throw new ResearchRefused('No consent is recorded for this. No consent, no generation.');
        }
        if (! $this->features->enabled(self::FLAG, (int) $consent->business_id)) {
            throw new ResearchRefused('Avatar generation is off (flag avatar.generation). Nothing was generated.');
        }
        $script = trim($script);
        if ($script === '' || mb_strlen($script) > 2000) {
            throw new ResearchRefused('A script has between 1 and 2,000 characters.');
        }

        $row = DB::transaction(function () use ($by, $consent, $brandId, $use, $modality, $script): AvatarGeneration {
            $locked = AvatarConsent::query()->whereKey($consent->getKey())->lockForUpdate()->firstOrFail();
            $base = [
                'uuid' => (string) Str::uuid(),
                'consent_id' => $locked->getKey(),
                'business_id' => $locked->business_id,
                'brand_id' => $brandId,
                'use' => mb_substr($use, 0, 32),
                'modality' => mb_substr($modality, 0, 16),
                'script' => $script,
                'requested_by' => $by?->getKey(),
            ];

            $refusal = $this->consents->refusal($locked, (int) $locked->business_id, $brandId, $use, $modality)
                ?? (app()->bound(AvatarProvider::class) ? null : 'No avatar provider is connected, so nothing is generated.');
            if ($refusal !== null) {
                $refused = AvatarGeneration::query()->create($base + ['status' => 'refused', 'refusal_reason' => $refusal]);
                $this->auditor->record('avatar.generation.refused', $refused, after: ['consent' => $locked->uuid, 'reason' => $refusal], reason: $refusal, actorUserId: $by?->getKey());

                return $refused;
            }

            return AvatarGeneration::query()->create($base + ['status' => 'requested', 'provider' => app(AvatarProvider::class)->name()]);
        });

        if ($row->status === 'refused') {
            throw new ResearchRefused((string) $row->refusal_reason);
        }

        $provider = app(AvatarProvider::class);
        $result = $provider->generate(new AvatarRequest($row->uuid, (string) $consent->uuid, $row->modality, $row->script, $consent->provider_avatar_ref, $consent->provider_voice_ref));

        return DB::transaction(function () use ($row, $result, $by, $consent, $brandId, $use, $modality): AvatarGeneration {
            $fresh = AvatarConsent::query()->whereKey($consent->getKey())->lockForUpdate()->firstOrFail();
            $row = AvatarGeneration::query()->whereKey($row->getKey())->lockForUpdate()->firstOrFail();
            $after = $this->consents->refusal($fresh, (int) $fresh->business_id, $brandId, $use, $modality);

            if ($row->status === 'withdrawn' || $after !== null) {
                $row->forceFill(['status' => 'withdrawn', 'provider_job_ref' => $result->jobRef, 'refusal_reason' => $after ?? (string) $row->refusal_reason])->save();
                $this->auditor->record('avatar.generation.withdrawn', $row, after: ['consent' => $fresh->uuid, 'job' => $result->jobRef], reason: $after, actorUserId: $by?->getKey());

                return $row;
            }

            $row->forceFill($result->accepted
                ? ['status' => 'generated', 'provider_job_ref' => $result->jobRef]
                : ['status' => 'failed', 'refusal_reason' => $result->error])->save();
            $this->auditor->record('avatar.generation.'.$row->status, $row, after: ['consent' => $fresh->uuid, 'provider' => $row->provider, 'job' => $result->jobRef, 'error' => $result->error],
                actorUserId: $by?->getKey());

            return $row;
        });
    }
}
