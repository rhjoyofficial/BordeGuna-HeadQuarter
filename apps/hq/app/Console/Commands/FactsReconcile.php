<?php

namespace App\Console\Commands;

use App\Commerce\Support\CommerceRegistry;
use App\Facts\FactsReconciler;
use App\Gateway\Credentials\CredentialVault;
use App\Models\Store;
use Illuminate\Console\Command;
use Throwable;

/**
 * Pulls recent orders from each store through its adapter and inserts any fact a webhook missed
 * (docs/35 section G). Needs the store's admin token (list_orders capability). Read-only on the store.
 *
 * The work itself lives in FactsReconciler, because the nightly scheduled job runs the same thing.
 */
final class FactsReconcile extends Command
{
    protected $signature = 'hq:facts:reconcile {--store= : store slug; all adapter stores when omitted} {--hours=48}';

    protected $description = 'Reconcile facts_orders against the stores (catches lost webhooks).';

    public function handle(CommerceRegistry $registry, FactsReconciler $reconciler, CredentialVault $vault): int
    {
        $slugs = $this->option('store') ? [$this->option('store')] : $registry->storesWithAdapters();
        $since = now()->subHours((int) $this->option('hours'));
        $rc = self::SUCCESS;
        foreach ($slugs as $slug) {
            $store = Store::where('slug', $slug)->firstOrFail();
            try {
                // Diagnostic path: the scheduled job goes through the gateway, which opens
                // the credential for it. A hand-run command opens it explicitly.
                $result = $vault->openForAccounts(
                    $registry->credentialPairsFor($slug),
                    fn () => $reconciler->reconcileStore($store, $since->toImmutable()),
                );
                if ($result['skipped']) {
                    $this->warn("[{$slug}] {$result['reason']}, skipped");

                    continue;
                }
                $this->info("[{$slug}] checked {$result['checked']} orders since {$since->toDateTimeString()}, inserted {$result['inserted']} missing");
            } catch (Throwable $e) {
                $this->error("[{$slug}] ".get_class($e).': '.$e->getMessage());
                $rc = self::FAILURE;
            }
        }

        return $rc;
    }
}
