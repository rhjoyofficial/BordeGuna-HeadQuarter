<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** docs/143: the record of one batch review (docs/143 B). */
class ApprovalBatch extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['results' => 'array'];
    }
}
