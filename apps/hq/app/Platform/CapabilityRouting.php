<?php

namespace App\Platform;

use App\Models\PlatformAccount;

/**
 * Which connected account will serve a capability, or why none can (ADR-029).
 *
 * ⭐ **The rejected candidates are part of the answer, not diagnostics.** "Nobody can publish for
 * Bionic" is nearly useless; "the Page is connected but its publish permission is still pending App
 * Review, and the second Page is switched off" is something a person can act on this afternoon. An
 * agent that can say the second sentence is worth having; one that can only say the first is a
 * slightly slower way of reading an error log.
 */
final readonly class CapabilityRouting
{
    /**
     * @param  list<array{account: string, platform: string, reason: string}>  $rejected
     */
    private function __construct(
        public bool $available,
        public ?PlatformAccount $account = null,
        public ?string $platform = null,
        public ?string $reason = null,
        public array $rejected = [],
        public bool $ambiguous = false,
    ) {}

    public static function to(PlatformAccount $account, array $rejected = []): self
    {
        return new self(true, $account, $account->platform, null, $rejected);
    }

    public static function unavailable(string $reason, array $rejected = []): self
    {
        return new self(false, null, null, $reason, $rejected);
    }

    /**
     * Two or more accounts could serve this and nothing says which.
     *
     * ⛔ Refused rather than chosen, mirroring `AmbiguousCredential`. The precedent is worth keeping
     * exactly: an unordered `->first()` on two active credentials silently kept using the key that a
     * replacement was meant to replace, and the failure looked like success for as long as nobody
     * checked. Picking a Page to post as, or a number to call from, has the same shape and a louder
     * consequence - it reaches a person under the wrong identity.
     *
     * There is no rule ("newest wins", "lowest id wins") that is right often enough to be safe,
     * because the operator's intent is not in the rows. Naming the account is how intent gets in.
     */
    public static function ambiguous(string $capability, array $candidates, array $rejected = []): self
    {
        $names = implode(', ', array_map(static fn (PlatformAccount $a) => "{$a->platform}/{$a->account_ref}", $candidates));

        return new self(
            false, null, null,
            "[{$capability}] could be served by more than one connected account ({$names}) and nothing says which. "
            .'Name the account rather than letting HQ guess: acting as the wrong identity reaches a real person under the wrong name.',
            $rejected,
            true,
        );
    }

    /** Safe for an audit row, a tool result, or a model prompt: names and reasons, never a credential. */
    public function toArray(): array
    {
        return [
            'available' => $this->available,
            'platform' => $this->platform,
            'account_ref' => $this->account?->account_ref,
            'reason' => $this->reason,
            'ambiguous' => $this->ambiguous,
            'rejected' => $this->rejected,
        ];
    }
}
