<?php

namespace App\Console\Commands;

use App\Identity\CrossBusinessIdentity;
use App\Identity\IdentityQuarantined;
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
        $businessId = $this->option('business') ? (int) $this->option('business') : null;
        $query = FactsOrder::query()->when($businessId, fn ($q) => $q->where('business_id', $businessId))->orderBy('placed_at');
        $stores = Store::all()->keyBy('id');
        $n = 0;
        $held = 0;
        $refused = 0;
        if (! $this->option('recount-only')) {
            $query->chunkById(200, function ($facts) use ($resolver, $stores, &$n, &$held, &$refused) {
                foreach ($facts as $f) {
                    $store = $stores[$f->store_id];
                    // ⛔ docs/122 (A4): a copy hanging under another business's customer is the evidence-backed
                    // repair's (docs/120 A3), never this command's. It is counted and left exactly as it is.
                    if ($f->customer_id !== null && (int) Customer::query()->whereKey($f->customer_id)->value('business_id') !== (int) $f->business_id) {
                        $held++;

                        continue;
                    }
                    try {
                        $customer = $resolver->resolve($store, [
                            'name' => null, 'phone' => $f->customer_phone_canonical, 'district' => $f->district, 'order_ref' => $f->order_ref, 'placed_at' => $f->placed_at?->toIso8601String(),
                        ]);
                    } catch (IdentityQuarantined) {
                        $refused++;

                        continue;
                    }
                    if ((int) $customer->business_id !== (int) $f->business_id) {
                        throw CrossBusinessIdentity::customer((int) $customer->business_id, (int) $f->business_id);
                    }
                    if ($f->customer_id !== $customer->id) {
                        $f->forceFill(['customer_id' => $customer->id])->save();
                    }
                    $n++;
                }
            });
        }
        // Every figure from the customer's own business's copies only (docs/117 Q3, docs/120 item 37).
        foreach (Customer::whereNull('merged_into_id')->when($businessId, fn ($q) => $q->where('business_id', $businessId))->cursor() as $c) {
            $own = fn () => DB::table('facts_orders')->where('customer_id', $c->id)->where('business_id', $c->business_id);
            $c->forceFill([
                'orders_count' => $own()->count(),
                'lifetime_value' => $own()->where('status', '!=', 'cancelled')->sum('gross'),
                'last_seen_at' => $own()->max('placed_at') ?? $c->last_seen_at,
            ])->save();
        }
        $this->info("rebuilt from {$n} order facts; ".Customer::whereNull('merged_into_id')->count().' customers');
        if ($held > 0 || $refused > 0) {
            $this->warn("{$held} order fact(s) under another business's customer left for the repair; {$refused} refused on a quarantined identity.");
        }

        return self::SUCCESS;
    }
}
