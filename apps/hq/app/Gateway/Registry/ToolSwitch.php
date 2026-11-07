<?php

namespace App\Gateway\Registry;

use App\Models\ToolRegistration;
use App\Models\User;
use App\Support\Audit\Auditor;
use App\Support\Authorization\GroupWideAuthority;
use InvalidArgumentException;

/**
 * The audited runtime kill switch (docs/48 D.2, brief section 16).
 *
 * Disabling takes effect for every caller at once: the scheduler, the console, an operator screen
 * and, when they exist, agents. There is no per-caller exemption, because a kill switch with an
 * exemption is not a kill switch.
 *
 * Both directions are audited, and both require a reason (disabling always did; enabling since
 * docs/138 ruling 13). "Why is this off?" asked three weeks later is the question the record has to
 * answer, and an empty reason answers nothing.
 *
 * ⛔ docs/138 ruling 13: the switch acts on every business, so a named person needs group-wide
 * authority (`GroupWideAuthority`: `hq.tools.manage` and no business restriction). Nobody named is
 * accepted only from the terminal (the kill switch for when the application is what is broken) and is
 * recorded as the system.
 */
final class ToolSwitch
{
    public const PERMISSION = 'hq.tools.manage';

    public function __construct(
        private readonly ToolAvailability $availability,
        private readonly Auditor $auditor,
        private readonly GroupWideAuthority $authority,
    ) {}

    public function disable(string $key, string $reason, ?User $by = null): ToolRegistration
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Disabling a tool requires a reason.');
        }
        $this->authorize($by);
        $row = $this->row($key);
        $before = $row->exists ? $row->toArray() : null;

        $row->fill([
            'enabled' => false,
            'disabled_reason' => $reason,
            'disabled_by' => $by?->getKey(),
            'disabled_at' => now(),
        ])->save();

        $this->availability->forget();
        $this->auditor->record('tool.disabled', $row, before: $before, after: $row->toArray(), reason: $reason, actorUserId: $by?->getKey(),
            context: $this->authority->auditContext(self::PERMISSION, $by));

        return $row;
    }

    public function enable(string $key, ?string $reason = null, ?User $by = null): ToolRegistration
    {
        $this->authorize($by);
        $row = $this->row($key);
        if (trim((string) $reason) === '') {
            throw new InvalidArgumentException('Enabling a tool requires a reason: the switch acts on every business (docs/138 ruling 13).');
        }
        $before = $row->exists ? $row->toArray() : null;

        $row->fill([
            'enabled' => true,
            'disabled_reason' => null,
            'disabled_by' => null,
            'disabled_at' => null,
        ])->save();

        $this->availability->forget();
        $this->auditor->record('tool.enabled', $row, before: $before, after: $row->toArray(), reason: $reason, actorUserId: $by?->getKey(),
            context: $this->authority->auditContext(self::PERMISSION, $by));

        return $row;
    }

    /** docs/138 ruling 13: a named person needs group-wide authority; nobody named is the terminal only. */
    private function authorize(?User $by): void
    {
        if ($by === null && app()->runningInConsole()) {
            return;
        }
        $this->authority->assert($by, self::PERMISSION);
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
