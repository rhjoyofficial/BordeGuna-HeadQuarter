<?php

namespace App\Tools;

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
    ) {}

    public static function succeeded(array $output = [], ?string $providerRef = null): self
    {
        return new self(self::STATUS_SUCCEEDED, $output, null, $providerRef);
    }

    public static function failed(string $error, array $output = []): self
    {
        return new self(self::STATUS_FAILED, $output, $error);
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
        return ['status' => $this->status, 'output' => $this->output, 'error' => $this->error, 'provider_ref' => $this->providerRef];
    }
}
