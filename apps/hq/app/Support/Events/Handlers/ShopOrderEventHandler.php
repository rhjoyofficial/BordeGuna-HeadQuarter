<?php

namespace App\Support\Events\Handlers;

use App\Facts\FactsOrderUpserter;
use App\Models\DomainEvent;
use App\Models\Store;
use App\Support\Events\EventHandler;
use Carbon\CarbonImmutable;

/**
 * Handles every shop order-family event. Idempotent: upserts keyed on (store, order_ref); a
 * status event for an unknown order throws UnknownOrderException so the drain retries later.
 *
 * `order.updated` exists because an order edited on the shop leaves HQ holding line data that is
 * positively wrong, and reconciliation cannot repair it: the admin list endpoint carries no items,
 * and the endpoint that does carries a side effect on the shop's own order. A full-state event is
 * the only way for HQ to hold current lines without writing to the source system.
 */
final class ShopOrderEventHandler implements EventHandler
{
    public function __construct(private readonly FactsOrderUpserter $facts) {}

    public function handle(DomainEvent $event): void
    {
        $payload = $event->payload;
        $store = Store::where('slug', $payload['_store'] ?? '')->firstOrFail();

        // `order.created` and `order.updated` are both FULL-STATE: the shop sends the order as it
        // stands, so the newer of the two wins and the older is refused. That ordering decision
        // needs the shop's own timestamp, not HQ's clock - see FactsOrderUpserter.
        $occurredAt = $event->occurred_at ? CarbonImmutable::parse($event->occurred_at) : null;

        match ($event->type) {
            'order.created', 'order.updated' => $this->facts->upsertFromShopPayload($store, is_array($payload['order'] ?? null) ? $payload['order'] : $payload, $event->event_id, $occurredAt),
            'order.status_changed', 'order.payment_updated', 'shipment.status_updated', 'payment.completed', 'shipment.delivered' => $this->facts->applyStatusEvent($store, $event->type, $payload, $event->event_id),
            default => null,
        };
    }
}
