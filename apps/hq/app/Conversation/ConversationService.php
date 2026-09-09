<?php

namespace App\Conversation;

use App\Context\Provenance;
use App\Gateway\Descriptor\Channel;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationParticipant;
use App\Models\CustomerIdentity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Opening threads and recording what was said (Phase 2.5 F).
 *
 * ⛔ **A thread is found by the platform's thread reference, not by a phone number**, and it is
 * linked to a person through `customer_identities`, which already enumerates messenger_psid,
 * instagram_igsid and whatsapp. That is what lets the same customer arrive on Messenger today and
 * WhatsApp next week without becoming two people, and what lets a stranger's first message be
 * recorded before anybody knows who they are.
 *
 * ⛔ **Every inbound message is untrusted** (ADR-024). The provenance is set here rather than by the
 * caller, because a channel adapter written in a hurry is exactly the place the label would be
 * omitted.
 */
final readonly class ConversationService
{
    /** Find the open thread for this platform conversation, or start one. */
    public function openOrFind(
        int $businessId,
        Channel $channel,
        ?string $externalThreadRef = null,
        ?int $customerId = null,
        array $attributes = [],
    ): Conversation {
        return DB::transaction(function () use ($businessId, $channel, $externalThreadRef, $customerId, $attributes) {
            if ($externalThreadRef !== null) {
                $existing = Conversation::query()
                    ->where('channel', $channel->value)
                    ->where('external_thread_ref', $externalThreadRef)
                    ->where('status', '!=', 'closed')
                    ->first();

                if ($existing !== null) {
                    // A later message may finally identify a person the first one could not.
                    if ($customerId !== null && $existing->customer_id === null) {
                        $existing->forceFill(['customer_id' => $customerId])->save();
                    }

                    return $existing;
                }
            }

            return Conversation::create([
                'uuid' => (string) Str::uuid(),
                'business_id' => $businessId,
                'brand_id' => $attributes['brand_id'] ?? null,
                'customer_id' => $customerId,
                'channel' => $channel->value,
                'external_thread_ref' => $externalThreadRef,
                'subject' => $attributes['subject'] ?? null,
                'status' => 'open',
                // No agent exists in Phase 2.5, so nothing is AI-owned. The default is the truth.
                'owner' => $attributes['owner'] ?? 'none',
                'opened_at' => now(),
                'metadata' => $attributes['metadata'] ?? null,
            ]);
        });
    }

    /** Record something a person outside the group said. Always untrusted. */
    public function recordInbound(Conversation $conversation, string $body, array $attributes = []): ConversationMessage
    {
        return $this->record($conversation, 'inbound', $attributes['author_type'] ?? 'customer', $body, [
            ...$attributes,
            // Not overridable by the caller: an inbound message is untrusted by construction.
            'provenance' => Provenance::Customer->value,
            'received_at' => $attributes['received_at'] ?? now(),
        ]);
    }

    /** Record something the group said. Trusted, because the group wrote it. */
    public function recordOutbound(Conversation $conversation, string $body, array $attributes = []): ConversationMessage
    {
        $authorType = $attributes['author_type'] ?? 'user';

        return $this->record($conversation, 'outbound', $authorType, $body, [
            ...$attributes,
            // An agent's own words are `generated`, which is NOT trusted: a model's prior output can
            // carry an injection it read earlier, and treating it as business data opens a
            // laundering path where untrusted text goes in and a believed summary comes out.
            'provenance' => $attributes['provenance']
                ?? ($authorType === 'agent' ? Provenance::Generated->value : Provenance::Business->value),
            'sent_at' => $attributes['sent_at'] ?? now(),
        ]);
    }

    /** A note nobody outside the group sees. */
    public function recordInternal(Conversation $conversation, string $body, array $attributes = []): ConversationMessage
    {
        return $this->record($conversation, 'internal', $attributes['author_type'] ?? 'user', $body, [
            ...$attributes,
            'provenance' => $attributes['provenance'] ?? Provenance::Business->value,
        ]);
    }

    /** Attach a person to a thread, by the identity they actually arrived through. */
    public function addParticipant(Conversation $conversation, string $type, array $attributes = []): ConversationParticipant
    {
        return ConversationParticipant::create([
            'conversation_id' => $conversation->id,
            'participant_type' => $type,
            'customer_id' => $attributes['customer_id'] ?? null,
            'user_id' => $attributes['user_id'] ?? null,
            'agent_key' => $attributes['agent_key'] ?? null,
            'identity_id' => $attributes['identity_id'] ?? null,
            'role' => $attributes['role'] ?? 'participant',
            'joined_at' => now(),
        ]);
    }

    /**
     * Link the thread to a customer using the identity the message arrived on.
     *
     * Uses the identity model that already exists rather than inventing a second one: the same
     * table already answers "who is messenger_psid 123" for the Phase 1 webhooks.
     */
    public function linkByIdentity(Conversation $conversation, string $identityChannel, string $externalId): ?int
    {
        $identity = CustomerIdentity::query()
            ->where('channel', $identityChannel)
            ->where('external_id', $externalId)
            ->first();

        if ($identity === null) {
            return null;
        }

        if ($conversation->customer_id === null) {
            $conversation->forceFill(['customer_id' => $identity->customer_id])->save();
        }

        return (int) $identity->customer_id;
    }

    /** Hand a thread between AI and a human, recording which way and when. */
    public function handOff(Conversation $conversation, string $to, ?int $userId = null, ?string $agentKey = null): Conversation
    {
        $conversation->forceFill([
            'owner' => $to,
            'handoff_state' => $to === 'human' ? 'accepted' : 'returned',
            'assigned_user_id' => $userId,
            'assigned_agent_key' => $agentKey,
        ])->save();

        return $conversation;
    }

    private function record(Conversation $conversation, string $direction, string $authorType, string $body, array $attributes): ConversationMessage
    {
        return DB::transaction(function () use ($conversation, $direction, $authorType, $body, $attributes) {
            $message = ConversationMessage::create([
                'uuid' => (string) Str::uuid(),
                'conversation_id' => $conversation->id,
                'direction' => $direction,
                'author_type' => $authorType,
                'customer_id' => $attributes['customer_id'] ?? $conversation->customer_id,
                'user_id' => $attributes['user_id'] ?? null,
                'agent_key' => $attributes['agent_key'] ?? null,
                'channel' => $conversation->channel->value,
                'external_message_ref' => $attributes['external_message_ref'] ?? null,
                'body' => $body,
                'provenance' => $attributes['provenance'],
                'ai_run_id' => $attributes['ai_run_id'] ?? null,
                'tool_call_id' => $attributes['tool_call_id'] ?? null,
                'status' => $attributes['status'] ?? 'recorded',
                'sent_at' => $attributes['sent_at'] ?? null,
                'received_at' => $attributes['received_at'] ?? null,
                'metadata' => $attributes['metadata'] ?? null,
            ]);

            $conversation->forceFill(['last_message_at' => now()])->save();

            return $message;
        });
    }
}
