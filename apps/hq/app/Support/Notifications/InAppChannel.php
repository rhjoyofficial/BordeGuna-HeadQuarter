<?php

namespace App\Support\Notifications;

use App\Support\Audit\Auditor;

/**
 * The always-available channel: the message becomes an audit row, and the thing being notified about
 * is visible in HQ itself.
 *
 * This is not a stub. An approval waiting for a decision IS visible without any notification at all,
 * on the Approval Center screen and in its badge count; the audit row is what makes the fact that a
 * notification was raised part of the permanent record.
 */
final class InAppChannel implements NotificationChannel
{
    public function __construct(private readonly Auditor $auditor) {}

    public function name(): string
    {
        return 'in_app';
    }

    public function available(): bool
    {
        return true;
    }

    public function send(string $subject, string $body, array $context = []): bool
    {
        $this->auditor->recordSystem('notification.raised', 'notification', after: [
            'channel' => $this->name(),
            'subject' => $subject,
            'body' => $body,
        ] + $context);

        return true;
    }
}
