<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FeatureFlag extends Model
{
    use HasFactory;

    protected $fillable = ['key', 'description', 'environment', 'enabled', 'rollout'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'rollout' => 'array'];
    }
}
