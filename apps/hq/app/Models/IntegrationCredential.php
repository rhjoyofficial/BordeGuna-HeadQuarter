<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An external credential (API key, OAuth token set, basic auth...) for one provider account.
 *
 * `payload` is encrypted at rest with APP_KEY and is never serialised to the browser:
 * it is hidden, and the UI only ever sees hasPayload()/status/expiry.
 */
class IntegrationCredential extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUSES = ['pending', 'active', 'expired', 'revoked', 'invalid'];

    public const TYPES = ['api_key', 'oauth2', 'token', 'basic', 'custom'];

    protected $fillable = [
        'provider', 'account_ref', 'business_id', 'brand_id', 'credential_type', 'payload',
        'scopes', 'status', 'expires_at', 'last_verified_at', 'last_refreshed_at', 'meta',
    ];

    protected $hidden = ['payload'];

    protected function casts(): array
    {
        return [
            'payload' => 'encrypted:array',
            'scopes' => 'array',
            'meta' => 'array',
            'expires_at' => 'datetime',
            'last_verified_at' => 'datetime',
            'last_refreshed_at' => 'datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function hasPayload(): bool
    {
        return $this->getRawOriginal('payload') !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** Safe representation for screens and logs: never the secret itself. */
    public function toSafeArray(): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'account_ref' => $this->account_ref,
            'business_id' => $this->business_id,
            'brand_id' => $this->brand_id,
            'credential_type' => $this->credential_type,
            'scopes' => $this->scopes,
            'status' => $this->status,
            'has_payload' => $this->hasPayload(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'last_verified_at' => $this->last_verified_at?->toIso8601String(),
            'last_refreshed_at' => $this->last_refreshed_at?->toIso8601String(),
        ];
    }
}
