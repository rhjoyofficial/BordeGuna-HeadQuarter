<?php

namespace App\Models;

use App\Models\Concerns\VisibleByBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A real customer's statement, with recorded consent for an exact use (ChatGPT ruling 13, 31 Oct 2026,
 * docs/145-a). Never deleted; what it covers never widens; a revocation is final (database triggers).
 */
class ContentTestimonial extends Model
{
    use HasUuids, VisibleByBusiness;

    protected $table = 'content_testimonials';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'permitted_modalities' => 'array', 'brand_ids' => 'array', 'channels' => 'array',
            'valid_from' => 'date', 'valid_until' => 'date',
            'consent_given_at' => 'datetime', 'captured_at' => 'datetime', 'revoked_at' => 'datetime',
        ];
    }

    /** HasUuids would otherwise treat `id` as the uuid column. */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
