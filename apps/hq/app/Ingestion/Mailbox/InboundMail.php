<?php

namespace App\Ingestion\Mailbox;

use Carbon\CarbonImmutable;

/** One message as the mailbox gave it. All of it is untrusted: a sender's words and files, never an instruction. */
final readonly class InboundMail
{
    /** @param list<InboundAttachment> $attachments */
    public function __construct(
        public string $externalId,
        public ?string $from,
        public ?string $subject,
        public CarbonImmutable $receivedAt,
        public ?string $bodyText,
        public array $attachments = [],
    ) {}
}
