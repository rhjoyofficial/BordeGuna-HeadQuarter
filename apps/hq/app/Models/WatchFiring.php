<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** docs/143: one firing of an event watch. */
class WatchFiring extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [];
    }
}
