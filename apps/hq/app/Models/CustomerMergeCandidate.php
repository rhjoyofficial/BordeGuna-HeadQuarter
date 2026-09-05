<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerMergeCandidate extends Model
{
    use HasFactory;

    protected $fillable = ['customer_id', 'candidate_id', 'confidence', 'reason', 'status', 'decided_by', 'decided_at'];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime', 'confidence' => 'decimal:2'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'candidate_id');
    }
}
