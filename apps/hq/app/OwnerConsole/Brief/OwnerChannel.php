<?php

namespace App\OwnerConsole\Brief;

/**
 * The one door from HQ to the OWNER's own device (docs/143 D, docs/138 ruling 10). A connector seam in the
 * pattern of docs/97: an interface, a real transport behind configuration, and a fake. The destination is
 * NOT a parameter: an implementation reads the owner's chat from configuration and refuses to send unless
 * that chat is a validated, owner-confirmed one, so no model, argument or message can choose where it goes.
 */
interface OwnerChannel
{
    /** A name for the audit trail: `telegram`, `fake`, `none`. */
    public function name(): string;

    /** @return OwnerSend what happened; never throws for an ordinary delivery failure */
    public function send(string $text): OwnerSend;
}
