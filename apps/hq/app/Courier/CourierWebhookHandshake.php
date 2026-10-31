<?php

namespace App\Courier;

/**
 * A courier that wants the merchant's own secret echoed back in the webhook reply before it will deliver
 * (Pathao's integration secret; the pairing rule learned in the ERP's courier hub, where a courier's
 * "reply value" is shown only on the courier's own page). The controller adds these headers to the
 * answer of an authentic webhook and of nothing else.
 */
interface CourierWebhookHandshake
{
    /** @return array<string, string> response header name => value */
    public function handshakeHeaders(string $secret): array;
}
