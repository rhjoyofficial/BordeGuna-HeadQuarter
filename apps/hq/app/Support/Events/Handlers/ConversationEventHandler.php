<?php

namespace App\Support\Events\Handlers;

use App\Models\DomainEvent;
use App\Support\Events\EventHandler;

/**
 * `conversation.message_received`, `conversation.state_changed`, `conversation.reply_proposed`.
 *
 * The event row is the record, and in this milestone the record is all there is: the support agent
 * that would be woken by a message, the shadow comparison that would pair a proposal with what n8n
 * sent, and the delivery reconciliation all arrive with their gates (docs/72 §D). Registering the
 * handler now keeps the events from being "unhandled" rows and gives those gates one place to fill.
 */
final class ConversationEventHandler implements EventHandler
{
    public function handle(DomainEvent $event): void
    {
        // Nothing to do yet, deliberately.
    }
}
