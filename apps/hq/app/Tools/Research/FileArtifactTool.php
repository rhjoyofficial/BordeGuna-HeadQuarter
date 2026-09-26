<?php

namespace App\Tools\Research;

use App\Gateway\Descriptor\DataClass;
use App\Gateway\Descriptor\ReadWrite;
use App\Gateway\Descriptor\RiskTier;
use App\Gateway\Descriptor\ToolCardinality;
use App\Gateway\Descriptor\ToolDescriptor;
use App\Gateway\Descriptor\ToolScope;
use App\Models\User;
use App\Research\ArtifactFiler;
use App\Research\ResearchRefused;
use App\Tools\Tool;
use App\Tools\ToolContext;
use App\Tools\ToolResult;

/**
 * `research.file_artifact` (docs/85 D): files the append-only artifact of a task, every claim typed and
 * every fact checked against its source's span. R3 internal write.
 *
 * ⛔ DECLARED, NOT REGISTERED, held by no agent, refused by the flag `research.engine`.
 */
final class FileArtifactTool implements Tool
{
    public const KEY = 'research.file_artifact';

    public function __construct(private readonly ArtifactFiler $filer) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function descriptor(): ToolDescriptor
    {
        return new ToolDescriptor(
            key: self::KEY,
            version: '1.0.0',
            title: 'File a research artifact',
            purpose: 'Files the append-only record of a research task: every claim labelled fact, metric, inference or recommendation, and '
                .'every fact tied to the exact words of the page it came from.',
            riskTier: RiskTier::R3,
            permission: 'hq.tools.research.read',
            auditAction: 'tool.research_file_artifact',
            scope: ToolScope::Business,
            readWrite: ReadWrite::Write,
            agentExecutable: false,
            scopeFrom: 'business:args.business_slug',
            idempotent: true,
            idempotencyKeyFrom: ['task_uuid', 'claims'],
            timeoutSeconds: 20,
            outputSchema: $this->outputSchema(),
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
                'task_uuid' => ['type' => 'string', 'maxLength' => 64],
                'claims' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 60],
                'discarded' => ['type' => 'array', 'maxItems' => 60],
            ],
            'required' => ['business_slug', 'task_uuid', 'claims'],
            'additionalProperties' => false,
        ];
    }

    public function outputSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'artifact_uuid' => ['type' => 'string'], 'version' => ['type' => 'integer'], 'digest' => ['type' => 'string'],
        ]];
    }

    public function execute(array $payload, ToolContext $context): ToolResult
    {
        $task = ResearchToolScope::task((string) ($payload['task_uuid'] ?? ''), $context);
        if ($task === null) {
            return ToolResult::failed('HQ holds no such research task in this business.');
        }

        try {
            $artifact = $this->filer->file(
                $task,
                array_values((array) ($payload['claims'] ?? [])),
                array_values((array) ($payload['discarded'] ?? [])),
                $context->actorUserId !== null ? User::query()->find($context->actorUserId) : null,
            );
        } catch (ResearchRefused $e) {
            return ToolResult::failed($e->getMessage());
        }

        return ToolResult::succeeded(['artifact_uuid' => $artifact->uuid, 'version' => $artifact->version, 'digest' => $artifact->digest]);
    }

    public function reconcile(ToolContext $context): ?ToolResult
    {
        return null;
    }
}
