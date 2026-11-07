<?php

namespace App\Content;

use App\Context\Provenance;
use App\Models\Brand;
use App\Models\Business;
use App\Models\ContentTestimonial;
use App\Models\MediaAsset;
use App\Models\User;
use App\Research\ResearchRefused;
use App\Support\Audit\Auditor;
use App\Support\Authorization\BusinessScope;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Normalizer;
use Throwable;

/**
 * The narrow testimonial exception (ChatGPT ruling 13, 31 Oct 2026; docs/145-a Q2 as amended).
 *
 * The claims floor stays absolute. A draft may quote a customer only when ALL of these hold, and then the
 * ONLY thing waived is the floor's `floor.testimonial` phrase rule for that draft:
 *
 *   - a person recorded the testimonial (`record()`): the customer's original words verbatim, evidence of the
 *     original statement (an asset of this business), the recorded consent document (an asset of this
 *     business), when consent was given, where the statement came from (provenance) and when it was captured;
 *   - the consent covers the exact use: the modality (text, image, video, voice), the brand, the channel and
 *     the date, and it is not revoked;
 *   - the words were written by a person: model output (`generated`) can never carry a testimonial;
 *   - the quote is the customer's original words verbatim, or the person declared it edited, in which case
 *     approval needs the reviewer to confirm the exact final asset (its content digest);
 *   - a media testimonial (image, video, voice) is the recorded evidence asset itself, unaltered.
 *
 * Everything else still runs on the whole text, quote included: the floor's health and medical rule, the
 * owner's rules, banned words and the number check. Consent never makes a prohibited claim acceptable.
 * The final asset is reviewed by a person, and publishing remains its own governed approval.
 */
final class ContentTestimonials
{
    public const MODALITIES = ['text', 'image', 'video', 'voice'];

    public const CHANNELS = ['facebook_page', 'instagram'];

    public const SOURCES = ['whatsapp', 'messenger', 'instagram', 'email', 'phone', 'in_person', 'review_site'];

    /** The floor rule this exception, and only this exception, may waive. */
    public const WAIVABLE_RULE = 'floor.testimonial';

    public function __construct(
        private readonly BusinessScope $scope,
        private readonly Auditor $auditor,
    ) {}

    /**
     * @param  array{customer_label: string, original_statement: string, original_evidence_asset_id: int, consent_document_asset_id: int,
     *               consent_given_at: string, permitted_modalities: list<string>, brand_ids: list<int>, channels: list<string>,
     *               valid_from: string, valid_until: string, source_channel: string, source_ref?: ?string, captured_at: string}  $data
     */
    public function record(User $by, Business $business, array $data): ContentTestimonial
    {
        $this->mayAct($by, (int) $business->getKey());

        $label = trim((string) ($data['customer_label'] ?? ''));
        if ($label === '' || mb_strlen($label) > 120) {
            throw new ResearchRefused('Name the customer as they agreed to be shown, in at most 120 characters. No phone number or address.');
        }
        $statement = trim((string) ($data['original_statement'] ?? ''));
        if ($statement === '' || mb_strlen($statement) > 2000) {
            throw new ResearchRefused('Copy the customer\'s original words exactly, in at most 2,000 characters.');
        }
        $evidence = $this->asset($business, (int) ($data['original_evidence_asset_id'] ?? 0), ['image', 'video', 'audio', 'document'],
            'The evidence of the original statement is an asset in this business\'s library (a screenshot, recording or note).');
        $consentDoc = $this->asset($business, (int) ($data['consent_document_asset_id'] ?? 0), ['document', 'image', 'audio', 'video'],
            'The customer\'s recorded consent is an asset in this business\'s library. A verbal yes is not consent.');
        if ($evidence->getKey() === $consentDoc->getKey()) {
            throw new ResearchRefused('The consent record and the evidence of the original statement are two separate records.');
        }

        $modalities = self::subset((array) ($data['permitted_modalities'] ?? []), self::MODALITIES, 'the uses the consent covers (text, image, video, voice)');
        $channels = self::subset((array) ($data['channels'] ?? []), self::CHANNELS, 'the channels the consent covers');
        $source = (string) ($data['source_channel'] ?? '');
        if (! in_array($source, self::SOURCES, true)) {
            throw new ResearchRefused('Say where the customer said it: '.implode(', ', self::SOURCES).'.');
        }

        $brandIds = array_values(array_unique(array_map('intval', (array) ($data['brand_ids'] ?? []))));
        sort($brandIds);
        if ($brandIds === [] || Brand::query()->where('business_id', $business->getKey())->whereIn('id', $brandIds)->count() !== count($brandIds)) {
            throw new ResearchRefused('Name the brands the consent covers; each must belong to this business.');
        }

        try {
            $given = CarbonImmutable::parse((string) ($data['consent_given_at'] ?? ''));
            $captured = CarbonImmutable::parse((string) ($data['captured_at'] ?? ''));
            $from = CarbonImmutable::parse((string) ($data['valid_from'] ?? ''))->startOfDay();
            $until = CarbonImmutable::parse((string) ($data['valid_until'] ?? ''))->startOfDay();
        } catch (Throwable) {
            throw new ResearchRefused('Give when the customer said it, when they consented, and the period the consent covers.');
        }
        if ($given->greaterThan(now()) || $captured->greaterThan(now())) {
            throw new ResearchRefused('The statement and the consent are both in the past.');
        }
        if ($until->lessThan($from) || $until->greaterThan(now()->addMonths(36))) {
            throw new ResearchRefused('The consent period ends after it starts, and at most 36 months from today.');
        }

        $row = ContentTestimonial::query()->create([
            'business_id' => $business->getKey(),
            'customer_label' => $label,
            'original_statement' => $statement,
            'original_evidence_asset_id' => $evidence->getKey(),
            'consent_document_asset_id' => $consentDoc->getKey(),
            'consent_given_at' => $given,
            'permitted_modalities' => $modalities,
            'brand_ids' => $brandIds,
            'channels' => $channels,
            'valid_from' => $from->toDateString(),
            'valid_until' => $until->toDateString(),
            'source_channel' => $source,
            'source_ref' => ($ref = trim((string) ($data['source_ref'] ?? ''))) === '' ? null : mb_substr($ref, 0, 200),
            'captured_at' => $captured,
            'recorded_by' => $by->getKey(),
        ]);
        $this->auditor->record('content.testimonial.recorded', $row,
            after: ['uuid' => $row->uuid, 'business_id' => $row->business_id, 'modalities' => $modalities, 'brand_ids' => $brandIds, 'channels' => $channels,
                'valid_from' => $from->toDateString(), 'valid_until' => $until->toDateString(), 'evidence_asset_id' => $evidence->getKey(),
                'consent_asset_id' => $consentDoc->getKey(), 'statement_sha256' => hash('sha256', $statement)],
            actorUserId: $by->getKey());

        return $row;
    }

    public function revoke(User $by, ContentTestimonial $testimonial, string $reason): ContentTestimonial
    {
        $this->mayAct($by, (int) $testimonial->business_id);
        $reason = trim($reason);
        if ($reason === '') {
            throw new ResearchRefused('Say why the consent is withdrawn.');
        }

        return DB::transaction(function () use ($by, $testimonial, $reason): ContentTestimonial {
            $locked = ContentTestimonial::query()->whereKey($testimonial->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->revoked_at !== null) {
                throw new ResearchRefused('This testimonial consent is already withdrawn.');
            }
            $locked->forceFill(['revoked_at' => now(), 'revoked_by' => $by->getKey(), 'revocation_reason' => mb_substr($reason, 0, 2000)])->save();
            $this->auditor->record('content.testimonial.revoked', $locked, before: ['revoked_at' => null], after: ['revoked_at' => $locked->revoked_at?->toIso8601String()],
                reason: $reason, actorUserId: $by->getKey());

            return $locked;
        });
    }

    /**
     * Whether the exception applies to this exact draft. Never throws: a testimonial that does not qualify is a
     * failed check with its reasons, so the draft goes to a person marked failed.
     *
     * @param  list<array{media_asset_id: int, role: string}>  $assets
     * @return array{applies: bool, testimonial: string, edited: bool, modalities: list<string>, reasons: list<string>, waives: string}
     */
    public function judge(ContentTestimonial $t, string $text, string $textProvenance, int $businessId, int $brandId, string $platform, array $assets, bool $declaredEdited, ?DateTimeInterface $at = null): array
    {
        $at = CarbonImmutable::instance($at ?? now());
        $reasons = [];

        $verbatim = self::contains($text, (string) $t->original_statement);
        $edited = ! $verbatim;
        $modalities = ['text'];
        foreach ($assets as $a) {
            if (($a['role'] ?? '') !== 'testimonial') {
                continue;
            }
            $asset = MediaAsset::query()->find((int) ($a['media_asset_id'] ?? 0));
            $modalities[] = match ($asset?->kind) {
                'image' => 'image', 'video' => 'video', 'audio' => 'voice', default => 'unknown',
            };
            // A media testimonial is the recorded original itself, never a generated, edited or re-cut file.
            if ($asset === null || (int) $asset->getKey() !== (int) $t->original_evidence_asset_id) {
                $reasons[] = 'A testimonial image, video or voice clip is the recorded original evidence itself, unaltered.';
            }
        }
        $modalities = array_values(array_unique($modalities));

        if ($textProvenance !== Provenance::Business->value) {
            $reasons[] = 'Words written by a model never carry a testimonial. A person quotes the customer from the recorded source.';
        }
        if ((int) $t->business_id !== $businessId) {
            $reasons[] = 'The testimonial belongs to another business.';
        }
        if ($t->revoked_at !== null) {
            $reasons[] = 'The customer\'s consent was withdrawn on '.$t->revoked_at->toDateString().'.';
        }
        if (! in_array($brandId, array_map('intval', (array) $t->brand_ids), true)) {
            $reasons[] = 'The consent does not cover this brand.';
        }
        if (! in_array($platform, (array) $t->channels, true)) {
            $reasons[] = "The consent does not cover {$platform}.";
        }
        foreach ($modalities as $m) {
            if (! in_array($m, (array) $t->permitted_modalities, true)) {
                $reasons[] = "The consent does not cover use as {$m}.";
            }
        }
        $day = $at->setTimezone((string) config('hq.timezone', 'Asia/Dhaka'))->toDateString();
        if ($t->valid_from === null || $t->valid_until === null || $day < $t->valid_from->toDateString() || $day > $t->valid_until->toDateString()) {
            $reasons[] = 'Today is outside the period the consent covers.';
        }
        if ($edited && ! $declaredEdited) {
            $reasons[] = 'The quote is not the customer\'s original words. HQ never presents rewritten words as the customer\'s; if a person edited them, say so, and the exact final post is approved as edited.';
        }

        return [
            'applies' => $reasons === [],
            'testimonial' => (string) $t->uuid,
            'edited' => $edited,
            'modalities' => $modalities,
            'reasons' => $reasons,
            'waives' => self::WAIVABLE_RULE,
        ];
    }

    /** The original words appear in the text as written (whitespace and Unicode form aside). */
    public static function contains(string $text, string $original): bool
    {
        $o = self::fold($original);

        return $o !== '' && str_contains(self::fold($text), $o);
    }

    private static function fold(string $s): string
    {
        if (class_exists(Normalizer::class)) {
            $s = Normalizer::normalize($s, Normalizer::FORM_C) ?: $s;
        }

        return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
    }

    /** @param  list<string>  $kinds */
    private function asset(Business $business, int $id, array $kinds, string $why): MediaAsset
    {
        $asset = $id > 0 ? MediaAsset::query()->find($id) : null;
        if ($asset === null || (int) $asset->business_id !== (int) $business->getKey() || ! in_array($asset->kind, $kinds, true)
            || $asset->provenance !== Provenance::Business) {
            throw new ResearchRefused($why);
        }

        return $asset;
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
}
