<?php

namespace App\Support\Events\Handlers;

use App\Conversation\WakeUp\SupportWakeUp;
use App\Conversation\WakeUp\WakeSupportAgent;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\DomainEvent;
use App\Support\Events\EventHandler;

/**
 * `conversation.message_received` -> maybe wake the Support agent (docs/95 §5, §16 item 4; stream W2a).
 *
 * Runs in the event drain, never in the webhook request. It decides nothing itself: `SupportWakeUp`
 * says whether every precondition holds (the two flags, the active agent with an enabling user, a
 * thread that is the agent's, an inbound message), and this handler either queues `WakeSupportAgent`
 * on the `ai` queue or records `conversation.wake_up_skipped` with the reason. The run itself never
 * happens here: a model call belongs on a queue with a timeout, not in a drain that holds a lock.
 *
 * ⛔ Inert in production's posture: `channels.meta.inbound` is off, so the first precondition fails and
 * every message (there are none, the webhook answers 404) would be audited as skipped. A thread of a
 * paused agent, a thread a person holds, a thread the core already handed over: all skipped, by reason.
 *
 * Idempotent: a redelivered event is the same message uuid, and `SupportWakeUp::run()` makes one run
 * per message whatever reaches it.
 */
final class SupportWakeUpHandler implements EventHandler
{
    public function __construct(private readonly SupportWakeUp $wakeUp) {}

    public function handle(DomainEvent $event): void
    {
        $payload = (array) $event->payload;
        $conversationUuid = (string) ($payload['conversation_uuid'] ?? '');
        $messageUuid = (string) ($payload['message_uuid'] ?? '');
        if ($conversationUuid === '' || $messageUuid === '') {
            return;
        }

        $conversation = Conversation::query()->where('uuid', $conversationUuid)->first();
        if ($conversation === null) {
            return;
        }
        $message = ConversationMessage::query()->where('uuid', $messageUuid)->first();

        if (($refusal = $this->wakeUp->refusal($conversation, $message)) !== null) {
            $this->wakeUp->skipped($conversation, $messageUuid, $refusal, 'event');

            return;
        }

        WakeSupportAgent::dispatch($conversationUuid, $messageUuid, is_string($payload['_correlation_id'] ?? null) ? $payload['_correlation_id'] : $event->event_id);
    }
}
