<?php

namespace App\Conversation\Channels;

use App\Gateway\Descriptor\Channel;
use Carbon\CarbonImmutable;

/**
 * One message from a person outside the group, in the shape every channel is reduced to (docs/72 §B).
 *
 * ⛔ Channel-neutral on purpose. Messenger, Instagram and WhatsApp each arrive as a different JSON
 * document; by the time a message reaches the conversation core it is this, and the core never
 * learns which provider it came from beyond the `channel` word. The platform account reference is
 * the page or number the message was sent TO, which is how the business and brand are found; the
 * sender's external id is who it came FROM, which is how the person is found.
 *
 * Holds no token, no raw provider payload, no HTTP. The digest is of the provider's own message
 * entry, so a redelivered webhook is recognisable without the body being stored twice.
 *
 * @param  list<array{type: string, ref: string}>  $attachments  provider attachment references, never fetched here
 * @param  array<string, mixed>  $metadata  small, provider-specific facts worth keeping (a quick-reply payload, a referral)
 */
final readonly class InboundMessage
{
    public function __construct(
        public Channel $channel,
        public string $platformAccountRef,
        public string $externalThreadRef,
        public string $externalMessageRef,
        public string $senderExternalId,
        public ?string $body,
        public CarbonImmutable $receivedAt,
        public string $digest,
        public array $attachments = [],
        public array $metadata = [],
    ) {}

    public function hasText(): bool
    {
        return $this->body !== null && trim($this->body) !== '';
    }
}
