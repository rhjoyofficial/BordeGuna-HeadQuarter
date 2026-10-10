<?php

namespace App\Outcomes;

/**
 * One business record an outcome is about: the system of record's natural key, always, and HQ's
 * read-model row when it has one (docs/107 D4).
 */
final readonly class ResultRefSpec
{
    public const ROLE_SUBJECT = 'subject';

    public const ROLE_CONTEXT = 'context';

    public function __construct(
        public ResultType $type,
        public string $sourceSystem,
        public string $externalRef,
        public ?int $rowId = null,
        public string $role = self::ROLE_SUBJECT,
    ) {}

    /** @return array<string, mixed> the reference's meaning, for the outcome's digest */
    public function meaning(): array
    {
        return [
            'type' => $this->type->value,
            'role' => $this->role,
            'source_system' => $this->sourceSystem,
            'external_ref' => $this->externalRef,
            'row_id' => $this->rowId,
        ];
    }
}
