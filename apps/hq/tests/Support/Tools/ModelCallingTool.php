<?php

namespace Tests\Support\Tools;

use App\Ai\ModelRuntime;
use App\Ai\RoutingContext;
use App\Ai\TaskType;
use App\Gateway\Descriptor\ReadWrite;
use App\Gateway\Descriptor\RiskTier;
use App\Gateway\Descriptor\ToolDescriptor;
use App\Gateway\Descriptor\ToolScope;
use App\Tools\Tool;
use App\Tools\ToolContext;
use App\Tools\ToolResult;

/**
 * docs/141: a scheduled job whose tool asks a model. No production tool does this today (the briefing's
 * narrator is `NoNarrator`), so the test brings its own, to prove that a scheduled run after Pause All AI
 * reaches no provider whatever its tool does.
 */
final class ModelCallingTool implements Tool
{
    public function key(): string
    {
        return 'test.model_calling';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
    }

    public function outputSchema(): array
    {
        return ['type' => 'object', 'properties' => ['text' => ['type' => 'string']]];
    }

    public function descriptor(): ToolDescriptor
    {
        return new ToolDescriptor(
            key: $this->key(),
            version: '1.0.0',
            title: 'Model calling tool',
            purpose: 'Asks a model once, so a scheduled run that reaches a provider can be tested.',
            riskTier: RiskTier::R1,
            permission: 'hq.tools.diagnostics',
            auditAction: 'tool.test_model_calling',
            scope: ToolScope::Hq,
            readWrite: ReadWrite::Read,
        );
    }

    public function execute(array $payload, ToolContext $context): ToolResult
    {
        $outcome = app(ModelRuntime::class)->run(
            TaskType::OperatorQuestion, 'sys', [['role' => 'user', 'content' => 'summarise']],
            new RoutingContext(correlationId: $context->correlationId, inputRef: 'test:model_calling'),
        );

        return ToolResult::succeeded(['text' => $outcome->text]);
    }

    public function reconcile(ToolContext $context): ?ToolResult
    {
        return null;
    }
}
