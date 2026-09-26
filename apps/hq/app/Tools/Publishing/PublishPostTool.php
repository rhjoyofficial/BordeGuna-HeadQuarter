<?php

namespace App\Tools\Publishing;

use App\Content\ContentDigest;
use App\Content\LiveProductFacts;
use App\Content\Publishing\FacebookPagePublisher;
use App\Content\Publishing\InstagramPublisher;
use App\Content\Publishing\PublicationService;
use App\Gateway\Descriptor\Channel;
use App\Gateway\Descriptor\CredentialRequirement;
use App\Gateway\Descriptor\DataClass;
use App\Gateway\Descriptor\MonetaryImpact;
use App\Gateway\Descriptor\ReadWrite;
use App\Gateway\Descriptor\Reversibility;
use App\Gateway\Descriptor\RiskTier;
use App\Gateway\Descriptor\ToolCardinality;
use App\Gateway\Descriptor\ToolDescriptor;
use App\Gateway\Descriptor\ToolScope;
use App\Models\ContentDraft;
use App\Models\PlatformAccount;
use App\Research\ResearchRefused;
use App\Tools\PreviewableTool;
use App\Tools\Tool;
use App\Tools\ToolContext;
use App\Tools\ToolResult;

/**
 * `publishing.publish_post` (docs/85 D; ADR-051 accepted: publication R5, approval of the exact final
 * artifact, read back and reconciled).
 *
 * The payload carries `content_digest`, the fingerprint of the exact words, assets and account. The
 * gateway binds a person's approval to the payload hash (ADR-018), so the approval is bound to the
 * digest; `PublicationService` then recomputes the digest from the stored draft and refuses any
 * difference. An approval can never be reused for changed content.
 *
 * Customer-facing (people see it) and irreversible (a post, once seen, cannot be unseen). It addresses
 * no one person, so no per-person consent class applies; the channel is the Page or account, which the
 * descriptor's `Channel` enum does not name, so it stays `Internal` rather than borrowing a messaging
 * channel's consent rules. `delete_post`, the compensation docs/85 names, is not built.
 *
 * ⛔ DECLARED, NOT REGISTERED, held by no agent (never the Executive), and refused four times over
 * today: the flag `publishing.write` is off, no publisher is in `PlatformRegistry`, no Page token is
 * stored, and the live re-read of product facts is not wired (`LiveProductFacts`).
 */
final class PublishPostTool implements PreviewableTool, Tool
{
    public const KEY = 'publishing.publish_post';

    public function __construct(
        private readonly PublicationService $publications,
        private readonly LiveProductFacts $liveFacts,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function descriptor(): ToolDescriptor
    {
        return new ToolDescriptor(
            key: self::KEY,
            version: '1.0.0',
            title: 'Publish an approved post',
            purpose: 'Publishes one approved draft, exactly as approved, to one Facebook Page or Instagram professional account of its brand, '
                .'then reads it back. Every publication is approved individually by a person.',
            riskTier: RiskTier::R5,
            permission: 'hq.tools.publishing.write',
            auditAction: 'tool.publishing_publish_post',
            scope: ToolScope::Business,
            readWrite: ReadWrite::Write,
            agentExecutable: false,
            scopeFrom: 'business:args.business_slug',
            credentials: [
                new CredentialRequirement(FacebookPagePublisher::CREDENTIAL, 'args.account_ref', optional: true),
                new CredentialRequirement(InstagramPublisher::CREDENTIAL, 'args.account_ref', optional: true),
            ],
            idempotent: true,
            idempotencyKeyFrom: ['draft_uuid', 'platform_account_id', 'content_digest'],
            timeoutSeconds: 60,
            outputSchema: $this->outputSchema(),
            channel: Channel::Internal,
            monetaryImpact: MonetaryImpact::None,
            consentRequired: null,
            customerFacing: true,
            reversibility: Reversibility::Irreversible,
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
                'draft_uuid' => ['type' => 'string', 'maxLength' => 64],
                'platform_account_id' => ['type' => 'integer'],
                'account_ref' => ['type' => 'string', 'maxLength' => 128],
                'content_digest' => ['type' => 'string', 'minLength' => 64, 'maxLength' => 64],
                'image_url' => ['type' => 'string', 'maxLength' => 2000],
            ],
            'required' => ['business_slug', 'draft_uuid', 'platform_account_id', 'account_ref', 'content_digest'],
            'additionalProperties' => false,
        ];
    }

    public function outputSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'publication_uuid' => ['type' => 'string'], 'status' => ['type' => 'string'], 'platform_post_id' => ['type' => 'string'],
        ]];
    }

    /** What the approval card shows: the exact words, the account and the digest being approved. */
    public function preview(array $payload, ToolContext $context): array
    {
        $draft = ContentDraft::query()->where('uuid', (string) ($payload['draft_uuid'] ?? ''))->first();
        $account = PlatformAccount::query()->find((int) ($payload['platform_account_id'] ?? 0));

        return [
            'text' => $draft?->text,
            'assets' => $draft?->assets,
            'checks' => $draft?->checks,
            'platform' => $account?->platform,
            'account' => $account?->name ?? $account?->account_ref,
            'content_digest' => $payload['content_digest'] ?? null,
            'digest_matches_draft' => $draft !== null && $account !== null
                && hash_equals(ContentDigest::forPublication($draft, (int) $account->getKey()), (string) ($payload['content_digest'] ?? '')),
        ];
    }

    public function execute(array $payload, ToolContext $context): ToolResult
    {
        $draft = ContentDraft::query()->where('uuid', (string) ($payload['draft_uuid'] ?? ''))->first();
        if ($draft === null || ($context->businessId !== null && $draft->business_id !== $context->businessId)
            || ($context->permittedBusinessIds !== null && ! in_array($draft->business_id, $context->permittedBusinessIds, true))) {
            return ToolResult::failed('HQ holds no such draft in this business.');
        }

        $account = PlatformAccount::query()->find((int) ($payload['platform_account_id'] ?? 0));
        if ($account === null || (string) $account->account_ref !== (string) ($payload['account_ref'] ?? '')) {
            return ToolResult::failed('The account named is not the account the approval names.');
        }

        try {
            $facts = $this->liveFacts->reread($draft);
            $publication = $this->publications->publish($draft, $account, (string) ($payload['content_digest'] ?? ''), null, $facts,
                isset($payload['image_url']) ? (string) $payload['image_url'] : null);
        } catch (ResearchRefused $e) {
            return ToolResult::failed($e->getMessage());
        }

        $output = ['publication_uuid' => $publication->uuid, 'status' => $publication->status, 'platform_post_id' => $publication->platform_post_id];

        return match ($publication->status) {
            'published' => ToolResult::succeeded($output, $publication->platform_post_id),
            'uncertain' => ToolResult::uncertain((string) $publication->last_error, $publication->platform_post_id),
            default => ToolResult::failed((string) ($publication->last_error ?? "The publication is {$publication->status}."), $output),
        };
    }

    /** The service reconciles by the publication's logical key before any retry (docs/85 C.20). */
    public function reconcile(ToolContext $context): ?ToolResult
    {
        return null;
    }
}
