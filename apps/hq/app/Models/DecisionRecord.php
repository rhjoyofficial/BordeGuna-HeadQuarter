<?php

namespace App\Models;

use App\Decisions\DecisionVocabulary;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class DecisionRecord extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = ['agent_key', 'user_id', 'business_id', 'context_type', 'context_id', 'proposal', 'final', 'delta', 'decision', 'reason', 'outcome', 'correlation_id'];

    protected static function booted(): void
    {
        // Ruling docs/120 item 20: one vocabulary going forward. Legacy `send`/`discard` rows stay as written and
        // are read through DecisionVocabulary::normalize(); a new row may not add to them.
        static::creating(function (DecisionRecord $record) {
            if (! DecisionVocabulary::isCurrent((string) $record->decision)) {
                throw new InvalidArgumentException('A decision record stores approved, edited or rejected; ['.$record->decision.'] is not one of them.');
            }
        });
    }

    protected function casts(): array
    {
        return ['proposal' => 'array', 'final' => 'array', 'delta' => 'array', 'outcome' => 'array', 'created_at' => 'datetime'];
    }
}
