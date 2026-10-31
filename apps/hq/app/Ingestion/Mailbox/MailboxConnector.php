<?php

namespace App\Ingestion\Mailbox;

use App\Ingestion\Mailbox\Exceptions\MailboxRefused;
use App\Ingestion\Mailbox\Exceptions\MailboxUnavailable;

/**
 * Reading a business's inbound supplier mailbox (capability `mail.inbox.read`, docs/144-email-ingestion).
 *
 * ⛔ **Reads only.** No send, no reply, no delete, no label, no mark-as-read: the interface has no method for any
 * of them, so no implementation can be asked to. What comes back is untrusted third-party content.
 *
 * Implementations: Gmail (REST), Microsoft Graph (REST), a fake; an IMAP driver is another implementation of
 * this one interface. An implementation authenticates only through the `CredentialVault` scope the caller opened.
 *
 * ⭐ Contract tests (`tests/Feature/Ingestion/MailboxContractTest`): a fetch returns messages with their
 * attachments' bytes in the order received; a `since` moment returns only later mail; a rejected credential is
 * `MailboxRefused`; an unreachable mailbox is `MailboxUnavailable`; the interface has no write method.
 */
interface MailboxConnector
{
    public function key(): string;

    /**
     * @param  string  $accountRef  the business slug the credential is stored under
     * @param  \DateTimeInterface|null  $since  only mail received after this moment
     * @param  int  $limit  at most this many messages (the oldest first, so repeated calls move forward)
     *
     * @throws MailboxRefused
     * @throws MailboxUnavailable
     */
    public function fetch(string $accountRef, ?\DateTimeInterface $since, int $limit): MailboxBatch;
}
