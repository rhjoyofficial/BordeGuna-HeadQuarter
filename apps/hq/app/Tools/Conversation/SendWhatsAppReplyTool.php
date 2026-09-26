<?php

namespace App\Tools\Conversation;

use App\Consent\ConsentPurpose;
use App\Conversation\Channels\OutboundTemplate;
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
use App\Models\ConversationMessage;
use App\Models\PlatformAccount;
use App\Platform\CapabilityResolver;
use App\Platform\Contracts\MessagingChannel;
use App\Tools\Tool;
use App\Tools\ToolContext;
use App\Tools\ToolResult;
use Carbon\CarbonImmutable;

/**
 * Sends one WhatsApp message to the person on a WhatsApp thread (docs/88 A point 2). Today, it cannot.
 *
 * ADR-026: a WhatsApp reply is its own action, not the Messenger tool with another transport. It has
 * its own consent channel, its own capability (`send_template` outside the window), its own cost, and
 * Meta's rule that "when the window closes, you can only send pre-approved template messages". So:
 *
 *  - free text only inside the customer service window: 24 hours from the customer's LAST message on
 *    this thread, read from the thread, never from an argument;
 *  - outside it, only a template from HQ's allow-list (`config('hq.messaging.whatsapp.templates')`,
 *    EMPTY), in the language it was approved in, and never a `marketing` one: a customer's own message
 *    grants Service consent, which marketing is not;
 *  - the gateway checks Service consent on the thread's person on the WhatsApp channel before this runs.
 *    The consent a customer's own message grants lasts only the window (ConversationService::ingest), so
 *    outside it a template also needs a standing opt-in (Explicit Service consent on WhatsApp): without
 *    one the gateway refuses before this class is reached, which is Meta's opt-in rule for messages the
 *    business starts;
 *  - the outbound policy, then the capability resolver: the number must hold `send_service_message` for
 *    text, `send_template` for a template.
 *
 * ⭐ INERT, structurally, twice over. `PlatformRegistry` ships empty, so the capability is absent and the
 * tool fails for everyone, human included. And `agentExecutable` is FALSE and no agent's `allowed_tools`
 * names it: an agent cannot even propose it. Making it agent-executable or registering the WhatsApp
 * adapter is a gate decision (docs/88 A points 1, 8), recorded for Gate 13, never a side effect of this
 * build.
 */
final class SendWhatsAppReplyTool implements Tool
{
    public const NO_PROVIDER = 'No WhatsApp provider is registered for this number; sending is not enabled (the WhatsApp gate).';

    public const WINDOW_HOURS = 24;

    public function __construct(
        private readonly OutboundPolicy $policy,
        private readonly CapabilityResolver $capabilities,
    ) {}

    public function key(): string
    {
        return 'messaging.send_whatsapp_reply';
    }

    public function descriptor(): ToolDescriptor
    {
        return new ToolDescriptor(
            key: $this->key(),
            version: '1.0.0',
            title: 'Send a WhatsApp message to a customer',
            purpose: 'Delivers one WhatsApp message to the person on a WhatsApp thread: free text inside the customer\'s 24-hour service '
                .'window, an approved non-marketing template outside it. Customer-facing and consent-gated.',
            riskTier: RiskTier::R3,
            permission: 'hq.inbox.manage',
            auditAction: 'tool.messaging_send_whatsapp_reply',
            scope: ToolScope::Business,
            readWrite: ReadWrite::Write,
            agentExecutable: false,
            scopeFrom: 'business:args.business_slug',
            idempotent: true,
            idempotencyKeyFrom: ['conversation_uuid', 'text', 'template_name', 'template_language', 'template_parameters'],
            timeoutSeconds: 20,
            outputSchema: $this->outputSchema(),
            channel: Channel::Whatsapp,
            // docs/88 point 12: what running it IS, for the role boundary (stream W). The Support role permits a
            // customer reply; the Executive's and the Analytics role's forbid it at the gateway, whatever they hold.
            roleAction: 'customer_reply',
            consentRequired: new ConsentRequirement(ConsentPurpose::Service, null, 'conversation:args.conversation_uuid'),
            customerFacing: true,
            reversibility: Reversibility::Irreversible,
            dataClass: DataClass::Personal,
            cardinality: ToolCardinality::OnePerBusiness,
        );
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'business_slug' => ['type' => 'string', 'maxLength' => 64],
                'conversation_uuid' => ['type' => 'string', 'maxLength' => 64],
                'text' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 4096],
                // Flat, because the gateway's argument validator reads flat properties only; the values are checked below.
                'template_name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 512],
                'template_language' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 16],
                'template_parameters' => ['type' => 'array'],
            ],
            'required' => ['conversation_uuid'],
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
        $text = isset($payload['text']) ? (string) $payload['text'] : null;
        $template = isset($payload['template_name']) ? [
            'name' => (string) $payload['template_name'],
            'language' => (string) ($payload['template_language'] ?? ''),
            'parameters' => $payload['template_parameters'] ?? [],
        ] : null;
        if (($text === null) === ($template === null)) {
            return ToolResult::failed('Give exactly one of a text or a template.');
        }

        $conversation = Conversation::query()->where('uuid', (string) ($payload['conversation_uuid'] ?? ''))->first();
        if ($conversation === null || ($context->businessId !== null && $conversation->business_id !== $context->businessId)
            || ($context->permittedBusinessIds !== null && ! in_array($conversation->business_id, $context->permittedBusinessIds, true))) {
            return ToolResult::failed('HQ holds no such conversation in this business.');
        }

        if ($conversation->channel !== Channel::Whatsapp) {
            return ToolResult::failed("This thread is on {$conversation->channel->value}; a WhatsApp message goes only to a WhatsApp thread.");
        }

        if ($conversation->status === 'closed') {
            return ToolResult::failed('The conversation is closed; nothing is sent to a closed thread.');
        }

        $refusal = $this->policy->refusal($conversation, humanAuthor: $context->actorUserId !== null);
        if ($refusal !== null) {
            return ToolResult::failed('The outbound policy refuses this message: '.$refusal);
        }

        $windowOpen = $this->windowOpen($conversation);
        if ($text !== null && ! $windowOpen) {
            return ToolResult::failed('Outside the 24-hour customer service window: the customer last wrote more than '.self::WINDOW_HOURS
                .' hours ago, or never on this thread. WhatsApp allows only a pre-approved template now.');
        }

        $capability = MessagingChannel::SEND_SERVICE;
        if ($template !== null) {
            $resolved = $this->template($template);
            if (is_string($resolved)) {
                return ToolResult::failed($resolved);
            }
            $capability = MessagingChannel::SEND_TEMPLATE;
        }

        $account = $conversation->platformAccount
            ?? PlatformAccount::query()->where('brand_id', $conversation->brand_id)->where('platform', 'whatsapp_number')->where('is_active', true)->first();
        if ($account === null) {
            return ToolResult::failed('The thread names no WhatsApp number HQ could send from.');
        }

        $resolution = $this->capabilities->resolve($account);
        if (! $resolution->supports($capability)) {
            return ToolResult::failed(self::NO_PROVIDER.' ('.($resolution->reasonFor($capability) ?? 'no reason recorded').')');
        }

        // Unreachable until a provider is registered at the WhatsApp gate; the single place for the
        // recorded outbound message, `ChannelSender` and the delivery row.
        return ToolResult::failed('A WhatsApp provider is registered but no outbound adapter is wired for it yet (the WhatsApp gate).');
    }

    public function reconcile(ToolContext $context): ?ToolResult
    {
        return null;
    }

    /** The customer service window, from the customer's own last message on this thread. */
    public function windowOpen(Conversation $conversation, ?CarbonImmutable $now = null): bool
    {
        $last = ConversationMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', 'inbound')
            ->whereNotNull('received_at')
            ->max('received_at');

        if ($last === null) {
            return false;
        }

        return CarbonImmutable::parse($last, 'UTC')->greaterThan(($now ?? CarbonImmutable::now('UTC'))->utc()->subHours(self::WINDOW_HOURS));
    }

    /** @return OutboundTemplate|string the allowed template, or why it is refused */
    private function template(array $given): OutboundTemplate|string
    {
        $name = (string) ($given['name'] ?? '');
        $language = (string) ($given['language'] ?? '');
        $allowed = config("hq.messaging.whatsapp.templates.{$name}");

        if (! is_array($allowed)) {
            return "The template [{$name}] is not on HQ's WhatsApp template list; only a template Meta approved and HQ lists may be sent.";
        }
        if (($allowed['language'] ?? null) !== $language) {
            return "The template [{$name}] is listed in [".($allowed['language'] ?? '?')."], not [{$language}].";
        }
        $category = (string) ($allowed['category'] ?? '');
        if ($category === OutboundTemplate::MARKETING || ! in_array($category, [OutboundTemplate::UTILITY, OutboundTemplate::AUTHENTICATION], true)) {
            return "The template [{$name}] is a [{$category}] template; a marketing message needs the customer's marketing consent and is not a reply.";
        }
        $raw = (array) ($given['parameters'] ?? []);
        foreach ($raw as $value) {
            if (! is_string($value) || mb_strlen($value) > 1024) {
                return 'Every template value must be text of at most 1,024 characters.';
            }
        }
        $parameters = array_values($raw);
        if (count($parameters) !== (int) ($allowed['parameters'] ?? 0)) {
            return "The template [{$name}] takes ".(int) ($allowed['parameters'] ?? 0).' values; '.count($parameters).' were given.';
        }

        return new OutboundTemplate($name, $language, $category, $parameters);
    }
}
