<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FactsOrder extends Model
{
    use HasFactory;

    protected $fillable = ['business_id', 'store_id', 'order_ref', 'source', 'status', 'payment_status', 'payment_method', 'subtotal', 'discount_total', 'shipping_cost', 'gross', 'customer_id', 'customer_phone_canonical', 'district', 'thana', 'placed_at', 'confirmed_at', 'delivered_at', 'cancelled_at', 'campaign_ref', 'lines', 'last_event_id', 'last_event_at', 'synced_at',
        // docs/90 A.5: the evidence behind an order HQ read back from the ERP (null on every shop fact).
        'source_system', 'provenance', 'erp_order_id', 'erp_order_number', 'erp_status', 'erp_approval_status', 'hq_key', 'correlation_id', 'read_back_digest', 'read_back_at'];

    protected function casts(): array
    {
        return ['placed_at' => 'datetime', 'confirmed_at' => 'datetime', 'delivered_at' => 'datetime', 'cancelled_at' => 'datetime', 'synced_at' => 'datetime', 'last_event_at' => 'datetime', 'read_back_at' => 'datetime', 'lines' => 'array', 'subtotal' => 'decimal:2', 'discount_total' => 'decimal:2', 'shipping_cost' => 'decimal:2', 'gross' => 'decimal:2'];
    }

    protected $table = 'facts_orders';

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
