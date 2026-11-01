<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One exchange rate the owner set (ruling 1 Nov 2026). Append-only: the database refuses UPDATE and DELETE on `fx_rates`,
 * and so does this model, so a change is always a new row and the history stays whole. Written only by `FxRates::setRate()`.
 *
 * @property string $reference_rate
 * @property string $reserve_rate
 * @property string $safety_margin_percent
 */
class FxRate extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'base_currency', 'quote_currency', 'reference_rate', 'reserve_rate', 'safety_margin_percent',
        'source', 'as_of', 'valid_until', 'set_by_user_id', 'reason',
    ];

    protected function casts(): array
    {
        return [
            'reference_rate' => 'decimal:6',
            'reserve_rate' => 'decimal:6',
            'safety_margin_percent' => 'decimal:4',
            'as_of' => 'immutable_datetime',
            'valid_until' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * Store every instant in UTC. The column is `timestamptz`, but Eloquent writes a date as wall-clock text without its offset,
     * so a Dhaka-time value would otherwise land six hours off.
     */
    public function fromDateTime($value): ?string
    {
        return parent::fromDateTime($value instanceof DateTimeInterface ? CarbonImmutable::instance($value)->utc() : $value);
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('An exchange rate is never edited. Set a new rate instead.'));
        static::deleting(fn () => throw new LogicException('An exchange rate is never deleted. Set a new rate instead.'));
    }

    /** @return BelongsTo<User, $this> */
    public function setBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by_user_id');
    }
}
