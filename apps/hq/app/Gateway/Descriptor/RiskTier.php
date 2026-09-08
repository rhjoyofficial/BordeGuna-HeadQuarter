<?php

namespace App\Gateway\Descriptor;

/**
 * How much damage a tool could do, as a property of what its code does (ADR-017).
 *
 * This is INTRINSIC and static. It changes only when the code changes, and it is declared on the
 * tool rather than in configuration precisely so that no config file can make a refund look like a
 * read. The other axis - who may run this particular call, right now - is the ActionClass, and it
 * is derived by policy per call.
 *
 * The rule for classifying a tool: take the HIGHEST tier of any effect it has. Recording the call
 * itself (tool_calls, connector_calls, audit_logs) is not an effect for this purpose, because every
 * call makes those and counting them would flatten the whole scale to one value.
 */
enum RiskTier: string
{
    /** Pure computation. No I/O beyond the process. */
    case R0 = 'r0';

    /** Internal read: HQ's own read models and control tables. */
    case R1 = 'r1';

    /** External read: a system of record or a provider. Consumes quota, may cost money. */
    case R2 = 'r2';

    /** Internal write: HQ state only, reversible in HQ, invisible outside HQ. */
    case R3 = 'r3';

    /** External side effect that can be corrected without loss: a draft, a pending order, a ticket. */
    case R4 = 'r4';

    /** Material or irreversible: money, a customer-visible message, a publication, a price, a deletion. */
    case R5 = 'r5';

    /**
     * The baseline outcome for the tier, before any tool policy or guardrail rule is consulted.
     *
     * Joy's V.1 decision (8 Sep 2026): every R4 and R5 action requires human approval in Phase 2,
     * with no value threshold. Nothing at those tiers is enabled yet, so this costs nothing now and
     * sets the safe default before it is expensive to change.
     */
    public function baseline(): ActionClass
    {
        return match ($this) {
            self::R0, self::R1, self::R2, self::R3 => ActionClass::Auto,
            self::R4, self::R5 => ActionClass::ApprovalRequired,
        };
    }

    public function writesOutsideHq(): bool
    {
        return $this === self::R4 || $this === self::R5;
    }

    public function atLeast(self $other): bool
    {
        return $this->rank() >= $other->rank();
    }

    public function rank(): int
    {
        return (int) substr($this->value, 1);
    }

    public function label(): string
    {
        return match ($this) {
            self::R0 => 'R0 pure computation',
            self::R1 => 'R1 internal read',
            self::R2 => 'R2 external read',
            self::R3 => 'R3 internal write',
            self::R4 => 'R4 external side effect, recoverable',
            self::R5 => 'R5 material or irreversible',
        };
    }
}
