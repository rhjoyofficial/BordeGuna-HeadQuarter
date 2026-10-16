<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * The lineage of one repair split (docs/126, ruling docs/120 A3 points 5 and 7): rows of one business that hung
 * under a customer of another business, and where they went. The original `customer_merges` row (when a merge
 * explains the rows) is never modified; this row names it. Append-only: the database refuses UPDATE and DELETE,
 * the model refuses them too.
 *
 * `moved` lists every row id by table; `withheld` lists what was deliberately not moved: business-less memories
 * held for a person's assignment (point 8) and consent rows a person must re-record in the right scope (point 9).
 */
class CustomerMergeReversal extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['moved' => 'array', 'withheld' => 'array', 'to_customer_created' => 'boolean', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('customer_merge_reversals is append-only: a correction is a new reversal row.'));
        static::deleting(fn () => throw new LogicException('customer_merge_reversals is append-only: lineage is never removed.'));
    }

    public function repair(): BelongsTo
    {
        return $this->belongsTo(CustomerIdentityRepair::class, 'repair_id');
    }

    public function fromCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'from_customer_id');
    }

    public function toCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'to_customer_id');
    }
}
