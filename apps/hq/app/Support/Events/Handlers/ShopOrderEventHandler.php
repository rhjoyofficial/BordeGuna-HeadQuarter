<?php

namespace App\Support\Events\Handlers;

use App\Facts\FactsOrderUpserter;
use App\Models\DomainEvent;
use App\Models\Store;
use App\Support\Events\EventHandler;

/**
 * Handles every shop order-family event. Idempotent: upserts keyed on (store, order_ref); a
 * status event for an unknown order throws UnknownOrderException so the drain retries later.
 */
final class ShopOrderEventHandler implements EventHandler
{
    public function __construct(private readonly FactsOrderUpserter $facts) {}

    public function handle(DomainEvent $event): void
    {
        $payload = $event->payload;
        $store = Store::where('slug', $payload['_store'] ?? '')->firstOrFail();

        match ($event->type) {
            'order.created' => $this->facts->upsertFromShopPayload($store, is_array($payload['order'] ?? null) ? $payload['order'] : $payload, $event->event_id),
            'order.status_changed', 'order.payment_updated', 'shipment.status_updated', 'payment.completed', 'shipment.delivered' => $this->facts->applyStatusEvent($store, $event->type, $payload, $event->event_id),
            default => null,
        };
    }
}
