<?php

namespace App\Tools\Conversation;

use App\Consent\ConsentPurpose;
use App\Conversation\OutboundPolicy;
use App\Gateway\Descriptor\Channel;
use App\Gateway\Descriptor\ConsentRequirement;
use App\Gateway\Descriptor\DataClass;
use App\Gateway\Descriptor\ReadWrite;
use App\Gateway\Descriptor\Reversibility;
use App\Gateway\Descriptor\RiskTier;
use App\Gateway\Descriptor\ToolCardinality;
use App\Gateway\Descriptor\ToolDescriptor;
use App\Gateway\Descriptor\ToolScope;
use App\Models\Conversation;
use App\Models\PlatformAccount;
use App\Platform\CapabilityResolver;
use App\Platform\Contracts\MessagingChannel;
use App\Tools\Tool;
use App\Tools\ToolContext;
use App\Tools\ToolResult;

/**
 * Sends one reply to the person on a thread (docs/72 §B). In this milestone, it cannot.
 *
 * ⭐ The inert guarantee is structural. The descriptor is customer-facing and consent-gated, so the
 * gateway checks the Service consent on the thread's channel before `execute()` is reached; then the
 * outbound policy runs; then the thread's platform account is resolved through `CapabilityResolver`,
 * and because `PlatformRegistry` ships with no provider registered, the capability is absent and
 * the tool fails for everyone, human included. No flag has to be remembered; there is nothing that
 * could send.
 *
 * An agent at A0 that names this tool produces a PROPOSAL and no execution (`AgentRunner::hasEffect`):
 * that is the P3-G2 shadow, and it needs no provider either.
 *
 * ⚠ The descriptor's channel is Messenger, the first channel Phase 3 serves. The consent check uses
 * it; a WhatsApp thread would need its own tool, which is the right shape (ADR-026: the two are not
 * one action with a different transport).
 */
final class SendReplyTool implements Tool
{
    public const NO_PROVIDER = 'No messaging provider is registered for this channel; sending is not enabled (P3-G4).';

    public function __construct(
        private readonly OutboundPolicy $policy,
        private readonly CapabilityResolver $capabilities,
    ) {}

    public function key(): string
    {
        return 'messaging.send_reply';
    }

    public function descriptor(): ToolDescriptor
    {
        return new ToolDescriptor(
            key: $this->key(),
            version: '1.0.0',
            title: 'Send a reply to a customer',
            purpose: 'Delivers one reply, in the customer\'s own language, to the person on a conversation thread. Customer-facing and '
                .'consent-gated. An agent proposes it; whether it runs is decided by the control plane.',
            riskTier: RiskTier::R3,
            permission: 'hq.inbox.manage',
            auditAction: 'tool.messaging_send_reply',
            scope: ToolScope::Business,
            readWrite: ReadWrite::Write,
            agentExecutable: true,
            scopeFrom: 'business:args.business_slug',
            idempotent: true,
            idempotencyKeyFrom: ['conversation_uuid', 'text'],
            timeoutSeconds: 20,
            outputSchema: $this->outputSchema(),
            channel: Channel::Messenger,
            consentRequired: new ConsentRequirement(ConsentPurpose::Service, null, 'conversation:args.conversation_uuid'),
            customerFacing: true,
            reversibility: Reversibility::Irreversible,
            dataClass: DataClass::Personal,
            cardinality: ToolCardinality::OnePerBusiness,
            // docs/88 point 12: what running it IS, for the role boundary. The Support role permits
            // it; the Executive's and the Analytics role's forbid it at the gateway, whatever they hold.
            roleAction: 'customer_reply',
        );
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'business_slug' => ['type' => 'string', 'maxLength' => 64],
                'conversation_uuid' => ['type' => 'string', 'maxLength' => 64],
                'text' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 2000],
            ],
            'required' => ['conversation_uuid', 'text'],
            'additionalProperties' => false,
        ];
    }

    public function outputSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'message_uuid' => ['type' => 'string'],
            'delivery' => ['type' => 'object'],
        ]];
    }

    public function execute(array $payload, ToolContext $context): ToolResult
    {
        $conversation = Conversation::query()->where('uuid', (string) ($payload['conversation_uuid'] ?? ''))->first();

        if ($conversation === null || ($context->businessId !== null && $conversation->business_id !== $context->businessId)
            || ($context->permittedBusinessIds !== null && ! in_array($conversation->business_id, $context->permittedBusinessIds, true))) {
            return ToolResult::failed('HQ holds no such conversation in this business.');
        }

        if ($conversation->status === 'closed') {
            return ToolResult::failed('The conversation is closed; nothing is sent to a closed thread.');
        }

        // The policy runs before any provider is looked up, so a refusal here costs nothing and
        // reaches nobody.
        $refusal = $this->policy->refusal($conversation, humanAuthor: $context->actorUserId !== null);
        if ($refusal !== null) {
            return ToolResult::failed('The outbound policy refuses this reply: '.$refusal);
        }

        $account = $conversation->platformAccount
            ?? PlatformAccount::query()->where('brand_id', $conversation->brand_id)->where('platform', 'facebook_page')->where('is_active', true)->first();
        if ($account === null) {
            return ToolResult::failed('The thread names no platform account HQ could send from.');
        }

        $resolution = $this->capabilities->resolve($account);
        if (! $resolution->supports(MessagingChannel::SEND_SERVICE)) {
            // Absent, not annotated (ADR-022): the reason is kept beside the refusal for the operator.
            return ToolResult::failed(self::NO_PROVIDER.' ('.($resolution->reasonFor(MessagingChannel::SEND_SERVICE) ?? 'no reason recorded').')');
        }

        // Unreachable until a provider is registered at gate P3-G4, and written so that the gate has a
        // single place to put the adapter call, the outbound record and the delivery row.
        return ToolResult::failed('A messaging provider is registered but no outbound adapter is wired for it yet (P3-G4).');
    }

    public function reconcile(ToolContext $context): ?ToolResult
    {
        return null;
    }
}
