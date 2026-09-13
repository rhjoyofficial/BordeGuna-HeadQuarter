<?php

namespace App\Conversation\Channels;

use App\Gateway\Descriptor\Channel;

/**
 * Turns one provider's inbound payload into channel-neutral messages (docs/72 §B).
 *
 * ⛔ A normaliser is a pure function over a document. It holds no credential, makes no request and
 * writes nothing: verifying the payload's signature is the webhook controller's job, and recording
 * the result is the conversation core's. That split is what lets the Meta normaliser be tested
 * against recorded fixtures with no Meta account anywhere near the test.
 */
interface InboundChannelAdapter
{
    public function channel(): Channel;

    /**
     * @param  array<string, mixed>  $payload  the provider's decoded webhook document
     * @return iterable<InboundMessage>
     */
    public function normalise(array $payload): iterable;
}
