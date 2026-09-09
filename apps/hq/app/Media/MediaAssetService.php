<?php

namespace App\Media;

use App\Context\Provenance;
use App\Models\MediaAsset;
use App\Platform\Contracts\StorageDriver;
use App\Support\Audit\Auditor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Storing and reading media, with its provenance and its rights (Phase 2.5 E).
 *
 * ⛔ **The domain model never sees a path.** `store()` generates an opaque key, hands the bytes to a
 * driver, and records the driver's name beside the key. Nothing above this class can build a
 * filesystem path even by accident, which is what makes the storage backend replaceable.
 *
 * ⭐ **Rights are recorded at store time or not at all.** `MediaAsset::permitsUse()` fails closed on
 * an asset whose rights nobody recorded, so the cost of forgetting is an asset that cannot be
 * published, rather than a publication nobody had the right to make.
 */
final readonly class MediaAssetService
{
    public function __construct(private StorageDriver $storage, private Auditor $auditor) {}

    /**
     * @param  array<string, mixed>  $attributes  business_id, brand_id, generator, rights,
     *                                            consent_event_id, data_class, retention_days
     */
    public function store(string $contents, string $mime, string $kind, Provenance $provenance, array $attributes = []): MediaAsset
    {
        $checksum = hash('sha256', $contents);

        // Content-addressed enough to make an identical re-upload obvious, prefixed by kind so a
        // human poking at the disk can tell what they are looking at.
        $key = $kind.'/'.substr($checksum, 0, 2).'/'.substr($checksum, 2, 2).'/'.$checksum.$this->extension($mime);

        return DB::transaction(function () use ($contents, $mime, $kind, $provenance, $attributes, $checksum, $key) {
            $existing = MediaAsset::where('checksum_sha256', $checksum)
                ->where('storage_driver', $this->storage->name())
                ->first();

            if ($existing !== null) {
                // The same bytes are the same asset. Storing them twice would double the disk and
                // leave two rows whose rights records could later disagree about one file.
                return $existing;
            }

            $this->storage->put($key, $contents, $mime);

            $asset = MediaAsset::create([
                'uuid' => (string) Str::uuid(),
                'business_id' => $attributes['business_id'] ?? null,
                'brand_id' => $attributes['brand_id'] ?? null,
                'kind' => $kind,
                'mime' => $mime,
                'bytes' => strlen($contents),
                'checksum_sha256' => $checksum,
                'storage_driver' => $this->storage->name(),
                'storage_key' => $key,
                'width' => $attributes['width'] ?? null,
                'height' => $attributes['height'] ?? null,
                'duration_ms' => $attributes['duration_ms'] ?? null,
                'provenance' => $provenance->value,
                'source_ref' => $attributes['source_ref'] ?? null,
                'generator' => $attributes['generator'] ?? null,
                'rights' => $attributes['rights'] ?? null,
                'consent_event_id' => $attributes['consent_event_id'] ?? null,
                'data_class' => $attributes['data_class'] ?? 'internal',
                'retention_days' => $attributes['retention_days'] ?? null,
                'expires_at' => $attributes['expires_at'] ?? null,
                'created_by' => $attributes['created_by'] ?? null,
            ]);

            $this->auditor->record('media_asset.stored', $asset, after: [
                'uuid' => $asset->uuid, 'kind' => $kind, 'mime' => $mime, 'bytes' => $asset->bytes,
                'provenance' => $provenance->value, 'driver' => $this->storage->name(),
            ]);

            return $asset;
        });
    }

    /** The bytes back, or null when the driver no longer holds them. */
    public function contents(MediaAsset $asset): ?string
    {
        if ($asset->storage_driver !== $this->storage->name()) {
            throw new RuntimeException(
                "Asset {$asset->uuid} is stored on driver [{$asset->storage_driver}] and this service holds [{$this->storage->name()}]."
            );
        }

        return $this->storage->get($asset->storage_key);
    }

    /** Whether what is on disk still matches what was recorded. */
    public function verify(MediaAsset $asset): bool
    {
        $contents = $this->contents($asset);

        return $contents !== null && hash('sha256', $contents) === $asset->checksum_sha256;
    }

    private function extension(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => '.jpg',
            'image/png' => '.png',
            'image/webp' => '.webp',
            'video/mp4' => '.mp4',
            'audio/mpeg' => '.mp3',
            'audio/ogg' => '.ogg',
            'application/pdf' => '.pdf',
            default => '.bin',
        };
    }
}
