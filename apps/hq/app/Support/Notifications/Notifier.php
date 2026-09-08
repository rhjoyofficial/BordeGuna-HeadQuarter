<?php

namespace App\Support\Notifications;

use App\Models\Approval;
use App\Models\User;

/**
 * Composes the messages HQ sends a person, and delivers them on every available channel.
 *
 * The in-app channel always runs, so the fact that a notification was raised is always on the
 * record even when an external channel is down or unconfigured. Telegram runs when it is configured,
 * which in production it is.
 *
 * ⚠ A message body carries a description, never a secret and never customer personal data. Approval
 * payloads were already redacted before they were stored; this adds the rule that a notification
 * says what needs deciding and where to decide it, rather than reproducing the arguments.
 */
final class Notifier
{
    /** @var list<NotificationChannel> */
    private array $channels;

    public function __construct(InAppChannel $inApp, TelegramChannel $telegram)
    {
        $this->channels = [$inApp, $telegram];
    }

    public function approvalRequested(Approval $approval): void
    {
        $this->dispatch(
            "HQ approval needed: {$approval->tool_key}",
            implode("\n", array_filter([
                $approval->reason,
                'Risk: '.($approval->risk_tier?->label() ?? 'unknown'),
                $approval->business_id ? "Business: {$approval->business_id}" : null,
                $approval->sla_due_at ? 'Decide by: '.$approval->sla_due_at->timezone(config('hq.timezone'))->format('D j M H:i T') : null,
                'Open HQ > Approvals to decide. Ignoring it expires the request; it is never approved by default.',
            ])),
            ['approval_id' => $approval->id, 'tool_key' => $approval->tool_key],
        );
    }

    public function approvalDelegated(Approval $approval, User $to): void
    {
        $this->dispatch(
            "HQ approval delegated: {$approval->tool_key}",
            "This decision is now {$to->email}'s to make.",
            ['approval_id' => $approval->id, 'delegated_to' => $to->getKey()],
        );
    }

    public function approvalOverdue(Approval $approval): void
    {
        $this->dispatch(
            "HQ approval overdue: {$approval->tool_key}",
            'It passed its decision deadline and has been escalated to '.($approval->escalate_to_role ?? 'the owner')
            .'. It is still waiting, and it still will not approve itself.',
            ['approval_id' => $approval->id],
        );
    }

    public function approvalIntegrityFailed(Approval $approval, string $reason): void
    {
        $this->dispatch(
            "HQ approval INTEGRITY FAILURE: {$approval->tool_key}",
            "Execution was refused and nothing was repaired.\n{$reason}\n"
            .'A mismatch cannot happen through the application, so the row was changed underneath it. Investigate before approving anything else.',
            ['approval_id' => $approval->id],
        );
    }

    /** @param array<string, mixed> $context */
    private function dispatch(string $subject, string $body, array $context = []): void
    {
        foreach ($this->channels as $channel) {
            if ($channel->available()) {
                $channel->send($subject, $body, $context);
            }
        }
    }

    /** @return array<string, bool> which channels would deliver right now */
    public function channelStatus(): array
    {
        $status = [];
        foreach ($this->channels as $channel) {
            $status[$channel->name()] = $channel->available();
        }

        return $status;
    }
}
