<?php

namespace App\Briefing;

/**
 * The validator's verdict. There are two states and no third: `complete`, or `degraded` with every
 * reason named. A reason that `blocks_narration` means the bundle is not fit to be spoken from at
 * all (a provenance or completeness fault); a freshness reason does not block, because a briefing
 * that says "the ERP snapshot is two days old" at the top is exactly what docs/48 M.3 asks for.
 */
final readonly class ValidationReport
{
    public const COMPLETE = 'complete';

    public const DEGRADED = 'degraded';

    /** @param  list<array{code: string, check: string, subject: ?string, message: string, blocks_narration: bool}>  $reasons */
    public function __construct(public array $reasons = []) {}

    public static function missing(string $message): self
    {
        return new self([[
            'code' => 'bundle_missing', 'check' => 'completeness', 'subject' => null, 'message' => $message, 'blocks_narration' => true,
        ]]);
    }

    public function state(): string
    {
        return $this->reasons === [] ? self::COMPLETE : self::DEGRADED;
    }

    public function isDegraded(): bool
    {
        return $this->reasons !== [];
    }

    public function blocksNarration(): bool
    {
        foreach ($this->reasons as $reason) {
            if ($reason['blocks_narration']) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_values(array_unique(array_map(static fn (array $r): string => $r['code'], $this->reasons)));
    }

    public function toArray(): array
    {
        return ['state' => $this->state(), 'blocks_narration' => $this->blocksNarration(), 'reasons' => $this->reasons];
    }
}
