<?php

namespace App\Ingestion\Mailbox;

final readonly class InboundAttachment
{
    public function __construct(public string $filename, public string $declaredMime, public string $bytes) {}
}
