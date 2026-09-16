<?php

namespace App\Conversation\Channels;

use App\Gateway\Descriptor\Channel;
use Carbon\CarbonImmutable;

/**
 * A message the Page itself sent, as the provider echoes it back (docs/78 B.1, gate P3-G3).
 *
 * During the shadow week beside the n8n bot, HQ does not send; n8n does, and Meta echoes what went
 * out. That echo is the only record HQ has of "what was actually said", so it is kept apart from an
 * `InboundMessage` (it is not the customer's) and from an HQ-sent outbound (HQ did not send it): an
 * external outbound, recorded on the thread so a proposal can be compared with it.
 */
final readonly class OutboundEcho
{
    public function __construct(
        public Channel $channel,
        public string $platformAccountRef,
        public string $externalThreadRef,
        public string $externalMessageRef,
        public string $recipientExternalId,
        public ?string $body,
        public CarbonImmutable $sentAt,
        public string $digest,
        public array $attachments = [],
    ) {}
}
