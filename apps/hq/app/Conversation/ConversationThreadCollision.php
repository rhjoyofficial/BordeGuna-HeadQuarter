<?php

namespace App\Conversation;

use RuntimeException;
use Throwable;

/**
 * A platform thread reference this business cannot open because the database still holds it for ANOTHER business
 * (docs/131 ruling 2, docs/140). Only possible while the group-wide key (channel, external_thread_ref) is deployed;
 * once the business-scoped key is, each business holds its own thread. Never caught to carry on: the inbound event
 * stays replayable and nothing is attached to the other business's conversation.
 */
final class ConversationThreadCollision extends RuntimeException
{
    public static function for(int $businessId, string $channel, string $externalThreadRef, ?Throwable $previous = null): self
    {
        return new self("The {$channel} thread [{$externalThreadRef}] is held open by another business, so business {$businessId} cannot open it "
            .'until the business-scoped thread key is deployed. Nothing was attached to the other business\'s conversation; the event stays replayable.', 0, $previous);
    }
}
