<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** How far retention has drained one detail table: every Dhaka day up to `through_day`. */
class RetentionWatermark extends Model
{
    protected $primaryKey = 'source_table';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['source_table', 'through_day', 'retention_run_id'];

    protected function casts(): array
    {
        return ['through_day' => 'immutable_date'];
    }
}
