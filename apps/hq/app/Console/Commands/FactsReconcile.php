<?php

namespace App\Console\Commands;

use App\Commerce\Support\CommerceRegistry;
use App\Gateway\OutcomeClass;
use App\Gateway\ToolGateway;
use App\Gateway\ToolRequest;
use Illuminate\Console\Command;
use Throwable;

/**
 * Pulls recent orders from each store through its adapter and inserts any fact a webhook missed
 * (docs/35 section G). Needs the store's admin token (list_orders capability). Read-only on the store.
 *
 * ⛔ docs/105 (S1, ruling docs/103 §3): this is `commerce.reconcile_facts` (an R3 write into `facts_orders`), so it
 * runs THROUGH THE TOOL GATEWAY as a console caller, exactly as the nightly scheduled job does: availability,
 * arguments, scope, policy and permission (the narrow system identity holds `hq.tools.commerce.read`), the
 * credential the tool declares opened by the gateway, a `tool_calls` row and the R3 audit. It used to call
 * `FactsReconciler` itself and open the credential by hand, which was the same effect with none of that.
 */
final class FactsReconcile extends Command
{
    /** The tool this command is (ReconcileShopFactsTool). */
    private const TOOL = 'commerce.reconcile_facts';

    protected $signature = 'hq:facts:reconcile {--store= : store slug; all adapter stores when omitted} {--hours=48}';

    protected $description = 'Reconcile facts_orders against the stores (catches lost webhooks), through the Tool Gateway.';

    public function handle(CommerceRegistry $registry, ToolGateway $gateway): int
    {
        $slugs = $this->option('store') ? [$this->option('store')] : $registry->storesWithAdapters();
        $hours = (int) $this->option('hours');
        $rc = self::SUCCESS;

        foreach ($slugs as $slug) {
            try {
                $outcome = $gateway->execute(ToolRequest::forConsole(self::TOOL, ['store_slug' => (string) $slug, 'hours' => $hours]));
            } catch (Throwable $e) {
                $this->error("[{$slug}] ".get_class($e).': '.$e->getMessage());
                $rc = self::FAILURE;

                continue;
            }

            if ($outcome->ok()) {
                $out = $outcome->output;
                $since = (string) ($out['since'] ?? now()->subHours($hours)->toIso8601String());
                $this->info("[{$slug}] checked ".($out['checked'] ?? 0)." orders since {$since}, inserted ".($out['inserted'] ?? 0).' missing'
                    .' (tool call #'.$outcome->toolCallId.')');

                continue;
            }

            // A store HQ holds no admin token for is skipped, as before: nothing to reconcile is not a failure.
            $reason = (string) ($outcome->error ?? $outcome->policyReason ?? 'refused by the tool gateway');
            if ($outcome->class === OutcomeClass::CredentialMissing || str_contains($reason, 'no admin token')) {
                $this->warn("[{$slug}] {$reason}, skipped (tool call #{$outcome->toolCallId})");

                continue;
            }

            $this->error("[{$slug}] {$outcome->status->value}: {$reason} (tool call #{$outcome->toolCallId})");
            $rc = self::FAILURE;
        }

        return $rc;
    }
}
