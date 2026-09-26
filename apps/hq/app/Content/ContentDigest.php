<?php

namespace App\Content;

use App\Models\ContentDraft;
use Normalizer;

/**
 * The fingerprint of exactly what a person approves (ADR-051 accepted with clarifications, docs/88 A.5:
 * "the approved content fingerprinted / digested so an approval cannot be reused for changed content").
 *
 * The draft digest covers the words (NFC), every asset by id and checksum, the platform, the variant,
 * the brief version, the business and the brand. The publication digest adds the account it goes to.
 * The publish tool carries the publication digest in its payload, so the gateway's approval, which is
 * bound to the payload hash (ADR-018), is bound to it; and the tool recomputes it from the stored
 * draft before anything is sent. A changed word, a swapped picture or another Page is a different
 * digest, and an old approval does not cover it.
 */
final class ContentDigest
{
    public static function ofDraftParts(string $text, array $assets, string $platform, string $variant, int $briefId, int $businessId, int $brandId): string
    {
        if (class_exists(Normalizer::class)) {
            $text = Normalizer::normalize($text, Normalizer::FORM_C) ?: $text;
        }

        $assetList = array_map(static fn (array $a): array => [
            'id' => (int) ($a['media_asset_id'] ?? 0),
            'checksum' => (string) ($a['checksum_sha256'] ?? ''),
            'role' => (string) ($a['role'] ?? ''),
        ], $assets);
        usort($assetList, static fn (array $x, array $y): int => [$x['id'], $x['role']] <=> [$y['id'], $y['role']]);

        return hash('sha256', (string) json_encode([
            'v' => 1, 'text' => $text, 'assets' => $assetList, 'platform' => $platform, 'variant' => $variant,
            'brief_id' => $briefId, 'business_id' => $businessId, 'brand_id' => $brandId,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public static function ofDraft(ContentDraft $draft): string
    {
        return self::ofDraftParts((string) $draft->text, (array) $draft->assets, (string) $draft->platform, (string) $draft->variant,
            (int) $draft->brief_id, (int) $draft->business_id, (int) $draft->brand_id);
    }

    public static function forPublication(ContentDraft $draft, int $platformAccountId): string
    {
        return hash('sha256', self::ofDraft($draft).'|account:'.$platformAccountId);
    }
}
