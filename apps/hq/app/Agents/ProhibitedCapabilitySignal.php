<?php

namespace App\Agents;

/**
 * What `ProhibitedCapability` found in one trusted message (ADR-044): the detector version, the
 * kinds it matched, the clause each match came from, and whether the detector itself failed,
 * which is read as a detection (fail closed).
 *
 * @param  list<string>  $kinds  the matched `ProhibitedCapability::*` kinds, unique, in order found
 * @param  list<array{kind: string, clause: string}>  $evidence
 */
final readonly class ProhibitedCapabilitySignal
{
    public function __construct(
        public string $version,
        public array $kinds,
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
        return $this->kinds !== [] || $this->error !== null;
    }

    public function has(string $kind): bool
    {
        return in_array($kind, $this->kinds, true);
    }

    /** @return array{version: string, kinds: list<string>, evidence: list<array{kind: string, clause: string}>, error: string|null} */
    public function toArray(): array
    {
        return ['version' => $this->version, 'kinds' => $this->kinds, 'evidence' => $this->evidence, 'error' => $this->error];
    }
}
