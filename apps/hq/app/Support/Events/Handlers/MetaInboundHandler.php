<?php

namespace App\Support\Events\Handlers;

use App\Conversation\Channels\DeliveryStatus;
use App\Conversation\Channels\InboundChannelAdapter;
use App\Conversation\Channels\MetaCommentNormaliser;
use App\Conversation\Channels\MetaInstagramNormaliser;
use App\Conversation\Channels\MetaMessengerNormaliser;
use App\Conversation\Channels\WhatsAppCloudNormaliser;
use App\Conversation\ConversationService;
use App\Conversation\Deliveries\DeliveryLedger;
use App\Conversation\ShadowComparisons;
use App\Models\DomainEvent;
use App\Support\Events\EventHandler;

/**
 * `conversation.inbound_received` from the Meta webhook (or n8n's copy of it): normalise, then ingest
 * each message, then apply each delivery signal.
 *
 * Four normalisers read the same document (docs/78 B.1, docs/88 A point 2): a Page's Messenger, an
 * Instagram account's DMs, comments on a Page's posts, and a business number's WhatsApp. Each is pure
 * and yields nothing for a shape that is not its own, so one event is handed to all four and the
 * channel-neutral core sees only `InboundMessage`s.
 *
 * Delivery signals (WhatsApp `statuses`, Messenger `delivery` and `read`) go to the ledger, which
 * moves only deliveries HQ itself sent; a signal for a message someone else sent on the account is
 * ignored there. Nothing here sends anything.
 *
 * Runs in the event drain, never in the request: the webhook answered 202 the moment the row was
 * written, and Meta's redelivery is harmless because the controller dedupes on the body digest and
 * `ingest()` dedupes on the message id. An account HQ does not hold makes `ingest()` throw, which fails
 * the event into retry and then dead-letter with the reason on the row, rather than inventing a
 * business for it.
 */
final class MetaInboundHandler implements EventHandler
{
    /** @var list<InboundChannelAdapter> */
    private readonly array $normalisers;

    public function __construct(
        private readonly MetaMessengerNormaliser $messenger,
        MetaInstagramNormaliser $instagram,
        MetaCommentNormaliser $comments,
        private readonly WhatsAppCloudNormaliser $whatsapp,
        private readonly ConversationService $conversations,
        private readonly ShadowComparisons $comparisons,
        private readonly DeliveryLedger $deliveries,
    ) {
        $this->normalisers = [$messenger, $instagram, $comments, $whatsapp];
    }

    public function handle(DomainEvent $event): void
    {
        $payload = (array) $event->payload;
        unset($payload['_digest'], $payload['_correlation_id'], $payload['_provenance']);

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

        foreach ($this->whatsapp->statuses($payload) as $status) {
            $this->deliveries->apply($status);
        }
        foreach ($this->messenger->deliveries($payload) as $signal) {
            $signal instanceof DeliveryStatus ? $this->deliveries->apply($signal) : $this->deliveries->applyWatermark($signal);
        }
    }
}
