<?php

namespace App\Platform;

/**
 * What a connected account can do today, and why anything else was withheld (ADR-022).
 *
 * The withheld reasons are not decoration. An agent that simply lacks a capability says nothing
 * useful; an agent that knows the Page's messaging permission is still in App Review can tell a
 * human that, which is the difference between a system that stalls silently and one that explains
 * itself. It is also what stops the same capability being promised to a customer.
 */
final readonly class CapabilityResolution
{
    /** @param  array<string, string>  $withheld  operation => why it is not available */
    public function __construct(
        public Capabilities $effective,
        public array $withheld = [],
        public array $quotas = [],
    ) {}

    public function supports(string $operation): bool
    {
        return $this->effective->supports($operation);
    }

    public function reasonFor(string $operation): ?string
    {
        return $this->withheld[$operation] ?? null;
    }

    /** @return list<string> */
    public function granted(): array
    {
        return $this->effective->granted();
    }

    public static function none(string $reason): self
    {
        return new self(new Capabilities, ['*' => $reason]);
    }

    public function toArray(): array
    {
        return ['granted' => $this->granted(), 'withheld' => $this->withheld, 'quotas' => $this->quotas];
    }
}
