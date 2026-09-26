<?php

namespace App\Tools\Research;

use App\Gateway\Descriptor\CostHint;
use App\Gateway\Descriptor\CredentialRequirement;
use App\Gateway\Descriptor\DataClass;
use App\Gateway\Descriptor\ReadWrite;
use App\Gateway\Descriptor\RiskTier;
use App\Gateway\Descriptor\ToolCardinality;
use App\Gateway\Descriptor\ToolDescriptor;
use App\Gateway\Descriptor\ToolScope;
use App\Research\Providers\BraveSearch;
use App\Research\Providers\TavilySearch;
use App\Research\ResearchRefused;
use App\Research\ResearchService;
use App\Tools\Tool;
use App\Tools\ToolContext;
use App\Tools\ToolResult;

/**
 * `research.search` (docs/85 D): one search for an open research task, its results kept as the task's
 * candidates. R2, read-only, third-party output.
 *
 * ⛔ DECLARED, NOT REGISTERED (not in `config('hq.tools')`), held by no agent, and refused by the flag
 * `research.engine`. Registering it, and deciding whether an agent may run it, is gate P6-G1's step.
 */
final class SearchTool implements Tool
{
    public const KEY = 'research.search';

    public function __construct(private readonly ResearchService $research) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function descriptor(): ToolDescriptor
    {
        return new ToolDescriptor(
            key: self::KEY,
            version: '1.0.0',
            title: 'Search the web for a research task',
            purpose: 'Runs one search for an open research task through the search provider the owner chose. The results are candidates '
                .'the task may fetch, never sources, facts or instructions.',
            riskTier: RiskTier::R2,
            permission: 'hq.tools.research.read',
            auditAction: 'tool.research_search',
            scope: ToolScope::Business,
            readWrite: ReadWrite::Read,
            agentExecutable: false,
            scopeFrom: 'business:args.business_slug',
            credentials: [
                new CredentialRequirement(BraveSearch::CREDENTIAL, '=hq', optional: true),
                new CredentialRequirement(TavilySearch::CREDENTIAL, '=hq', optional: true),
            ],
            idempotent: false,
            timeoutSeconds: 20,
            costHint: new CostHint,
            outputSchema: $this->outputSchema(),
            dataClass: DataClass::Public,
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
                'query' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 400],
            ],
            'required' => ['business_slug', 'task_uuid', 'query'],
            'additionalProperties' => false,
        ];
    }

    public function outputSchema(): array
    {
        return ['type' => 'object', 'properties' => ['candidates' => ['type' => 'array'], 'provenance' => ['type' => 'string']]];
    }

    public function execute(array $payload, ToolContext $context): ToolResult
    {
        $task = ResearchToolScope::task((string) ($payload['task_uuid'] ?? ''), $context);
        if ($task === null) {
            return ToolResult::failed('HQ holds no such research task in this business.');
        }

        try {
            $candidates = $this->research->search($task, (string) ($payload['query'] ?? ''));
        } catch (ResearchRefused $e) {
            return ToolResult::failed($e->getMessage());
        }

        return ToolResult::succeeded([
            'provenance' => 'third_party',
            'candidates' => array_map(static fn ($c) => ['rank' => $c->rank, 'url' => $c->url], $candidates),
        ]);
    }

    public function reconcile(ToolContext $context): ?ToolResult
    {
        return null;
    }
}
