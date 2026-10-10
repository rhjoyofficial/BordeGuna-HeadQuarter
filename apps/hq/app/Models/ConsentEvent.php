<?php

namespace App\Models;

use App\Consent\ConsentBasis;
use App\Consent\ConsentPurpose;
use App\Consent\ConsentState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One recorded consent fact (ADR-021). Append-only: the database refuses UPDATE and DELETE.
 *
 * ⚠ `occurred_at` and `recorded_at` are different questions. An import of last year's paper forms
 * happened last year and was recorded today, and a reconstruction "as at" a past instant that
 * confuses the two will confidently report consent the group did not have at the time.
 */
class ConsentEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'customer_id', 'business_id', 'brand_id', 'identity_id', 'channel', 'purpose', 'state', 'basis',
        'occurred_at', 'recorded_at', 'expires_at', 'evidence_ref', 'evidence',
        'actor_type', 'actor_id', 'actor_label', 'reason', 'data_class', 'retention_days',
        'correlation_id', 'tool_call_id',
    ];

    protected function casts(): array
    {
        return [
            'purpose' => ConsentPurpose::class,
            'state' => ConsentState::class,
            'basis' => ConsentBasis::class,
            'evidence' => 'array',
            'occurred_at' => 'datetime',
            'recorded_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // The database refuses these anyway. Refusing them here as well turns a PostgreSQL
        // exception from deep inside a query into a sentence that names the rule, at the line that
        // broke it.
        static::updating(fn () => throw new LogicException('consent_events is append-only: record a new event rather than editing history.'));
        static::deleting(fn () => throw new LogicException('consent_events is append-only: a withdrawal is a new event, never a deletion.'));
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** docs/114 §2: the one identity of the customer this event is about, or null for every identity. */
    public function identity(): BelongsTo
    {
        return $this->belongsTo(CustomerIdentity::class, 'identity_id');
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
