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

    /**
     * The soft cap warning (decision V.8).
     *
     * ⚠ It says what will HAPPEN, not merely that a number was passed. "You are at 80 percent" is
     * information; "at 100 percent HQ will use a cheaper model rather than stop" is a decision the
     * reader can act on.
     *
     * @param  array{spent: float, cap: float, share_percent: int, scope: string, downgrade: bool}  $pressure
     */
    public function budgetPressure(array $pressure): void
    {
        $this->dispatch(
            "HQ AI spend at {$pressure['share_percent']}% of the cap ({$pressure['scope']})",
            implode("\n", [
                'Spent this period: USD '.number_format($pressure['spent'], 2).' of '.number_format($pressure['cap'], 2).'.',
                $pressure['downgrade']
                    ? 'The cap is reached. HQ is now choosing a cheaper model tier rather than stopping, and anything that runs degraded says so on its face.'
                    : 'Nothing has changed yet. At 100 percent HQ moves to a cheaper model tier; it does not stop working.',
                'Raise or change the cap in the ai_budgets table, or leave it and accept the lower tier.',
            ]),
            ['scope' => $pressure['scope'], 'share_percent' => $pressure['share_percent']],
        );
    }

    /**
     * A hard cap refused a call. Only reachable when somebody deliberately created a `hard` row.
     *
     * @param  array{spent: float, cap: float, scope: string}  $pressure
     */
    public function budgetRefused(array $pressure): void
    {
        $this->dispatch(
            "HQ AI spend cap REACHED, work refused ({$pressure['scope']})",
            implode("\n", [
                'Spent this period: USD '.number_format($pressure['spent'], 2).' against a HARD cap of '.number_format($pressure['cap'], 2).'.',
                'A model call was refused and did not run. This cap refuses rather than degrades because it was set to `hard`.',
                'Raise the limit or set the row to `soft` to have HQ downgrade instead of stopping.',
            ]),
            ['scope' => $pressure['scope']],
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
