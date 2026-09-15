<?php

namespace App\Tools;

use App\Gateway\OutcomeClass;
use Throwable;

final readonly class ToolResult
{
    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    /** The provider may have acted but HQ could not confirm; reconcile before retrying. */
    public const STATUS_UNCERTAIN = 'uncertain';

    public function __construct(
        public string $status,
        public array $output = [],
        public ?string $error = null,
        public ?string $providerRef = null,
        /**
         * Whose fault a failure was (ADR-043), when the tool knows: a tool that catches a
         * connector's exception and answers `failed` must say so here, or the 401 it caught reads
         * as a plain connector error once the exception is gone.
         */
        public ?OutcomeClass $class = null,
    ) {}

    public static function succeeded(array $output = [], ?string $providerRef = null): self
    {
        return new self(self::STATUS_SUCCEEDED, $output, null, $providerRef);
    }

    public static function failed(string $error, array $output = [], ?OutcomeClass $class = null): self
    {
        return new self(self::STATUS_FAILED, $output, $error, null, $class);
    }

    /** A failure the tool caught from a connector, classified from the exception it caught. */
    public static function failedBy(Throwable $e, array $output = []): self
    {
        return new self(self::STATUS_FAILED, $output, $e->getMessage(), null, OutcomeClass::fromThrowable($e));
    }

    public static function uncertain(string $error, ?string $providerRef = null): self
    {
        return new self(self::STATUS_UNCERTAIN, [], $error, $providerRef);
    }

    public function ok(): bool
    {
        return $this->status === self::STATUS_SUCCEEDED;
    }

    public function toArray(): array
    {
        return ['status' => $this->status, 'output' => $this->output, 'error' => $this->error, 'provider_ref' => $this->providerRef, 'outcome_class' => $this->class?->value];
    }
}
