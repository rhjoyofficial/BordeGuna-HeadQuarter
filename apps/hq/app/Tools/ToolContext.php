<?php

namespace App\Tools;

final readonly class ToolContext
{
    public function __construct(
        public string $idempotencyKey,
        public string $correlationId,
        public ?int $businessId = null,
        public ?int $brandId = null,
        public ?int $jobRunId = null,
        public ?int $actorUserId = null,
        public int $attempt = 1,
        public string $environment = 'production',
    ) {}
}
