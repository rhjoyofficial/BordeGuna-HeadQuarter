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
 *
 * `readDelivery()` is the other direction (docs/95 §5 "Delivery", ADR-031): the daily reconciliation
 * asks the provider what became of a message whose send never recorded its outcome (`sending` left by
 * a crash, `uncertain` after a timeout). It is a READ of a message HQ already sent, by the provider's
 * own id; it never sends, and a provider that cannot answer says null, which leaves the attempt to a
 * person. `readCredentials()` names the vault pairs that read needs, so the caller (a command on the
 * diagnostic path, never the adapter) can open them.
 */
interface OutboundChannelAdapter
{
    public function channel(): Channel;

    public function send(OutboundMessage $message): DeliveryReceipt;

    /**
     * The provider's word on a message HQ sent, by its id: `accepted` when the provider holds it,
     * `failed` when it says the id does not exist, null when it cannot or will not say.
     */
    public function readDelivery(string $providerRef, string $platformAccountRef): ?DeliveryStatus;

    /**
     * @return list<array{0: string, 1: string}> [provider, account_ref] pairs `readDelivery()` needs open
     */
    public function readCredentials(string $platformAccountRef): array;
}
