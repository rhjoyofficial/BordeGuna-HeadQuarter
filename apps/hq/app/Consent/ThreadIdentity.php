<?php

namespace App\Consent;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationParticipant;
use App\Models\CustomerIdentity;

/**
 * Which IDENTITY of the customer a thread reaches (docs/114 §2, ruling (i)).
 *
 * ⛔ Read from the thread row, never from an id a caller typed. A reply names a conversation; the
 * conversation knows which phone or PSID the person arrived on (`conversation_participants.identity_id`,
 * written at ingestion), and that is the identity a consent restriction may stand against. The one
 * fallback is the latest inbound message's own sender id, looked up in the identity table, for a thread
 * linked before participants carried identities.
 *
 * Fail closed: the identity must be a live identity of the thread's own business (docs/122) and belong to
 * the thread's customer (or that customer's canonical record after a merge, inside the business). Anything else, and anything unresolvable, is null, which the ledger reads as "every
 * identity's denial applies and no identity's grant does" (`ConsentLedger::permittedAt`).
 */
final class ThreadIdentity
{
    public function resolve(Conversation $conversation): ?int
    {
        if ($conversation->customer_id === null) {
            return null;
        }

        $candidate = ConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('participant_type', 'customer')
            ->whereNotNull('identity_id')
            ->orderByDesc('id')
            ->value('identity_id');

        if ($candidate === null) {
            $identityChannel = $conversation->channel->identityChannel();
            $metadata = ConversationMessage::query()
                ->where('conversation_id', $conversation->id)
                ->where('direction', 'inbound')
                ->orderByDesc('id')
                ->value('metadata');
            $metadata = is_string($metadata) ? json_decode($metadata, true) : $metadata;
            $external = is_array($metadata) ? ($metadata['sender_external_id'] ?? null) : null;
            if ($identityChannel !== null && is_string($external) && $external !== '') {
                $candidate = CustomerIdentity::query()->key((int) $conversation->business_id, $identityChannel, $external)->value('id');
            }
        }

        if ($candidate === null) {
            return null;
        }

        $owners = [(int) $conversation->customer_id];
        $customer = $conversation->customer;
        if ($customer !== null) {
            $owners[] = (int) $customer->canonical()->id;
        }

        return CustomerIdentity::query()->live()->whereKey($candidate)->where('business_id', (int) $conversation->business_id)
            ->whereIn('customer_id', array_unique($owners))->exists()
            ? (int) $candidate
            : null;
    }
}
