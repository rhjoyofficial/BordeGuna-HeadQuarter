<?php

namespace App\Ingestion\Mailbox;

/** @param list<InboundMail> $messages */
final readonly class MailboxBatch
{
    public function __construct(public array $messages) {}
}
