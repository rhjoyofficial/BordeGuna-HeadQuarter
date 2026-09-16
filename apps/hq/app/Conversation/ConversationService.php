<?php

namespace App\Conversation;

use App\Consent\ConsentBasis;
use App\Consent\ConsentLedger;
use App\Consent\ConsentPurpose;
use App\Consent\ConsentState;
use App\Context\Provenance;
use App\Conversation\Channels\InboundMessage;
use App\Gateway\Descriptor\Channel;
use App\Identity\IdentityResolver;
use App\Models\Agent;
use App\Models\Business;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationParticipant;
use App\Models\CustomerIdentity;
use App\Models\PlatformAccount;
use App\Support\Events\EventRecorder;
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
    /** The agent that would answer a customer thread when it is active. Paused for all of this milestone. */
    public const SUPPORT_AGENT = 'support';

    /**
     * Which kind of platform account a channel's messages arrive on, so a Messenger page id is only
     * ever matched against Facebook Pages and never against a pixel or an ad account that happens to
     * share a number. A channel not listed here matches on the reference alone.
     */
    public const PLATFORM_FOR_CHANNEL = [
        'messenger' => 'facebook_page',
        'instagram' => 'instagram_account',
        'comment' => 'facebook_page',
        'whatsapp' => 'whatsapp_number',
    ];

    public function __construct(
        private IdentityResolver $identity,
        private ConsentLedger $consent,
        private EventRecorder $events,
        private HandoffStateMachine $states,
        private EscalationTriggers $triggers,
    ) {}

    /**
     * One inbound message, from any channel, into the conversation core (docs/72 §B).
     *
     * In order: the page or number it was sent to says which business and brand (never guessed; an
     * unknown reference throws); the thread is found or opened; the sender becomes a customer, a
     * stranger if need be; the message is recorded UNTRUSTED with its script tagged; a Service consent
     * with basis Inbound is written for this channel for the reply window; the state machine decides
     * whether a person is needed; and the event goes out. Idempotent on the provider's message id, so a
     * redelivered webhook creates nothing.
     */
    public function ingest(InboundMessage $inbound): ConversationMessage
    {
        $existing = ConversationMessage::query()
            ->where('channel', $inbound->channel->value)
            ->where('external_message_ref', $inbound->externalMessageRef)
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        $account = PlatformAccount::query()
            ->where('account_ref', $inbound->platformAccountRef)
            ->when(self::PLATFORM_FOR_CHANNEL[$inbound->channel->value] ?? null, fn ($q, $platform) => $q->where('platform', $platform))
            ->where('is_active', true)
            ->with('brand')
            ->first();
        if ($account === null || $account->brand === null) {
            throw new UnknownPlatformAccount(
                "No active platform account holds the reference [{$inbound->platformAccountRef}] on [{$inbound->channel->value}], "
                .'so HQ cannot say which business this message is for and will not guess.'
            );
        }
        $business = Business::query()->findOrFail($account->brand->business_id);

        return DB::transaction(function () use ($inbound, $account, $business) {
            // ⚠ UTC before anything is stored or bound: a provider timestamp carrying +06:00 written
            // as-is would be read back six hours late by every UTC column.
            $receivedAt = $inbound->receivedAt->utc();
            // A channel that names the sender (a comment carries `from.name`) gives a new customer a name;
            // it is the customer's own claim about themselves, kept as a name and nothing more.
            $profile = is_string($inbound->metadata['sender_name'] ?? null) ? ['name' => $inbound->metadata['sender_name']] : [];
            $customer = $this->identity->resolveChannelIdentity($inbound->channel, $inbound->senderExternalId, $business, $profile);
            $agentActive = Agent::query()->where('key', self::SUPPORT_AGENT)->where('status', 'active')->exists();

            $conversation = $this->openOrFind($business->id, $inbound->channel, $inbound->externalThreadRef, $customer->id, [
                'brand_id' => $account->brand_id,
                'owner' => 'human',
            ]);

            $fill = [];
            if ($conversation->platform_account_id === null) {
                $fill['platform_account_id'] = $account->id;
            }
            if ($conversation->wasRecentlyCreated) {
                $opening = $this->states->openingState($agentActive, false);
                $fill['state'] = $opening->value;
                $fill['owner'] = $opening->owner();
            }
            if ($fill !== []) {
                $conversation->forceFill($fill)->save();
            }

            $body = Language::normalise($inbound->body);
            $language = Language::detect($body);

            $message = $this->recordInbound($conversation, (string) $body, [
                'customer_id' => $customer->id,
                'external_message_ref' => $inbound->externalMessageRef,
                'received_at' => $receivedAt,
                'metadata' => array_filter([
                    'language' => $language,
                    'digest' => $inbound->digest,
                    'attachments' => $inbound->attachments,
                    'sender_external_id' => $inbound->senderExternalId,
                ] + $inbound->metadata, static fn ($v) => $v !== null && $v !== []),
            ]);

            if ($language !== null && $conversation->language === null) {
                $conversation->forceFill(['language' => $language])->save();
            }

            $identity = CustomerIdentity::query()
                ->where('channel', (string) $inbound->channel->identityChannel())
                ->where('external_id', $inbound->senderExternalId)
                ->first();
            $alreadyIn = ConversationParticipant::query()
                ->where('conversation_id', $conversation->id)
                ->where('participant_type', 'customer')
                ->where('customer_id', $customer->id)
                ->exists();
            if (! $alreadyIn) {
                $this->addParticipant($conversation, 'customer', ['customer_id' => $customer->id, 'identity_id' => $identity?->id]);
            }

            // Writing to us on a channel is consent to be answered on it, for the window the channel
            // itself defines, and to nothing else. Marketing is never inferred from a message.
            $this->consent->record(
                customerId: $customer->id,
                businessId: $business->id,
                purpose: ConsentPurpose::Service,
                state: ConsentState::Granted,
                basis: ConsentBasis::Inbound,
                channel: $inbound->channel->value,
                occurredAt: $receivedAt,
                attributes: [
                    'brand_id' => $account->brand_id,
                    'expires_at' => $receivedAt->addHours((int) config('hq.outbound.reply_window_hours', 24)),
                    'evidence_ref' => "conversation_message:{$message->uuid}",
                    'reason' => 'the customer wrote to us on this channel',
                    'correlation_id' => $conversation->uuid,
                ],
            );

            $reason = $this->triggers->reasonFor($conversation, $body, $agentActive);
            if ($reason !== null && $conversation->state->canMoveTo(ConversationState::HumanRequired)) {
                $this->states->transition($conversation, ConversationState::HumanRequired, null, $reason);
            } elseif ($reason === null && $conversation->state === ConversationState::AiWaiting) {
                $this->states->transition($conversation, ConversationState::AiActive, null, 'the customer replied');
            } elseif ($reason === null && $conversation->state === ConversationState::HumanResolved) {
                // A person closed the matter and the customer came back; the active agent takes it.
                $this->states->transition($conversation, ConversationState::AiResumed, null, 'the customer wrote again after a person resolved the thread', self::SUPPORT_AGENT);
            }

            $this->events->record(
                type: 'conversation.message_received',
                payload: [
                    'conversation_uuid' => $conversation->uuid,
                    'message_uuid' => $message->uuid,
                    'channel' => $inbound->channel->value,
                    'language' => $language,
                    'has_text' => $inbound->hasText(),
                    'digest' => $inbound->digest,
                    'state' => $conversation->fresh()->state->value,
                ],
                source: 'channel:'.$inbound->channel->value,
                direction: 'inbound',
                aggregateType: 'conversation',
                aggregateId: $conversation->uuid,
                businessId: $business->id,
                dedupeKey: "conversation:message:{$inbound->channel->value}:{$inbound->externalMessageRef}",
                occurredAt: $receivedAt,
            );

            return $message;
        });
    }

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
