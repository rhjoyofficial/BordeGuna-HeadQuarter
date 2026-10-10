<?php

namespace App\Playbooks;

use App\Context\ContextBlock;

/**
 * What `PlaybookLoader` chose for one run: the labelled blocks for the context, and the record for
 * `agent_runs.playbooks` (docs/112 §2) so the run can be traced to the exact text it read.
 */
final readonly class PlaybookSelection
{
    /**
     * @param  list<ContextBlock>  $blocks  the framing block first, then one `Business` block per playbook
     * @param  array{loaded: list<array<string, mixed>>, skipped: list<array<string, mixed>>, budget_chars: int, reason: ?string}  $record
     */
    public function __construct(public array $blocks, public array $record) {}

    public function isEmpty(): bool
    {
        return $this->record['loaded'] === [];
    }
}
