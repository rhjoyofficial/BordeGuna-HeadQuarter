<?php

namespace App\Gateway\Registry;

use App\Models\ToolRegistration;
use App\Models\User;
use App\Support\Audit\Auditor;
use InvalidArgumentException;

/**
 * The audited runtime kill switch (docs/48 D.2, brief section 16).
 *
 * Disabling takes effect for every caller at once: the scheduler, the console, an operator screen
 * and, when they exist, agents. There is no per-caller exemption, because a kill switch with an
 * exemption is not a kill switch.
 *
 * Both directions are audited, and disabling requires a reason. "Why is this off?" asked three
 * weeks later is the question the record has to answer, and an empty reason answers nothing.
 */
final class ToolSwitch
{
    public function __construct(
        private readonly ToolAvailability $availability,
        private readonly Auditor $auditor,
    ) {}

    public function disable(string $key, string $reason, ?User $by = null): ToolRegistration
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Disabling a tool requires a reason.');
        }
        $row = $this->row($key);
        $before = $row->exists ? $row->toArray() : null;

        $row->fill([
            'enabled' => false,
            'disabled_reason' => $reason,
            'disabled_by' => $by?->getKey(),
            'disabled_at' => now(),
        ])->save();

        $this->availability->forget();
        $this->auditor->record('tool.disabled', $row, before: $before, after: $row->toArray(), reason: $reason, actorUserId: $by?->getKey());

        return $row;
    }

    public function enable(string $key, ?string $reason = null, ?User $by = null): ToolRegistration
    {
        $row = $this->row($key);
        $before = $row->exists ? $row->toArray() : null;

        $row->fill([
            'enabled' => true,
            'disabled_reason' => null,
            'disabled_by' => null,
            'disabled_at' => null,
        ])->save();

        $this->availability->forget();
        $this->auditor->record('tool.enabled', $row, before: $before, after: $row->toArray(), reason: $reason, actorUserId: $by?->getKey());

        return $row;
    }

    /**
     * A tool must exist in code before its runtime state can be touched. Refusing here is what
     * stops the table becoming a place where capabilities are invented.
     */
    private function row(string $key): ToolRegistration
    {
        if (! $this->availability->registered($key)) {
            throw new InvalidArgumentException("Unknown tool [{$key}]: only a tool registered in config/hq.php can be switched.");
        }

        return ToolRegistration::query()->firstOrNew(['key' => $key]);
    }
}
