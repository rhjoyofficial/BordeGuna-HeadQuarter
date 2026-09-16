<?php

namespace App\Support\Events\Handlers;

use App\Conversation\Channels\InboundChannelAdapter;
use App\Conversation\Channels\MetaCommentNormaliser;
use App\Conversation\Channels\MetaInstagramNormaliser;
use App\Conversation\Channels\MetaMessengerNormaliser;
use App\Conversation\ConversationService;
use App\Conversation\ShadowComparisons;
use App\Models\DomainEvent;
use App\Support\Events\EventHandler;

/**
 * `conversation.inbound_received` from the Meta webhook: normalise, then ingest each message.
 *
 * Three normalisers read the same document (docs/78 B.1): a Page's Messenger, an Instagram account's
 * DMs, and comments on a Page's posts. Each is pure and yields nothing for a shape that is not its
 * own, so one event is handed to all three and the channel-neutral core sees only `InboundMessage`s.
 *
 * Runs in the event drain, never in the request: the webhook answered 202 the moment the row was
 * written, and Meta's redelivery is harmless because the controller dedupes on the body digest and
 * `ingest()` dedupes on the message id. A page HQ does not hold makes `ingest()` throw, which fails
 * the event into retry and then dead-letter with the reason on the row, rather than inventing a
 * business for it.
 */
final class MetaInboundHandler implements EventHandler
{
    /** @var list<InboundChannelAdapter> */
    private readonly array $normalisers;

    public function __construct(
        MetaMessengerNormaliser $messenger,
        MetaInstagramNormaliser $instagram,
        MetaCommentNormaliser $comments,
        private readonly ConversationService $conversations,
        private readonly ShadowComparisons $comparisons,
    ) {
        $this->normalisers = [$messenger, $instagram, $comments];
        $this->messenger = $messenger;
    }

    private readonly MetaMessengerNormaliser $messenger;

    public function handle(DomainEvent $event): void
    {
        $payload = (array) $event->payload;
        unset($payload['_digest'], $payload['_correlation_id']);

        foreach ($this->normalisers as $normaliser) {
            foreach ($normaliser->normalise($payload) as $message) {
                $this->conversations->ingest($message);
            }
        }

        // What the Page itself sent (P3-G3 pairing, docs/78 B.1): recorded on the thread as an
        // external outbound and paired with the proposal that answered the same customer message.
        foreach ($this->messenger->echoes($payload) as $echo) {
            $recorded = $this->conversations->recordExternalOutbound($echo);
            if ($recorded !== null && $recorded->wasRecentlyCreated) {
                $this->comparisons->pair($recorded);
            }
        }
    }
}
