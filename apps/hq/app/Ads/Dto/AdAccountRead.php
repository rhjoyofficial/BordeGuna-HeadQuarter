<?php

namespace App\Ads\Dto;

/**
 * One ad account as its platform describes it, read-only.
 *
 * `spendCap` is the OWNER's ceiling (Meta's ad account spending limit: "When the amount is reached,
 * all delivery stops", Ad Account reference, read 22 September 2026). HQ reads it and never writes
 * it (ADR-050 clarification 3). Null means no limit is set, which is NOT a limit of zero and NOT
 * permission to spend without one.
 */
final readonly class AdAccountRead
{
    /** Meta's `account_status` codes, from the Ad Account reference (read 22 September 2026). */
    public const STATUSES = [
        1 => 'active', 2 => 'disabled', 3 => 'unsettled', 7 => 'pending_risk_review', 8 => 'pending_settlement',
        9 => 'in_grace_period', 100 => 'pending_closure', 101 => 'closed', 201 => 'any_active', 202 => 'any_closed',
    ];

    public function __construct(
        public string $accountRef,
        public ?string $name,
        public string $currency,
        public ?string $timezone,
        public string $status,
        /** Decimal string in the major unit, or null when the owner set no limit. */
        public ?string $spendCap,
        /** Decimal string in the major unit: spent "with respect to spend_cap", or in total without one. */
        public ?string $amountSpent,
        /** The raw basic-unit figures as the platform sent them, kept for reconciliation. */
        public ?string $spendCapMinor,
        public ?string $amountSpentMinor,
        public ?int $disableReason = null,
    ) {}

    public static function statusLabel(null|int|string $code): string
    {
        return self::STATUSES[(int) $code] ?? 'unknown';
    }

    /** What remains under the owner's limit, or null when no limit is set or the figures are missing. */
    public function remainingUnderCap(): ?string
    {
        if ($this->spendCapMinor === null || $this->amountSpentMinor === null) {
            return null;
        }

        return AdMoney::fromMinor((string) ((int) $this->spendCapMinor - (int) $this->amountSpentMinor), $this->currency);
    }

    public function toArray(): array
    {
        return [
            'account_ref' => $this->accountRef,
            'name' => $this->name,
            'currency' => $this->currency,
            'timezone' => $this->timezone,
            'status' => $this->status,
            'spend_cap' => $this->spendCap,
            'amount_spent' => $this->amountSpent,
            'remaining_under_cap' => $this->remainingUnderCap(),
            'disable_reason' => $this->disableReason,
        ];
    }
}
