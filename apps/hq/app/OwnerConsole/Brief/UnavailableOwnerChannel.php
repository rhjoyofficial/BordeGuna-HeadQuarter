<?php

namespace App\OwnerConsole\Brief;

/** The shipped binding: no owner channel is configured, so nothing can be sent anywhere. */
final class UnavailableOwnerChannel implements OwnerChannel
{
    public function name(): string
    {
        return 'none';
    }

    public function send(string $text): OwnerSend
    {
        return OwnerSend::refused('No owner channel is configured (hq.owner_console.driver). Nothing was sent.');
    }
}
