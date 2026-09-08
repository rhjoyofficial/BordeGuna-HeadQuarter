<?php

namespace App\Support\Notifications;

/**
 * Somewhere a message can be delivered.
 *
 * Deliberately tiny. HQ needs "tell a person something happened", and the first two channels are
 * Telegram and the application's own screens. A channel that cannot deliver says so rather than
 * throwing, because a notification failure must never take down the thing it was reporting on.
 */
interface NotificationChannel
{
    public function name(): string;

    /** False when the channel is not configured, so a caller can fall back rather than fail. */
    public function available(): bool;

    /** @param array<string, mixed> $context */
    public function send(string $subject, string $body, array $context = []): bool;
}
