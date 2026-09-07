<?php

namespace App\Facts;

use App\Commerce\Support\CommerceRegistry;
use App\Models\FactsOrder;
use App\Models\Store;
use Carbon\CarbonImmutable;

/**
 * The safety net under the order webhooks: pull each store's recent orders through its adapter and
 * upsert them, so an order whose webhook was lost, delayed or dropped still becomes a fact.
 *
 * This lives in a service rather than in the console command because two callers need it and two
 * copies would drift: `hq:facts:reconcile` (a human, after an incident) and ReconcileShopFactsTool
 * (the durable nightly job). The symptom of a drift here would be a nightly run that quietly
 * reconciles a slightly different set of orders than the command a human just watched succeed.
 *
 * ⚠ Read-only on the store. It calls the admin *list* endpoint, never the admin *show* endpoint:
 * `show` stamps `admin_viewed_at` on the shop's order, which would mean HQ's own safety net silently
 * marked real orders as "seen" in the shop's workspace. The cost of that restraint is that the list
 * endpoint does not eager-load order items, so reconciliation can repair an order's totals, channel,
 * district and status but never its line-level detail. Lines come from the webhook payload, and
 * replaying a stored event is what repairs them - see `hq:events:replay`.
 */
final class FactsReconciler
{
    public function __construct(
        private readonly CommerceRegistry $registry,
        private readonly FactsOrderUpserter $facts,
    ) {}

    /**
     * @return array{store: string, checked: int, inserted: int, skipped: bool, reason: ?string}
     */
    public function reconcileStore(Store $store, CarbonImmutable $since, int $limit = 200): array
    {
        $adapter = $this->registry->for($store);
        if (! $adapter->capabilities()->supports('list_orders')) {
            return ['store' => $store->slug, 'checked' => 0, 'inserted' => 0, 'skipped' => true, 'reason' => 'no admin token: cannot list orders'];
        }

        $orders = $adapter->listRecentOrders(since: $since, limit: $limit);
        $inserted = 0;
        foreach ($orders as $order) {
            $known = FactsOrder::where('store_id', $store->id)->where('order_ref', $order->ref)->exists();
            $this->facts->upsertFromShopPayload($store, $order->raw ?: [
                'order_number' => $order->ref, 'order_status' => $order->status, 'grand_total' => $order->grandTotal,
                'placed_at' => $order->placedAt, 'customer_phone' => $order->customerPhone, 'customer_name' => $order->customerName,
            ]);
            $known || $inserted++;
        }

        return ['store' => $store->slug, 'checked' => count($orders), 'inserted' => $inserted, 'skipped' => false, 'reason' => null];
    }
}
