<?php

namespace App\Console\Commands;

use App\Identity\IdentityResolver;
use App\Models\Customer;
use App\Models\FactsOrder;
use App\Models\Store;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds the customer read model from facts_orders (ADR-007: read models are rebuildable).
 * Keeps merges: existing customers are not deleted; identities are re-linked and counts recomputed.
 */
final class CustomersRebuild extends Command
{
    protected $signature = 'hq:customers:rebuild {--business= : business id; all when omitted} {--recount-only : only recompute orders_count and lifetime_value}';

    protected $description = 'Rebuild customer profiles and identities from facts_orders.';

    public function handle(IdentityResolver $resolver): int
    {
        $query = FactsOrder::query()->when($this->option('business'), fn ($q) => $q->where('business_id', (int) $this->option('business')))->orderBy('placed_at');
        $stores = Store::all()->keyBy('id');
        $n = 0;
        if (! $this->option('recount-only')) {
            $query->chunkById(200, function ($facts) use ($resolver, $stores, &$n) {
                foreach ($facts as $f) {
                    $store = $stores[$f->store_id];
                    $customer = $resolver->resolve($store, [
                        'name' => null, 'phone' => $f->customer_phone_canonical, 'district' => $f->district, 'order_ref' => $f->order_ref, 'placed_at' => $f->placed_at?->toIso8601String(),
                    ]);
                    if ($f->customer_id !== $customer->id) {
                        $f->forceFill(['customer_id' => $customer->id])->save();
                    }
                    $n++;
                }
            });
        }
        foreach (Customer::whereNull('merged_into_id')->cursor() as $c) {
            $c->forceFill([
                'orders_count' => DB::table('facts_orders')->where('customer_id', $c->id)->count(),
                'lifetime_value' => DB::table('facts_orders')->where('customer_id', $c->id)->where('status', '!=', 'cancelled')->sum('gross'),
                'last_seen_at' => DB::table('facts_orders')->where('customer_id', $c->id)->max('placed_at') ?? $c->last_seen_at,
            ])->save();
        }
        $this->info("rebuilt from {$n} order facts; ".Customer::whereNull('merged_into_id')->count().' customers');

        return self::SUCCESS;
    }
}
