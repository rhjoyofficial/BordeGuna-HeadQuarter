<?php

namespace App\Ingestion\Mailbox;

use App\Ingestion\Mailbox\Exceptions\MailboxRefused;
use App\Ingestion\Mailbox\Exceptions\MailboxUnavailable;
use Carbon\CarbonImmutable;

/**
 * A mailbox that exists only inside HQ (contract tests, the sandbox switch): two fixed supplier mails, one
 * with a plain-text bill and one with a PDF bill, received a day apart. The account chooses a story: an account
 * ref of `refused` is refused and `down` is unavailable. It reaches nothing and needs no credential.
 */
final class FakeMailbox implements MailboxConnector
{
    public function key(): string
    {
        return 'fake_mailbox';
    }

    public static function sampleBillText(): string
    {
        return "Green Valley Traders\nInvoice No: GV-2026-0042\nDate: 2026-10-20\n"
            ."Hilsa pickle jar 4 x 450.00 1,800.00\nMustard oil 5L 2 x 1,750.00 3,500.00\nGrand Total: BDT 5,300.00\n";
    }

    public function fetch(string $accountRef, ?\DateTimeInterface $since, int $limit): MailboxBatch
    {
        if ($accountRef === 'refused') {
            throw new MailboxRefused('fake_mailbox: the credential is rejected.');
        }
        if ($accountRef === 'down') {
            throw new MailboxUnavailable('fake_mailbox: the mailbox is down.');
        }

        $mails = [
            new InboundMail('fake-msg-1', 'accounts@supplier.example', 'Invoice GV-2026-0042', CarbonImmutable::parse('2026-10-20 08:00:00', 'UTC'),
                'Please find the bill attached.', [new InboundAttachment('GV-2026-0042.txt', 'text/plain', self::sampleBillText())]),
            new InboundMail('fake-msg-2', 'sales@other.example', 'Our statement', CarbonImmutable::parse('2026-10-21 08:00:00', 'UTC'),
                'Statement attached.', [new InboundAttachment('statement.txt', 'text/plain', "Other Supplier Ltd\nInvoice No: OS-77\nDate: 2026-10-21\nSalt 10 x 90.00 900.00\nTotal: BDT 900.00\n")]),
        ];
        $mails = array_values(array_filter($mails, static fn (InboundMail $m) => $since === null || $m->receivedAt > $since));

        return new MailboxBatch(array_slice($mails, 0, $limit));
    }
}
