<?php

namespace App\Tools\Avatar;

use App\Avatar\AvatarGenerations;
use App\Gateway\Descriptor\DataClass;
use App\Gateway\Descriptor\MonetaryImpact;
use App\Gateway\Descriptor\ReadWrite;
use App\Gateway\Descriptor\Reversibility;
use App\Gateway\Descriptor\RiskTier;
use App\Gateway\Descriptor\ToolCardinality;
use App\Gateway\Descriptor\ToolDescriptor;
use App\Gateway\Descriptor\ToolScope;
use App\Models\AvatarConsent;
use App\Models\User;
use App\Research\ResearchRefused;
use App\Tools\Tool;
use App\Tools\ToolContext;
use App\Tools\ToolResult;

/**
 * `avatar.generate` (docs/20, docs/145-b): one avatar video or voice clip from a script, on a recorded
 * consent. R4 (an outside provider does the work), so every call waits for a person's approval; permanently
 * human-only, because generating a real person's likeness is never an agent's decision.
 *
 * ⛔ DECLARED, NOT REGISTERED (`PhaseEightTools`), held by no agent, and refused four times over today:
 * not in `config('hq.tools')`, the flag `avatar.generation` is off, no `AvatarProvider` is bound, and no
 * consent is recorded. The consent itself is checked by `AvatarGenerations`, inside a lock, before and after
 * the provider call, so an approval given days ago never outlives a revocation.
 */
final class GenerateAvatarTool implements Tool
{
    public const KEY = 'avatar.generate';

    public const PERMISSION = 'hq.avatar.generate';

    public function __construct(private readonly AvatarGenerations $generations) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'business_slug' => ['type' => 'string', 'maxLength' => 64],
                'consent_uuid' => ['type' => 'string', 'maxLength' => 64],
                'brand_id' => ['type' => 'integer'],
                'use' => ['type' => 'string', 'enum' => ['product_promo', 'brand_presenter', 'internal']],
                'modality' => ['type' => 'string', 'enum' => ['face', 'voice']],
                'script' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 2000],
            ],
            'required' => ['business_slug', 'consent_uuid', 'brand_id', 'use', 'modality', 'script'],
            'additionalProperties' => false,
        ];
    }

    public function outputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'generation_uuid' => ['type' => 'string'],
                'status' => ['type' => 'string'],
                'provider_job_ref' => ['type' => ['string', 'null']],
            ],
        ];
    }

    public function descriptor(): ToolDescriptor
    {
        return new ToolDescriptor(
            key: self::KEY,
            version: '1.0.0',
            title: 'Generate an avatar clip (on recorded consent)',
            purpose: 'Asks the avatar provider for one video or voice clip of a consented person or persona, from a script. Refused without a '
                .'valid, unrevoked, unexpired consent that covers this business, brand, use and modality; checked again after the provider '
                .'answers. Publishes nothing: a clip is used only through a content draft and its own publication approval.',
            riskTier: RiskTier::R4,
            permission: self::PERMISSION,
            auditAction: 'tool.avatar_generate',
            scope: ToolScope::Business,
            readWrite: ReadWrite::Write,
            agentExecutable: false,
            permanentlyHumanOnly: true,
            scopeFrom: 'business:args.business_slug',
            idempotent: true,
            idempotencyKeyFrom: ['consent_uuid', 'brand_id', 'use', 'modality', 'script'],
            timeoutSeconds: 60,
            outputSchema: $this->outputSchema(),
            monetaryImpact: MonetaryImpact::Low,
            customerFacing: false,
            reversibility: Reversibility::Reversible,
            dataClass: DataClass::Sensitive,
            cardinality: ToolCardinality::OnePerBusiness,
            requesterMayDecide: false,
        );
    }

    public function execute(array $payload, ToolContext $context): ToolResult
    {
        $consent = AvatarConsent::query()->where('uuid', (string) ($payload['consent_uuid'] ?? ''))->first();
        if ($consent === null || ($context->businessId !== null && (int) $consent->business_id !== $context->businessId)
            || ($context->permittedBusinessIds !== null && ! in_array((int) $consent->business_id, $context->permittedBusinessIds, true))) {
            return ToolResult::failed('HQ holds no such consent in this business. No consent, no generation.');
        }

        try {
            $row = $this->generations->generate(
                $context->actorUserId !== null ? User::query()->find($context->actorUserId) : null,
                (int) $consent->getKey(),
                (int) ($payload['brand_id'] ?? 0),
                (string) ($payload['use'] ?? ''),
                (string) ($payload['modality'] ?? ''),
                (string) ($payload['script'] ?? ''),
            );
        } catch (ResearchRefused $e) {
            return ToolResult::failed($e->getMessage());
        }

        if ($row->status !== 'generated') {
            return ToolResult::failed((string) ($row->refusal_reason ?? "The generation ended {$row->status}."), ['generation_uuid' => $row->uuid, 'status' => $row->status]);
        }

        return ToolResult::succeeded(['generation_uuid' => $row->uuid, 'status' => $row->status, 'provider_job_ref' => $row->provider_job_ref], $row->provider_job_ref);
    }

    public function reconcile(ToolContext $context): ?ToolResult
    {
        return null;
    }
}
