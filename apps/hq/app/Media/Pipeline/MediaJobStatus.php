<?php

namespace App\Media\Pipeline;

/** Where a pipeline job is. `ready` carries the video bytes; `failed` carries a reason a person can read. */
final readonly class MediaJobStatus
{
    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const READY = 'ready';

    public const FAILED = 'failed';

    public function __construct(
        public string $state,
        public ?string $video = null,
        public ?string $mime = null,
        public ?string $error = null,
    ) {}

    public function finished(): bool
    {
        return $this->state === self::READY || $this->state === self::FAILED;
    }
}
