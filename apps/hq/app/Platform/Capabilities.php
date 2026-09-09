<?php

namespace App\Platform;

/**
 * What a platform or a connected account can do, as a flag map (ADR-022).
 *
 * Promoted out of `App\Commerce\Dto\Capabilities`, which proved the pattern: a flag map with
 * `supports()`, declared by each adapter, gating real behaviour (`FactsReconciler` really does
 * refuse to reconcile when `list_orders` is false). The commerce version stays where it is and
 * keeps working; this is the shared, account-aware form the rest of the platform will use.
 *
 * Flags are booleans and nothing else. A tri-state flag meaning "maybe, if approved" was considered
 * and rejected: the whole point of ADR-022 is that a capability which is not usable RIGHT NOW is
 * absent, not decorated.
 */
final readonly class Capabilities
{
    /** @param  array<string, bool>  $flags */
    public function __construct(public array $flags = []) {}

    public function supports(string $operation): bool
    {
        return (bool) ($this->flags[$operation] ?? false);
    }

    /** @return list<string> only the operations that are actually available */
    public function granted(): array
    {
        return array_values(array_keys(array_filter($this->flags, static fn ($v) => (bool) $v)));
    }

    /** @param  list<string>  $operations */
    public static function of(array $operations): self
    {
        return new self(array_fill_keys($operations, true));
    }

    public function intersect(self $other): self
    {
        $out = [];
        foreach ($this->granted() as $operation) {
            $out[$operation] = $other->supports($operation);
        }

        return new self($out);
    }

    /** @param  list<string>  $operations */
    public function without(array $operations): self
    {
        $out = $this->flags;
        foreach ($operations as $operation) {
            $out[$operation] = false;
        }

        return new self($out);
    }

    public function isEmpty(): bool
    {
        return $this->granted() === [];
    }
}
