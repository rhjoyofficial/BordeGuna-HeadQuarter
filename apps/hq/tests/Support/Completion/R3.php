<?php

namespace Tests\Support\Completion;

use App\Models\Business;
use App\Models\MediaAsset;
use Illuminate\Support\Str;

/**
 * Fixtures for the tests of the 31 October 2026 ruling, items 13 to 17 (builder R3). Static helpers, not
 * Pest functions, so files share them without a global-function redeclare in the one-process full suite.
 */
final class R3
{
    public static function asset(Business $business, string $kind = 'document', string $provenance = 'business'): MediaAsset
    {
        $mime = ['document' => 'application/pdf', 'image' => 'image/jpeg', 'video' => 'video/mp4', 'audio' => 'audio/mpeg'][$kind];

        return MediaAsset::query()->create(['business_id' => $business->id, 'kind' => $kind, 'mime' => $mime, 'bytes' => 10,
            'checksum_sha256' => hash('sha256', (string) Str::uuid()), 'storage_driver' => 'local', 'storage_key' => 'r3/'.Str::uuid(),
            'provenance' => $provenance, 'rights' => ['allowed_uses' => ['publish']]]);
    }

    /** A testimonial record body for POST /content/testimonials. */
    public static function testimonial(Business $business, int $brandId, array $over = []): array
    {
        return $over + [
            'business_id' => $business->id,
            'customer_label' => 'Customer R',
            'original_statement' => 'সরিষার তেলের ঘ্রাণ খুব ভালো লেগেছে।',
            'original_evidence_asset_id' => self::asset($business, 'image')->id,
            'consent_document_asset_id' => self::asset($business)->id,
            'consent_given_at' => now()->subDays(2)->toDateString(),
            'permitted_modalities' => ['text'],
            'brand_ids' => [$brandId],
            'channels' => ['facebook_page'],
            'valid_from' => now()->subDay()->toDateString(),
            'valid_until' => now()->addMonths(6)->toDateString(),
            'source_channel' => 'whatsapp',
            'captured_at' => now()->subDays(3)->toDateString(),
        ];
    }
}
