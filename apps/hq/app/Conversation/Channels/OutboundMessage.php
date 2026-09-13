<?php

namespace App\Conversation\Channels;

use App\Gateway\Descriptor\Channel;

/**
 * One reply the group wants delivered to a person, channel-neutral (docs/72 §B).
 *
 * Built by the reply tool AFTER the gateway has passed consent, policy, permission and autonomy, and
 * handed to an `OutboundChannelAdapter`. No adapter that can reach a real person exists in this
 * milestone; the value exists so that the one written for gate P3-G4 has a shape to fill.
 */
final readonly class OutboundMessage
{
    public function __construct(
        public Channel $channel,
        public string $platformAccountRef,
        public string $recipientExternalId,
        public string $body,
        public string $conversationUuid,
        public string $messageUuid,
    ) {}
}
