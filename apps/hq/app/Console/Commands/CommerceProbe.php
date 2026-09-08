<?php

namespace App\Console\Commands;

use App\Commerce\Support\CommerceRegistry;
use App\Gateway\Credentials\CredentialVault;
use Illuminate\Console\Command;
use Throwable;

/**
 * Read-only liveness check of a store through its adapter: public catalogue, assistant key,
 * admin token. Never writes anything to the store.
 */
final class CommerceProbe extends Command
{
    protected $signature = 'hq:commerce:probe {store? : store slug; all adapter-backed stores when omitted} {--product= : also fetch one product by slug}';

    protected $description = 'Probe a store adapter (read-only): credentials, endpoints, capabilities.';

    public function handle(CommerceRegistry $registry, CredentialVault $vault): int
    {
        $slugs = $this->argument('store') ? [$this->argument('store')] : $registry->storesWithAdapters();
        $rc = self::SUCCESS;
        foreach ($slugs as $slug) {
            try {
                // Diagnostic path: open the store's credentials explicitly for this block.
                $vault->openForAccounts($registry->credentialPairsFor($slug), function () use ($registry, $slug) {
                    $adapter = $registry->for($slug);
                    $this->line("[{$slug}] ".json_encode($adapter->probe()));
                    $this->line("[{$slug}] capabilities: ".json_encode(array_filter($adapter->capabilities()->flags, fn ($v) => $v === true)));
                    if ($this->option('product')) {
                        $p = $adapter->getProduct($this->option('product'));
                        $this->line("[{$slug}] product {$p->slug}: active=".var_export($p->isActive, true).' status='.($p->status ?? 'null').' variants='.count($p->variants));
                        foreach ($p->variants as $v) {
                            $a = $adapter->getAvailability($v->id);
                            $this->line("   variant {$v->id} {$v->title} price {$v->finalPrice} available_stock ".var_export($v->availableStock, true).' -> orderable='.var_export($a->orderable, true)." reason={$a->reason}");
                        }
                    }
                });
            } catch (Throwable $e) {
                $this->error("[{$slug}] ".get_class($e).': '.$e->getMessage());
                $rc = self::FAILURE;
            }
        }

        return $rc;
    }
}
