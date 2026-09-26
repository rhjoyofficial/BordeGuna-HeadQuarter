<?php

namespace App\Tools\Content;

use App\Content\ContentDraftService;
use App\Gateway\Descriptor\DataClass;
use App\Gateway\Descriptor\ReadWrite;
use App\Gateway\Descriptor\RiskTier;
use App\Gateway\Descriptor\ToolCardinality;
use App\Gateway\Descriptor\ToolDescriptor;
use App\Gateway\Descriptor\ToolScope;
use App\Models\Brand;
use App\Models\ContentBrief;
use App\Models\ResearchArtifact;
use App\Models\User;
use App\Research\ResearchRefused;
use App\Tools\Tool;
use App\Tools\ToolContext;
use App\Tools\ToolResult;

/**
 * `content.create_draft` (docs/85 D): makes one checked draft inside HQ. R3; nothing leaves HQ.
 *
 * ⛔ DECLARED, NOT REGISTERED, held by no agent (never the Executive), refused by the flag
 * `content.drafting`.
 */
final class CreateDraftTool implements Tool
{
    public const KEY = 'content.create_draft';

    public function __construct(private readonly ContentDraftService $drafts) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function descriptor(): ToolDescriptor
    {
        return new ToolDescriptor(
            key: self::KEY,
            version: '1.0.0',
            title: 'Make a checked content draft',
            purpose: 'Makes one draft post from an approved brief, product facts read from the ERP or the shop, and cited research. It is '
                .'checked (figures, claims policy, banned words) and stored for a person; it is never published by this tool.',
            riskTier: RiskTier::R3,
            permission: 'hq.tools.content.write',
            auditAction: 'tool.content_create_draft',
            scope: ToolScope::Business,
            readWrite: ReadWrite::Write,
            agentExecutable: false,
            scopeFrom: 'business:args.business_slug',
            idempotent: true,
            idempotencyKeyFrom: ['brief_id', 'text', 'platform', 'variant', 'product_facts', 'claim_refs', 'assets'],
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
                'brief_id' => ['type' => 'integer'],
                'text' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 5000],
                'text_provenance' => ['type' => 'string', 'enum' => ['generated', 'business']],
                'platform' => ['type' => 'string', 'enum' => ['facebook_page', 'instagram']],
                'variant' => ['type' => 'string', 'maxLength' => 32],
                'product_lines' => ['type' => 'array', 'maxItems' => 10],
                'product_facts' => ['type' => 'array', 'maxItems' => 40],
                'research_artifact_uuid' => ['type' => 'string', 'maxLength' => 64],
                'claim_refs' => ['type' => 'array', 'maxItems' => 40],
                'assets' => ['type' => 'array', 'maxItems' => 10],
            ],
            'required' => ['business_slug', 'brief_id', 'text', 'text_provenance', 'platform', 'product_lines'],
            'additionalProperties' => false,
        ];
    }

    public function outputSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'draft_uuid' => ['type' => 'string'], 'status' => ['type' => 'string'], 'checks' => ['type' => 'object'], 'content_digest' => ['type' => 'string'],
        ]];
    }

    public function execute(array $payload, ToolContext $context): ToolResult
    {
        $brief = ContentBrief::query()->find((int) ($payload['brief_id'] ?? 0));
        $brand = $brief !== null ? Brand::query()->find($brief->brand_id) : null;
        if ($brand === null || ($context->businessId !== null && $brand->business_id !== $context->businessId)
            || ($context->permittedBusinessIds !== null && ! in_array($brand->business_id, $context->permittedBusinessIds, true))) {
            return ToolResult::failed('HQ holds no such brief in this business.');
        }

        $artifactId = null;
        if (isset($payload['research_artifact_uuid'])) {
            $artifactId = ResearchArtifact::query()->where('uuid', (string) $payload['research_artifact_uuid'])->value('id');
            if ($artifactId === null) {
                return ToolResult::failed('HQ holds no such research artifact.');
            }
        }

        try {
            $draft = $this->drafts->create([
                'brief_id' => $brief->getKey(),
                'text' => (string) ($payload['text'] ?? ''),
                'text_provenance' => (string) ($payload['text_provenance'] ?? ''),
                'platform' => (string) ($payload['platform'] ?? ''),
                'variant' => (string) ($payload['variant'] ?? 'default'),
                'product_lines' => (array) ($payload['product_lines'] ?? []),
                'product_facts' => (array) ($payload['product_facts'] ?? []),
                'research_artifact_id' => $artifactId,
                'claim_refs' => (array) ($payload['claim_refs'] ?? []),
                'assets' => (array) ($payload['assets'] ?? []),
            ], $context->actorUserId !== null ? User::query()->find($context->actorUserId) : null);
        } catch (ResearchRefused $e) {
            return ToolResult::failed($e->getMessage());
        }

        return ToolResult::succeeded([
            'draft_uuid' => $draft->uuid, 'status' => $draft->status, 'checks' => $draft->checks, 'content_digest' => $draft->content_digest,
        ]);
    }

    public function reconcile(ToolContext $context): ?ToolResult
    {
        return null;
    }
}
