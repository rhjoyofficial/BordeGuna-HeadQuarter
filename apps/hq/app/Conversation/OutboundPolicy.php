<?php

namespace App\Conversation;

use App\Consent\ConsentLedger;
use App\Consent\ConsentPurpose;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use Carbon\CarbonImmutable;

/**
 * The rules an outbound reply must pass beyond consent and permission (docs/72 §B).
 *
 * ⛔ Deterministic, and checked at the tool before any provider is looked up, so that an agent with
 * every grant in the world still cannot send twice to one message, cannot send at two in the
 * morning to somebody who last wrote yesterday, and cannot reach a person who said stop. The rules
 * are small and named; a rule that cannot be named here does not exist.
 */
final readonly class OutboundPolicy
{
    public function __construct(private ConsentLedger $consent) {}

    /** The reason this reply may not go, or null when it may. */
    public function refusal(Conversation $conversation, bool $humanAuthor, ?CarbonImmutable $now = null): ?string
    {
        $timezone = (string) config('hq.timezone', 'Asia/Dhaka');
        $now ??= CarbonImmutable::now($timezone);
        $now = $now->setTimezone($timezone);

        if ($conversation->customer_id === null) {
            return 'the thread is not linked to a person, so there is nobody consent could have been recorded for';
        }

        // Suppression: a person who said stop is not contacted, whoever is typing.
        $decision = $this->consent->permits($conversation->customer_id, $conversation->business_id, $conversation->channel->value, ConsentPurpose::Service);
        if (! $decision->permitted) {
            return 'consent does not permit contacting this person on this channel: '.$decision->reason;
        }

        $lastInbound = ConversationMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', 'inbound')
            ->latest('received_at')
            ->first();
        $lastInboundAt = $lastInbound?->received_at === null ? null : CarbonImmutable::instance($lastInbound->received_at)->setTimezone($timezone);

        // One reply per inbound message, unless a person is writing.
        if (! $humanAuthor) {
            if ($lastInbound === null) {
                return 'nothing inbound has been received on this thread, so there is nothing for the agent to reply to';
            }

            $agentRepliesSince = ConversationMessage::query()
                ->where('conversation_id', $conversation->id)
                ->where('direction', 'outbound')
                ->where('author_type', 'agent')
                // By id, not by timestamp: a reply written in the same second as the inbound is still after it.
                ->where('id', '>', $lastInbound->id)
                ->count();
            if ($agentRepliesSince >= 1) {
                return 'the agent has already replied to the latest inbound message; a second reply waits for the customer';
            }
        }

        // A per-thread cap, whoever is writing: a burst is a defect or an abuse either way.
        $cap = (int) config('hq.outbound.per_thread_hourly_cap', 6);
        // ⚠ The binding is converted to UTC first: a Dhaka-zoned instant formatted as-is would be
        // compared against UTC rows six hours off.
        $lastHour = ConversationMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', 'outbound')
            ->where('created_at', '>=', $now->utc()->subHour())
            ->count();
        if ($lastHour >= $cap) {
            return "the thread has already had {$lastHour} outbound messages in the last hour; the cap is {$cap}";
        }

        // Quiet hours, for anything that is not a reply inside the customer's own window.
        $windowHours = (int) config('hq.outbound.reply_window_hours', 24);
        $insideWindow = $lastInboundAt !== null && $lastInboundAt->greaterThanOrEqualTo($now->subHours($windowHours));
        if (! $insideWindow && $this->inQuietHours($now)) {
            $start = (int) config('hq.outbound.quiet_hours.start', 23);
            $end = (int) config('hq.outbound.quiet_hours.end', 8);

            return sprintf('it is quiet hours (%02d:00 to %02d:00 %s) and the customer has not written within the last %d hours', $start, $end, $timezone, $windowHours);
        }

        return null;
    }

    private function inQuietHours(CarbonImmutable $now): bool
    {
        $start = (int) config('hq.outbound.quiet_hours.start', 23);
        $end = (int) config('hq.outbound.quiet_hours.end', 8);
        $hour = (int) $now->format('G');

        // A window that crosses midnight (23 to 8) is two half-windows.
        return $start > $end ? ($hour >= $start || $hour < $end) : ($hour >= $start && $hour < $end);
    }
}
