<?php

namespace App\Support\Events;

use App\Models\DomainEvent;

/**
 * A subscriber to one or more event types. Handlers MUST be idempotent: delivery is
 * at-least-once and the same event can be replayed from the admin UI.
 */
interface EventHandler
{
    public function handle(DomainEvent $event): void;
}
