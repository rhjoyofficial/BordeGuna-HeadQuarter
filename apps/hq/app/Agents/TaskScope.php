<?php

namespace App\Agents;

/**
 * Which targets the trusted task is about (ADR-043, docs/74 C.2): the runtime's own reading of
 * the operator's words, made before any tool call is expanded and stored on the run.
 *
 * Four kinds. `group`: the text carries a group phrase, and an unscoped call may become one call
 * per permitted target. `single`: exactly one business is named, by any name the directory knows;
 * an unscoped call resolves to it and to nothing wider. `named`: two or more businesses are named;
 * an unscoped call resolves to those. `none`: nothing is named; an unscoped call stays unscoped
 * and the gateway refuses it, because a model omission is not an instruction and HQ does not guess.
 */
final readonly class TaskScope
{
    public const GROUP = 'group';

    public const SINGLE = 'single';

    public const NAMED = 'named';

    public const NONE = 'none';

    /**
     * @param  list<string>  $businessSlugs  the businesses the task named (empty for group and none)
     * @param  list<string>  $storeSlugs  the stores the task named by store name (a subset of the businesses' stores)
     * @param  string|null  $matched  the phrase or name that decided the kind, for the run record
     */
    public function __construct(
        public string $kind,
        public array $businessSlugs = [],
        public array $storeSlugs = [],
        public ?string $matched = null,
        public string $version = TaskScopeResolver::VERSION,
    ) {}

    public static function none(): self
    {
        return new self(self::NONE);
    }

    public function isGroup(): bool
    {
        return $this->kind === self::GROUP;
    }

    /** One or more named targets: an unscoped call resolves to them and never widens. */
    public function isTargeted(): bool
    {
        return $this->kind === self::SINGLE || $this->kind === self::NAMED;
    }

    /** @return array{kind: string, businesses: list<string>, stores: list<string>, matched: string|null, version: string} */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'businesses' => $this->businessSlugs,
            'stores' => $this->storeSlugs,
            'matched' => $this->matched,
            'version' => $this->version,
        ];
    }
}
