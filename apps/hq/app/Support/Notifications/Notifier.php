<?php

namespace App\Support\Notifications;

use App\Conversation\HandoverReason;
use App\Models\Alert;
use App\Models\Approval;
use App\Models\Conversation;
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

    public function __construct(private readonly InAppChannel $inApp, TelegramChannel $telegram)
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

    /**
     * A condition HQ's alert engine found (docs/78 B.2). Sent once when it opens; the alert page holds
     * the record, and the same condition staying true is counted there rather than sent again.
     */
    public function alertRaised(Alert $alert): void
    {
        $this->dispatch(
            'HQ alert ('.$alert->severity."): {$alert->subject}",
            $alert->body."\nOpen HQ > Observe > Alerts for the record. HQ says so again when it clears.",
            ['alert_id' => $alert->id, 'rule' => $alert->rule, 'fingerprint' => $alert->fingerprint],
        );
    }

    /** Sent at most once per renotify period, or at once when the condition got more severe. */
    public function alertStillOpen(Alert $alert, bool $worse): void
    {
        $since = $alert->first_seen_at->timezone(config('hq.timezone'))->format('D j M H:i T');

        $this->dispatch(
            ($worse ? 'HQ alert now '.$alert->severity : 'HQ alert still open').": {$alert->subject}",
            $alert->body."\nFirst seen {$since}; seen on {$alert->occurrences} checks.",
            ['alert_id' => $alert->id, 'rule' => $alert->rule, 'fingerprint' => $alert->fingerprint, 'worse' => $worse],
        );
    }

    /**
     * docs/123: an alert addressed to ONE person (an overdue follow-up's responsible person). It goes to that
     * person in HQ only: the in-app record names them as its recipient, and their sidebar shows the count.
     * ⛔ Never the shared Telegram chat (other people read it), never a customer, never a message out.
     */
    public function alertRaisedFor(Alert $alert, User $to): void
    {
        $this->deliverTo($to, 'HQ alert ('.$alert->severity."): {$alert->subject}", $alert->body,
            ['alert_id' => $alert->id, 'rule' => $alert->rule, 'fingerprint' => $alert->fingerprint]);
    }

    public function alertStillOpenFor(Alert $alert, User $to, bool $worse): void
    {
        $this->deliverTo($to, ($worse ? 'HQ alert now '.$alert->severity : 'HQ alert still open').": {$alert->subject}", $alert->body,
            ['alert_id' => $alert->id, 'rule' => $alert->rule, 'fingerprint' => $alert->fingerprint, 'worse' => $worse]);
    }

    public function alertResolvedFor(Alert $alert, User $to): void
    {
        $this->deliverTo($to, "HQ alert cleared: {$alert->subject}", 'The condition has not been seen on the last '.$alert->clear_checks.' checks.',
            ['alert_id' => $alert->id, 'rule' => $alert->rule, 'fingerprint' => $alert->fingerprint]);
    }

    public function alertResolved(Alert $alert): void
    {
        $minutes = (int) $alert->first_seen_at->diffInMinutes($alert->resolved_at ?? now());

        $this->dispatch(
            "HQ alert cleared: {$alert->subject}",
            "The condition has not been seen on the last {$alert->clear_checks} checks. It was open for {$minutes} minutes.",
            ['alert_id' => $alert->id, 'rule' => $alert->rule, 'fingerprint' => $alert->fingerprint],
        );
    }

    /**
     * A customer thread needs a person (docs/95 §5 "Hand-over", stream W2a): the thread entered
     * `human_required`, by the core's escalation before the agent, by a person switching the agent off,
     * or by the agent being woken and unable to serve.
     *
     * ⛔ The message names the business, the brand, the channel, the reason CODE and the thread's link,
     * and nothing the customer wrote: no message text, no name, no phone. A Telegram group is not the
     * inbox, and the inbox is where the words are read, behind the permission that guards them.
     *
     * @param  array<string, mixed>  $context  the dedupe key and ids, kept on the in-app record
     */
    public function handoverRequired(Conversation $conversation, ?HandoverReason $reason, array $context = []): void
    {
        $conversation->loadMissing(['business:id,name', 'brand:id,name']);

        $this->dispatch(
            'HQ inbox: a thread needs a person ('.($reason?->value ?? 'reason not recorded').')',
            implode("\n", array_filter([
                'Business: '.($conversation->business?->name ?? 'unknown').($conversation->brand ? ' / '.$conversation->brand->name : ''),
                'Channel: '.$conversation->channel->value,
                'Reason: '.($reason?->value ?? 'not recorded'),
                'Open: '.url('/inbox/'.$conversation->uuid),
                'Nothing the customer wrote is in this message; read the thread in HQ.',
            ])),
            ['conversation_uuid' => $conversation->uuid, 'reason' => $reason?->value] + $context,
        );
    }

    /** @param array<string, mixed> $context */
    private function deliverTo(User $to, string $subject, string $body, array $context): void
    {
        $this->inApp->send($subject, $body, $context + ['recipient_user_id' => $to->getKey()]);
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
