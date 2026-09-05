<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomerMerge extends Model
{
    use HasFactory;

    protected $fillable = ['kept_id', 'absorbed_id', 'reason', 'merged_by', 'moved_identity_ids', 'undone_at'];

    protected function casts(): array
    {
        return ['moved_identity_ids' => 'array', 'undone_at' => 'datetime'];
    }
}
