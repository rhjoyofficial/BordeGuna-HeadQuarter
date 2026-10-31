<?php

namespace App\Avatar;

use App\Models\AvatarConsent;
use App\Models\AvatarGeneration;
use App\Models\Brand;
use App\Models\Business;
use App\Models\MediaAsset;
use App\Models\User;
use App\Research\ResearchRefused;
use App\Support\Audit\Auditor;
use App\Support\Authorization\BusinessScope;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The consent registry for a person's face and voice (docs/20, docs/31 Q21, docs/145-b).
 *
 * - **No consent, no generation.** `refusal()` is the one answer `AvatarGenerations` asks, twice per call.
 * - **Consent is a document.** A real person's consent row must point at the signed document in the asset
 *   library (this business's, kind document or image, provenance business) and say when it was signed.
 *   A verbal yes is not consent, and HQ never treats a synthetic persona as a real person or the reverse.
 * - **Consent always ends.** `expires_at` is required and at most three years ahead.
 * - **Revocation is immediate and final.** It is a column written under a row lock; every generation that
 *   rested on it and is not finished is withdrawn in the same transaction; a trigger refuses any later
 *   update of a revoked row, and what a consent covers can never be widened after it is recorded.
 * - No phone number or address is stored: `person_label` is the name the person agreed to be shown under.
 */
final class AvatarConsents
{
    public const KINDS = ['real_person', 'synthetic_persona'];

    public const MODALITIES = ['face', 'voice'];

    /** `customer_reply` (docs/20) is deliberately absent: an avatar never speaks to a customer as a reply (docs/145-b). */
    public const USES = ['product_promo', 'brand_presenter', 'internal'];

    /**
     * ChatGPT ruling 14 (31 Oct 2026), a HARD rule: an avatar never answers a customer and never acts as a
     * customer-support representative. These uses are refused before anything else is read, can never be
     * recorded on a consent (a database CHECK holds it too), and no consent, flag or approval can lift them.
     * Kept in step with the migration `2026_10_31_700000_...`.
     */
    public const CUSTOMER_FACING_USES = ['customer_reply', 'customer_support', 'support_reply', 'inbox_reply', 'conversation_reply'];

    public const CUSTOMER_FACING_REFUSAL = 'An avatar never answers a customer or acts as customer support (ruling 14, 31 Oct 2026). Avatars are for approved content and presentation only.';

    public const MAX_MONTHS = 36;

    public function __construct(
        private readonly BusinessScope $scope,
        private readonly Auditor $auditor,
    ) {}

    /**
     * @param  array{person_label: string, kind: string, modalities: list<string>, permitted_uses: list<string>, brand_ids: list<int>,
     *               consent_document_asset_id?: ?int, signed_at?: ?string, expires_at: string, provider?: ?string, provider_avatar_ref?: ?string, provider_voice_ref?: ?string}  $data
     */
    public function record(User $by, Business $business, array $data): AvatarConsent
    {
        $this->mayAct($by, (int) $business->getKey());

        $label = trim((string) ($data['person_label'] ?? ''));
        if ($label === '' || mb_strlen($label) > 120) {
            throw new ResearchRefused('Name the person (or persona) as they agreed to be shown, in at most 120 characters.');
        }
        $kind = (string) ($data['kind'] ?? '');
        if (! in_array($kind, self::KINDS, true)) {
            throw new ResearchRefused('Say whether this is a real person or a synthetic persona.');
        }
        $modalities = self::subset((array) ($data['modalities'] ?? []), self::MODALITIES, 'what may be used (face, voice)');
        if (array_intersect(array_map('strval', (array) ($data['permitted_uses'] ?? [])), self::CUSTOMER_FACING_USES) !== []) {
            throw new ResearchRefused(self::CUSTOMER_FACING_REFUSAL);
        }
        $uses = self::subset((array) ($data['permitted_uses'] ?? []), self::USES, 'what it may be used for');

        $brandIds = array_values(array_unique(array_map('intval', (array) ($data['brand_ids'] ?? []))));
        sort($brandIds);
        $own = Brand::query()->where('business_id', $business->getKey())->whereIn('id', $brandIds)->count();
        if ($brandIds === [] || $own !== count($brandIds)) {
            throw new ResearchRefused('Name the brands the consent covers; each must belong to this business.');
        }

        try {
            $expires = CarbonImmutable::parse((string) ($data['expires_at'] ?? ''));
        } catch (Throwable) {
            throw new ResearchRefused('A consent always has an end date.');
        }
        if ($expires->lessThanOrEqualTo(now()) || $expires->greaterThan(now()->addMonths(self::MAX_MONTHS))) {
            throw new ResearchRefused('The end date is in the future and at most '.self::MAX_MONTHS.' months away. A longer consent is a new consent later.');
        }

        $documentId = isset($data['consent_document_asset_id']) && $data['consent_document_asset_id'] !== null && $data['consent_document_asset_id'] !== '' ? (int) $data['consent_document_asset_id'] : null;
        $signedAt = null;
        if ($kind === 'real_person') {
            $document = $documentId !== null ? MediaAsset::query()->find($documentId) : null;
            if ($document === null || (int) $document->business_id !== (int) $business->getKey() || ! in_array($document->kind, ['document', 'image'], true)
                || $document->provenance->value !== 'business') {
                throw new ResearchRefused('A real person\'s consent points at the signed consent document in this business\'s asset library. A verbal yes is not consent.');
            }
            try {
                $signedAt = CarbonImmutable::parse((string) ($data['signed_at'] ?? ''));
            } catch (Throwable) {
                $signedAt = null;
            }
            if ($signedAt === null || $signedAt->greaterThan(now())) {
                throw new ResearchRefused('Say when the person signed the consent (not in the future).');
            }
        } elseif ($documentId !== null) {
            throw new ResearchRefused('A synthetic persona has no consent document; a real person\'s likeness is recorded as a real person.');
        }

        $consent = AvatarConsent::query()->create([
            'business_id' => $business->getKey(),
            'person_label' => $label,
            'kind' => $kind,
            'modalities' => $modalities,
            'permitted_uses' => $uses,
            'brand_ids' => $brandIds,
            'consent_document_asset_id' => $kind === 'real_person' ? $documentId : null,
            'signed_at' => $signedAt,
            'expires_at' => $expires,
            'provider' => self::optional($data['provider'] ?? null, 32),
            'provider_avatar_ref' => self::optional($data['provider_avatar_ref'] ?? null, 120),
            'provider_voice_ref' => self::optional($data['provider_voice_ref'] ?? null, 120),
            'recorded_by' => $by->getKey(),
        ]);
        $this->auditor->record('avatar.consent.recorded', $consent,
            after: ['uuid' => $consent->uuid, 'business_id' => $consent->business_id, 'kind' => $kind, 'modalities' => $modalities, 'uses' => $uses,
                'brand_ids' => $brandIds, 'document_asset_id' => $consent->consent_document_asset_id, 'expires_at' => $expires->toIso8601String()],
            actorUserId: $by->getKey());

        return $consent;
    }

    /** Revoke at once. Every unfinished generation that rested on it is withdrawn in the same transaction. */
    public function revoke(User $by, AvatarConsent $consent, string $reason): AvatarConsent
    {
        $this->mayAct($by, (int) $consent->business_id);
        $reason = trim($reason);
        if ($reason === '') {
            throw new ResearchRefused('Say why the consent is revoked (the person asked, the contract ended, ...).');
        }

        return DB::transaction(function () use ($by, $consent, $reason): AvatarConsent {
            $locked = AvatarConsent::query()->whereKey($consent->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->revoked_at !== null) {
                throw new ResearchRefused('This consent is already revoked.');
            }
            $locked->forceFill(['revoked_at' => now(), 'revoked_by' => $by->getKey(), 'revocation_reason' => mb_substr($reason, 0, 2000)])->save();

            $withdrawn = AvatarGeneration::query()->where('consent_id', $locked->getKey())->whereIn('status', ['requested', 'generated'])->pluck('uuid')->all();
            AvatarGeneration::query()->where('consent_id', $locked->getKey())->whereIn('status', ['requested', 'generated'])
                ->update(['status' => 'withdrawn', 'refusal_reason' => 'The consent it rested on was revoked.', 'updated_at' => now()]);

            $this->auditor->record('avatar.consent.revoked', $locked, before: ['revoked_at' => null], after: ['revoked_at' => $locked->revoked_at?->toIso8601String(), 'withdrawn_generations' => $withdrawn],
                reason: $reason, actorUserId: $by->getKey());

            return $locked;
        });
    }

    /** Why this consent cannot be used for this brand, use and modality now, or null when it can. */
    public function refusal(?AvatarConsent $consent, int $businessId, ?int $brandId, string $use, string $modality, ?DateTimeInterface $at = null): ?string
    {
        $at = CarbonImmutable::instance($at ?? now());
        // Ruling 14: first, and whatever any consent says.
        if (self::isCustomerFacing($use)) {
            return self::CUSTOMER_FACING_REFUSAL;
        }
        if ($consent === null) {
            return 'No consent is recorded for this. No consent, no generation.';
        }

        return match (true) {
            (int) $consent->business_id !== $businessId => 'The consent belongs to another business.',
            $consent->revoked_at !== null => 'The consent was revoked on '.$consent->revoked_at->toDateString().'.',
            $consent->expires_at === null || $consent->expires_at->lessThanOrEqualTo($at) => 'The consent has expired.',
            $consent->kind === 'real_person' && ($consent->consent_document_asset_id === null || $consent->signed_at === null) => 'A real person\'s consent has no signed document on record.',
            ! in_array($modality, (array) $consent->modalities, true) => "The consent does not cover the person's {$modality}.",
            ! in_array($use, (array) $consent->permitted_uses, true) => "The consent does not cover use for {$use}.",
            $brandId === null || ! in_array($brandId, array_map('intval', (array) $consent->brand_ids), true) => 'The consent does not cover this brand.',
            default => null,
        };
    }

    /** Ruling 14: is this a use in which an avatar would face a customer? Anything not on the allowed list counts. */
    public static function isCustomerFacing(string $use): bool
    {
        return in_array($use, self::CUSTOMER_FACING_USES, true) || ! in_array($use, self::USES, true);
    }

    /** One of: active, revoked, expired. */
    public static function state(AvatarConsent $consent): string
    {
        return match (true) {
            $consent->revoked_at !== null => 'revoked',
            $consent->expires_at === null || $consent->expires_at->isPast() => 'expired',
            default => 'active',
        };
    }

    private function mayAct(User $by, int $businessId): void
    {
        if (! $this->scope->allows($by, $businessId)) {
            throw new ResearchRefused('That business is outside what you may act on.');
        }
    }

    /** @return list<string> */
    private static function subset(array $values, array $allowed, string $what): array
    {
        $values = array_values(array_unique(array_map('strval', $values)));
        if ($values === [] || array_diff($values, $allowed) !== []) {
            throw new ResearchRefused("Choose {$what} from: ".implode(', ', $allowed).'.');
        }
        sort($values);

        return $values;
    }

    private static function optional(mixed $value, int $max): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
