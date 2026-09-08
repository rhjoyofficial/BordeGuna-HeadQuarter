<?php

namespace App\Console\Commands;

use App\Gateway\Registry\ToolAvailability;
use App\Gateway\Registry\ToolRegistrySync;
use App\Gateway\ToolGateway;
use Illuminate\Console\Command;

/**
 * Reconciles the configured tool classes with the runtime `tools` rows, and lists what the gateway
 * currently holds.
 *
 * Run as part of a deploy. It is deliberately not load-bearing: a missing row already means
 * enabled, so forgetting to run this changes nothing about what works.
 */
final class ToolsSync extends Command
{
    protected $signature = 'hq:tools:sync {--list : Only list, change nothing}';

    protected $description = 'Reconcile registered tools with the runtime tools table, and show the registry';

    public function handle(ToolRegistrySync $sync, ToolAvailability $availability, ToolGateway $gateway): int
    {
        if (! $this->option('list')) {
            $result = $sync->sync();
            $this->line('created: '.(count($result['created']) ?: 0).'  existing: '.count($result['existing']));
            foreach ($result['created'] as $key) {
                $this->line("  + {$key}");
            }
            foreach ($result['orphaned'] as $key) {
                $this->warn("  ! {$key} has a row but is no longer registered in code (kept, not deleted)");
            }
        }

        $state = $availability->overview();
        $rows = [];
        foreach ($gateway->descriptors() as $key => $descriptor) {
            $rows[] = [
                $key,
                $descriptor->version,
                $descriptor->riskTier->value,
                $descriptor->readWrite->value,
                $descriptor->permanentlyHumanOnly ? 'NEVER' : ($descriptor->agentExecutable ? 'yes' : 'not yet'),
                ($state[$key]['enabled'] ?? true) ? 'enabled' : 'DISABLED',
                $descriptor->permission,
            ];
        }
        $this->table(['tool', 'ver', 'tier', 'rw', 'agent', 'runtime', 'permission'], $rows);

        return self::SUCCESS;
    }
}
