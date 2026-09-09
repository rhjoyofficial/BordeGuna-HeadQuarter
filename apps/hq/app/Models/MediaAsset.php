<?php

namespace App\Models;

use App\Context\Provenance;
use App\Gateway\Descriptor\DataClass;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A stored file's metadata. The bytes live behind a StorageDriver and are never named by path here.
 */
class MediaAsset extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'uuid', 'business_id', 'brand_id', 'kind', 'mime', 'bytes', 'checksum_sha256',
        'storage_driver', 'storage_key', 'width', 'height', 'duration_ms', 'provenance',
        'source_ref', 'generator', 'rights', 'consent_event_id', 'data_class', 'retention_days',
        'expires_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'provenance' => Provenance::class,
            'data_class' => DataClass::class,
            'generator' => 'array',
            'rights' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /** Whether this asset may be used for a purpose its rights record allows. */
    public function permitsUse(string $use): bool
    {
        $allowed = $this->rights['allowed_uses'] ?? null;

        // Fail closed: an asset whose rights nobody recorded is not an asset anybody may publish.
        return is_array($allowed) && in_array($use, $allowed, true);
    }
}
