<?php

namespace Tests\Support\Tools;

use App\Gateway\Descriptor\ReadWrite;
use App\Gateway\Descriptor\RiskTier;
use App\Gateway\Descriptor\ToolCardinality;
use App\Gateway\Descriptor\ToolDescriptor;
use App\Gateway\Descriptor\ToolScope;
use App\Gateway\PreExecution\PreExecutionSafetyChecks;
use App\Tools\Tool;
use App\Tools\ToolContext;
use App\Tools\ToolResult;

/**
 * Ruling 31 Oct item 4: registered under the real key `messaging.send_reply` with the real tool's `reply_script` pre-execution
 * check, so a reply option sent in the wrong script is refused by the gateway before it runs. Records what is sent.
 */
final class ScriptCheckedSendReplyTool implements Tool
{
    /** @var list<string> */
    public static array $sent = [];

    public function key(): string
    {
        return 'messaging.send_reply';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => ['business_slug' => ['type' => 'string'], 'conversation_uuid' => ['type' => 'string'], 'text' => ['type' => 'string', 'maxLength' => 2000]], 'required' => ['conversation_uuid', 'text'], 'additionalProperties' => false];
    }

    public function outputSchema(): array
    {
        return ['type' => 'object'];
    }

    public function descriptor(): ToolDescriptor
    {
        return new ToolDescriptor(
            key: $this->key(), version: '1.0.0', title: 'Send a reply', purpose: 'Test double.', riskTier: RiskTier::R3, permission: 'hq.inbox.manage',
            auditAction: 'tool.messaging_send_reply', scope: ToolScope::Business, readWrite: ReadWrite::Write, agentExecutable: true,
            scopeFrom: 'business:args.business_slug', cardinality: ToolCardinality::OnePerBusiness,
            preExecutionChecks: [PreExecutionSafetyChecks::REPLY_SCRIPT],
        );
    }

    public function execute(array $payload, ToolContext $context): ToolResult
    {
        self::$sent[] = (string) $payload['text'];

        return ToolResult::succeeded(['message_uuid' => 'm']);
    }

    public function reconcile(ToolContext $context): ?ToolResult
    {
        return null;
    }
}
