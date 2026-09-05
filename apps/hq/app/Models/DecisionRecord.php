<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DecisionRecord extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = ['agent_key', 'user_id', 'context_type', 'context_id', 'proposal', 'final', 'delta', 'decision', 'reason', 'outcome', 'correlation_id'];

    protected function casts(): array
    {
        return ['proposal' => 'array', 'final' => 'array', 'delta' => 'array', 'outcome' => 'array', 'created_at' => 'datetime'];
    }
}
