<?php

namespace App\Gateway\Credentials;

use App\Gateway\OutcomeClass;

/**
 * The result of the credential pre-flight: which credential ids are in play, and what is missing.
 *
 * There is deliberately no field here that could hold a decrypted value, so a future change cannot
 * accidentally carry one into tool_calls or an audit row by adding a line to a constructor.
 */
final readonly class CredentialResolution
{
    /**
     * @param  list<array{provider: string, account_ref: string, credential_id: int}>  $found
     * @param  list<string>  $missing
     * @param  OutcomeClass|null  $class  why it is unsatisfied (ADR-043): `credential_missing` when
     *                                    no active row with a value exists, `credential_invalid` when
     *                                    one exists and HQ already knows it is expired or rejected
     */
    public function __construct(
        public array $found = [],
        public array $missing = [],
        public ?OutcomeClass $class = null,
    ) {}

    public function satisfied(): bool
    {
        return $this->missing === [];
    }

    public function reason(): string
    {
        return implode('; ', $this->missing);
    }

    /** The class a refusal carries when the pre-flight failed; missing by default. */
    public function outcomeClass(): OutcomeClass
    {
        return $this->class ?? OutcomeClass::CredentialMissing;
    }

    /** @return list<int> */
    public function credentialIds(): array
    {
        return array_values(array_map(static fn (array $row) => $row['credential_id'], $this->found));
    }

    /** Safe for tool_calls and audit context: ids and account refs, never a value. */
    public function toArray(): array
    {
        return ['credentials' => $this->found, 'missing' => $this->missing, 'class' => $this->class?->value];
    }
}
