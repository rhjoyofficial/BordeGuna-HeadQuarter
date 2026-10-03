<?php

namespace Tests\Support\Tools;

use App\Gateway\Descriptor\ReadWrite;
use App\Gateway\Descriptor\RiskTier;
use App\Gateway\Descriptor\ToolDescriptor;
use App\Gateway\Descriptor\ToolScope;
use App\Gateway\PreExecution\PreExecutionSafetyChecks;
use App\Tools\Tool;
use App\Tools\ToolContext;
use App\Tools\ToolResult;

/**
 * A reply-shaped tool that opts into the `reply_script` pre-execution check and counts its executions
 * (docs/97 §6 D4). Subclasses add one more thing the gateway decides BEFORE the script check (a
 * credential, a consent requirement), so a test can put two refusals on one call and see which wins.
 */
class ScriptedReplyTool implements Tool
{
    public static int $executions = 0;

    public function key(): string
    {
        return 'test.scripted_reply';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['text' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 500], 'account' => ['type' => 'string', 'maxLength' => 32]],
            'required' => ['text'],
            'additionalProperties' => false,
        ];
    }

    public function outputSchema(): array
    {
        return ['type' => 'object', 'properties' => ['executions' => ['type' => 'integer']]];
    }

    public function descriptor(): ToolDescriptor
    {
        return new ToolDescriptor(...$this->descriptorArguments());
    }

    /** @return array<string, mixed> */
    protected function descriptorArguments(): array
    {
        return [
            'key' => $this->key(),
            'version' => '1.0.0',
            'title' => 'Scripted reply (test)',
            'purpose' => 'A reply-shaped test tool whose text is checked for its script before it runs.',
            'riskTier' => RiskTier::R1,
            'permission' => 'hq.tools.diagnostics',
            'auditAction' => 'tool.test_scripted_reply',
            'scope' => ToolScope::Hq,
            'readWrite' => ReadWrite::Read,
            'preExecutionChecks' => [PreExecutionSafetyChecks::REPLY_SCRIPT],
        ];
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
