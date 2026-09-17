<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ConnectorCall extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = ['connector', 'operation', 'idempotency_key', 'correlation_id', 'request_ref', 'response_status', 'provider_ref', 'duration_ms', 'status', 'error'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
