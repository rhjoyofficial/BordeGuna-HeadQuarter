<?php

namespace App\Support\Events\Handlers;

use App\Models\DomainEvent;
use App\Support\Events\EventHandler;

/**
 * `conversation.message_received`, `conversation.state_changed`, `conversation.reply_proposed`.
 *
 * The event row is the record, and for `state_changed` and `reply_proposed` the record is all there is.
 * The wake-up that `message_received` once waited for lives in its own handler beside this one
 * (`SupportWakeUpHandler`, docs/95 stream W2a, docs/100), inert by posture; the shadow comparison pairs
 * on the Meta echo (`MetaInboundHandler`); the delivery reconciliation still arrives with its gate
 * (docs/72 §D). Registering this handler keeps the events from being "unhandled" rows.
 */
final class ConversationEventHandler implements EventHandler
{
    public function handle(DomainEvent $event): void
    {
        // Nothing to do yet, deliberately.
    }
}
