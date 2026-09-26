<?php

namespace App\Conversation\Channels;

use App\Gateway\Descriptor\Channel;

/**
 * One reply the group wants delivered to a person, channel-neutral (docs/72 §B).
 *
 * Built by the reply tool AFTER the gateway has passed consent, policy, permission and autonomy, and
 * handed to an `OutboundChannelAdapter`. The Meta and WhatsApp adapters built in docs/88 stream Q can
 * fill it, and none of them is registered anywhere, so nothing a tool builds reaches a person.
 *
 * `$template` is WhatsApp's pre-approved message (outside the 24-hour customer service window only a
 * template may be sent). A channel with no template concept refuses a message that carries one rather
 * than sending its body instead.
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
        public ?OutboundTemplate $template = null,
    ) {}
}
