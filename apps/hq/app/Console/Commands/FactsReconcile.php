<?php

namespace App\Console\Commands;

use App\Commerce\Support\CommerceRegistry;
use App\Facts\FactsOrderUpserter;
use App\Models\FactsOrder;
use App\Models\Store;
use Illuminate\Console\Command;
use Throwable;

/**
 * Pulls recent orders from each store through its adapter and inserts any fact a webhook missed
 * (docs/35 section G). Needs the store's admin token (list_orders capability). Read-only on the store.
 */
final class FactsReconcile extends Command
{
    protected $signature = 'hq:facts:reconcile {--store= : store slug; all adapter stores when omitted} {--hours=48}';

    protected $description = 'Reconcile facts_orders against the stores (catches lost webhooks).';

    public function handle(CommerceRegistry $registry, FactsOrderUpserter $facts): int
    {
        $slugs = $this->option('store') ? [$this->option('store')] : $registry->storesWithAdapters();
        $since = now()->subHours((int) $this->option('hours'));
        $rc = self::SUCCESS;
        foreach ($slugs as $slug) {
            $store = Store::where('slug', $slug)->firstOrFail();
            try {
                $adapter = $registry->for($store);
                if (! $adapter->capabilities()->supports('list_orders')) {
                    $this->warn("[{$slug}] no admin token: cannot list orders, skipped");

                    continue;
                }
                $orders = $adapter->listRecentOrders(since: $since, limit: 200);
                $inserted = 0;
                foreach ($orders as $order) {
                    $known = FactsOrder::where('store_id', $store->id)->where('order_ref', $order->ref)->exists();
                    $facts->upsertFromShopPayload($store, $order->raw ?: ['order_number' => $order->ref, 'order_status' => $order->status, 'grand_total' => $order->grandTotal, 'placed_at' => $order->placedAt, 'customer_phone' => $order->customerPhone, 'customer_name' => $order->customerName]);
                    $known || $inserted++;
                }
                $this->info("[{$slug}] checked ".count($orders)." orders since {$since->toDateTimeString()}, inserted {$inserted} missing");
            } catch (Throwable $e) {
                $this->error("[{$slug}] ".get_class($e).': '.$e->getMessage());
                $rc = self::FAILURE;
            }
        }

        return $rc;
    }
}
