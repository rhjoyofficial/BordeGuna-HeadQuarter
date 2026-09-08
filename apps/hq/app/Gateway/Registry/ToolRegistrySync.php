<?php

namespace App\Gateway\Registry;

use App\Models\ToolRegistration;
use App\Support\Audit\Auditor;
use App\Tools\ToolRegistry;

/**
 * Reconciles the configured tool classes with the runtime `tools` rows.
 *
 * Safe and deterministic, in the specific sense that matters here: it is NOT load-bearing. Because
 * a missing row already means enabled (see ToolAvailability), running this changes no behaviour at
 * all. It materialises rows so the registry screen has something to list and so an operator has a
 * row to switch off, and it reports orphans. Running it twice does nothing the second time.
 *
 * It never deletes, never disables and never re-enables. Turning a tool off is a decision a human
 * makes and this is not a human.
 */
final class ToolRegistrySync
{
    public function __construct(
        private readonly ToolRegistry $registry,
        private readonly ToolAvailability $availability,
        private readonly Auditor $auditor,
    ) {}

    /** @return array{created: list<string>, existing: list<string>, orphaned: list<string>} */
    public function sync(bool $audit = true): array
    {
        $created = [];
        $existing = [];

        foreach ($this->registry->keys() as $key) {
            $row = ToolRegistration::query()->firstOrNew(['key' => $key]);
            if ($row->exists) {
                $existing[] = $key;

                continue;
            }
            // A tool arrives enabled: putting the class in config('hq.tools') and deploying it IS
            // the deliberate act. The table exists to record a later decision to stop it.
            $row->enabled = true;
            $row->save();
            $created[] = $key;
        }

        $this->availability->forget();
        $orphaned = $this->availability->orphans();

        if ($audit && ($created !== [] || $orphaned !== [])) {
            $this->auditor->recordSystem(
                action: 'tools.registry_synced',
                target: 'tools',
                after: ['created' => $created, 'orphaned' => $orphaned, 'registered' => count($this->registry->keys())],
                reason: $orphaned === [] ? null : 'rows exist for tools no longer registered in code; kept, not deleted',
            );
        }

        return ['created' => $created, 'existing' => $existing, 'orphaned' => $orphaned];
    }
}
