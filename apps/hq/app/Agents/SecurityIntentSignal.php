<?php

namespace App\Agents;

/**
 * What `SecurityIntent` found in one trusted message (ADR-042): the detector version, the classes
 * it matched, the clause each match came from, and whether the detector itself failed, which is
 * read as a detection (fail closed).
 *
 * @param  list<string>  $classes  the matched `SecurityIntent::CLASS_*` values, unique, in order found
 * @param  list<array{class: string, clause: string}>  $evidence
 */
final readonly class SecurityIntentSignal
{
    public function __construct(
        public string $version,
        public array $classes,
        public array $evidence = [],
        public ?string $error = null,
    ) {}

    public static function none(string $version): self
    {
        return new self($version, []);
    }

    /** A signal the runtime must act on: something matched, or the detector could not be trusted to say. */
    public function detected(): bool
    {
        return $this->classes !== [] || $this->error !== null;
    }

    public function has(string $class): bool
    {
        return in_array($class, $this->classes, true);
    }

    /** @return array{version: string, classes: list<string>, evidence: list<array{class: string, clause: string}>, error: string|null} */
    public function toArray(): array
    {
        return ['version' => $this->version, 'classes' => $this->classes, 'evidence' => $this->evidence, 'error' => $this->error];
    }
}
