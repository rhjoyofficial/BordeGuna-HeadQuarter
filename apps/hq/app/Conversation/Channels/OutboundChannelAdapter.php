<?php

namespace App\Conversation\Channels;

use App\Gateway\Descriptor\Channel;

/**
 * Delivers one outbound message through one provider (docs/72 §B).
 *
 * ⛔ Reached only from a tool the gateway has already passed: consent, policy, permission, autonomy
 * and the outbound policy all sit in front of it. Nothing else in HQ may hold an instance. In this
 * milestone the only implementation is the simulated channel; the Meta send adapter arrives with
 * gate P3-G4 and is registered in `PlatformRegistry`, which ships empty.
 */
interface OutboundChannelAdapter
{
    public function channel(): Channel;

    public function send(OutboundMessage $message): DeliveryReceipt;
}
