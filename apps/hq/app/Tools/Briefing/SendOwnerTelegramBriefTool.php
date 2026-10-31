<?php

namespace App\Tools\Briefing;

use App\Gateway\Descriptor\Channel;
use App\Gateway\Descriptor\DataClass;
use App\Gateway\Descriptor\ReadWrite;
use App\Gateway\Descriptor\Reversibility;
use App\Gateway\Descriptor\RiskTier;
use App\Gateway\Descriptor\ToolCardinality;
use App\Gateway\Descriptor\ToolDescriptor;
use App\Gateway\Descriptor\ToolScope;
use App\Models\Briefing;
use App\Models\Business;
use App\Models\OwnerBriefDelivery;
use App\OwnerConsole\Brief\BriefMessage;
use App\OwnerConsole\Brief\OwnerChannel;
use App\OwnerConsole\Flag;
use App\Tools\Tool;
use App\Tools\ToolContext;
use App\Tools\ToolResult;
use Illuminate\Support\Str;

/**
 * `briefing.send_owner_telegram` (docs/143 D, docs/138 ruling 10): sends one STORED briefing to the owner's
 * own Telegram chat. Tier R4, so the gateway parks every call on an approval (and a scheduled job that runs
 * it is itself created waiting for approval); not agent-executable.
 *
 * The arguments name a briefing (business and date) and nothing else: there is no destination argument and
 * `additionalProperties` is false, so neither a model nor an untrusted message can choose where it goes. The
 * chat comes from configuration and is validated by the channel (a private id the owner confirmed).
 *
 * The flag `console.telegram_brief` is OFF: with it off the tool fails before it reads a briefing. Scope:
 * the business is REQUIRED and is the tool's scope, so the gateway refuses, for the person who asks, a business they
 * may not act for BEFORE anything is parked (an approval runs under the approver's authority, so the requester's scope
 * must be enforced at request time). The group-wide briefing has no tool in v1. One delivery per briefing: a second call replays the
 * recorded result and sends nothing.
 */
final class SendOwnerTelegramBriefTool implements Tool
{
    public const KEY = 'briefing.send_owner_telegram';

    public function __construct(private readonly OwnerChannel $channel) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function descriptor(): ToolDescriptor
    {
        return new ToolDescriptor(
            key: self::KEY,
            version: '1.0.0',
            title: 'Send the morning brief to the owner on Telegram',
            purpose: "Sends one stored briefing, as a short minimised text, to the owner's own confirmed private Telegram chat. The destination is not an argument.",
            riskTier: RiskTier::R4,
            permission: 'hq.briefing.deliver',
            auditAction: 'tool.briefing_send_owner_telegram',
            scope: ToolScope::Business,
            scopeFrom: 'business:args.business_slug',
            readWrite: ReadWrite::Write,
            agentExecutable: false,
            idempotent: true,
            idempotencyKeyFrom: ['business_slug', 'date'],
            timeoutSeconds: 20,
            outputSchema: $this->outputSchema(),
            channel: Channel::Internal,
            reversibility: Reversibility::Irreversible,
            dataClass: DataClass::Internal,
            cardinality: ToolCardinality::OnePerBusiness,
        );
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'business_slug' => ['type' => 'string', 'maxLength' => 64],
                'date' => ['type' => 'string', 'maxLength' => 10],
            ],
            'required' => ['business_slug'],
            'additionalProperties' => false,
        ];
    }

    public function outputSchema(): array
    {
        return ['type' => 'object', 'properties' => ['delivery_uuid' => ['type' => 'string'], 'channel' => ['type' => 'string']]];
    }

    public function execute(array $payload, ToolContext $context): ToolResult
    {
        if (! Flag::on(Flag::TELEGRAM_BRIEF)) {
            return ToolResult::failed('The Telegram brief is switched off (console.telegram_brief).');
        }

        $business = null;
        if (! empty($payload['business_slug'])) {
            $business = Business::query()->where('slug', (string) $payload['business_slug'])->first();
            if ($business === null) {
                return ToolResult::failed('HQ holds no such business.');
            }
        }
        // A group-wide briefing (no business) is for a group-wide caller only; a restricted caller names a permitted business.
        if ($context->permittedBusinessIds !== null && ($business === null || ! in_array($business->id, $context->permittedBusinessIds, true))) {
            return ToolResult::failed('HQ holds no such briefing for you.');
        }

        $date = (string) ($payload['date'] ?? now((string) config('hq.timezone', 'Asia/Dhaka'))->toDateString());
        $briefing = Briefing::query()->whereDate('briefing_date', $date)
            ->when($business !== null, fn ($q) => $q->where('business_id', $business->id), fn ($q) => $q->whereNull('business_id'))
            ->latest('id')->first();
        if ($briefing === null) {
            return ToolResult::failed('No stored briefing for that business and date; compose it first (briefing.compose).');
        }

        $key = 'owner-brief:'.$briefing->uuid;
        $existing = OwnerBriefDelivery::query()->where('idempotency_key', $key)->first();
        if ($existing !== null && $existing->state === 'sent') {
            return ToolResult::succeeded(['delivery_uuid' => $existing->uuid, 'channel' => $this->channel->name(), 'replayed' => true]);
        }

        $text = BriefMessage::build($briefing, $business?->name);
        $result = $this->channel->send($text);

        $row = $existing ?? new OwnerBriefDelivery(['uuid' => (string) Str::uuid(), 'briefing_id' => $briefing->id, 'idempotency_key' => $key]);
        $row->forceFill([
            'business_id' => $business?->id, 'destination' => 'owner_chat', 'requested_by_user_id' => $context->actorUserId,
            'state' => $result->ok ? 'sent' : ($result->uncertain ? 'uncertain' : 'failed'), 'message_digest' => hash('sha256', $text),
            'provider_ref' => $result->ref, 'error' => $result->error, 'sent_at' => $result->ok ? now() : null,
        ])->save();

        if ($result->ok) {
            return ToolResult::succeeded(['delivery_uuid' => $row->uuid, 'channel' => $this->channel->name()], $result->ref);
        }

        return $result->uncertain
            ? ToolResult::uncertain($result->error ?? 'delivery unknown')
            : ToolResult::failed($result->error ?? 'The owner channel refused the message.');
    }

    /** A delivery row saying `sent` settles a retry in `execute`; anything else is not known to have been delivered. */
    public function reconcile(ToolContext $context): ?ToolResult
    {
        return null;
    }
}
