<?php

namespace App\Support\Events\Handlers;

use App\Conversation\Channels\MetaMessengerNormaliser;
use App\Conversation\ConversationService;
use App\Models\DomainEvent;
use App\Support\Events\EventHandler;

/**
 * `conversation.inbound_received` from the Meta webhook: normalise, then ingest each message.
 *
 * Runs in the event drain, never in the request: the webhook answered 202 the moment the row was
 * written, and Meta's redelivery is harmless because the controller dedupes on the body digest and
 * `ingest()` dedupes on the message id. A page HQ does not hold makes `ingest()` throw, which fails
 * the event into retry and then dead-letter with the reason on the row, rather than inventing a
 * business for it.
 */
final class MetaInboundHandler implements EventHandler
{
    public function __construct(
        private readonly MetaMessengerNormaliser $normaliser,
        private readonly ConversationService $conversations,
    ) {}

    public function handle(DomainEvent $event): void
    {
        $payload = (array) $event->payload;
        unset($payload['_digest'], $payload['_correlation_id']);

        foreach ($this->normaliser->normalise($payload) as $message) {
            $this->conversations->ingest($message);
        }
    }
}
