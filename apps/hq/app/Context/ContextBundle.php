<?php

namespace App\Context;

use App\Gateway\Descriptor\AutonomyLevel;

/**
 * Everything assembled for one model call, with the provenance of each piece preserved (ADR-024).
 *
 * ⛔ **The one rule that makes this more than bookkeeping**: a bundle containing untrusted content
 * caps autonomy. Reading a customer's message may inform a proposal; it may never be the reason an
 * action became automatic. `AutonomyResolver` reads `ceiling()` as one of its constraints, and
 * because autonomy combines by MINIMUM, the cap binds no matter what any grant says.
 *
 * Assembly is the only way context reaches a model, so a block cannot arrive unlabelled: there is
 * no constructor here that takes a bare string.
 */
final readonly class ContextBundle
{
    /** @param  list<ContextBlock>  $blocks */
    public function __construct(public array $blocks = []) {}

    public function with(ContextBlock ...$blocks): self
    {
        return new self([...$this->blocks, ...$blocks]);
    }

    public function containsUntrusted(): bool
    {
        foreach ($this->blocks as $block) {
            if (! $block->isTrusted()) {
                return true;
            }
        }

        return false;
    }

    /** @return list<ContextBlock> */
    public function untrusted(): array
    {
        return array_values(array_filter($this->blocks, static fn (ContextBlock $b) => ! $b->isTrusted()));
    }

    /**
     * The highest autonomy this context may support.
     *
     * A1 (propose, a human executes) when anything untrusted is present, unbounded otherwise. Not
     * A0: reading a customer message and proposing a reply is the entire point of customer AI, and
     * refusing to read is not the control. Bounding what reading it can CAUSE is the control.
     */
    public function ceiling(): ?AutonomyLevel
    {
        return $this->containsUntrusted() ? AutonomyLevel::A1 : null;
    }

    /** @return list<array<string, mixed>> provenance and digests, never the text itself */
    public function toAuditArray(): array
    {
        return array_map(static fn (ContextBlock $b) => $b->toAuditArray(), $this->blocks);
    }

    /** The rendered context, untrusted blocks fenced, in assembly order. */
    public function render(): string
    {
        return implode("\n\n", array_map(static fn (ContextBlock $b) => $b->render(), $this->blocks));
    }
}
