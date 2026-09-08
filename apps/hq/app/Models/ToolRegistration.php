<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A row in `tools`: the RUNTIME state of a tool that is already registered in code.
 *
 * Named ToolRegistration rather than Tool because App\Scheduling\Tools\Tool is the interface the
 * capability itself implements, and having two things called Tool one namespace apart is how a
 * reader ends up disabling the wrong one.
 *
 * ⚠ A row here can only narrow. The absence of a row means enabled; see ToolAvailability.
 */
class ToolRegistration extends Model
{
    protected $table = 'tools';

    protected $fillable = ['key', 'enabled', 'enabled_businesses', 'disabled_reason', 'disabled_by', 'disabled_at'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'enabled_businesses' => 'array',
            'disabled_at' => 'datetime',
        ];
    }

    public function disabledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disabled_by');
    }
}
