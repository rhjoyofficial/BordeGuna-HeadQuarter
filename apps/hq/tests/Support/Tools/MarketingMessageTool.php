<?php

namespace Tests\Support\Tools;

use App\Consent\ConsentPurpose;
use App\Gateway\Descriptor\Channel;
use App\Gateway\Descriptor\ConsentRequirement;
use App\Gateway\Descriptor\RiskTier;
use App\Gateway\Descriptor\ToolDescriptor;
use App\Gateway\Descriptor\ToolScope;
use App\Tools\Tool;
use App\Tools\ToolContext;
use App\Tools\ToolResult;

/**
 * A stand-in for a future marketing send on WhatsApp (docs/108 §8). It declares the Marketing consent
 * class on `args.customer_id` and counts its executions, so a test can prove the gateway refused it
 * BEFORE it ran. No such tool exists in production: marketing outbound is not activated (ruling §11).
 */
class MarketingMessageTool implements Tool
{
    public static int $executions = 0;

    public function key(): string
    {
        return 'test.marketing_message';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['customer_id' => ['type' => 'integer'], 'text' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 500]],
            'required' => ['customer_id', 'text'],
            'additionalProperties' => false,
        ];
    }

    public function outputSchema(): array
    {
        return ['type' => 'object', 'properties' => ['executions' => ['type' => 'integer']]];
    }

    public function descriptor(): ToolDescriptor
    {
        return new ToolDescriptor(
            key: $this->key(),
            version: '1.0.0',
            title: 'Marketing message (test)',
            purpose: 'Stands in for a marketing send, to prove the consent gate refuses it before it runs.',
            riskTier: RiskTier::R1,
            permission: 'hq.tools.diagnostics',
            auditAction: 'tool.test_marketing_message',
            scope: ToolScope::Hq,
            channel: Channel::Whatsapp,
            consentRequired: new ConsentRequirement(ConsentPurpose::Marketing),
        );
    }

    public function execute(array $payload, ToolContext $context): ToolResult
    {
        self::$executions++;

        return ToolResult::succeeded(['executions' => self::$executions]);
    }

    public function reconcile(ToolContext $context): ?ToolResult
    {
        return null;
    }
}
