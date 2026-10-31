<?php

namespace App\Tools\Connectors;

use App\Ai\Exceptions\AiPaused;
use App\Ai\Pause\AiPause;
use App\Connectors\Capabilities;
use App\Connectors\ConnectorRegistry;
use App\Connectors\ConnectorRequest;
use App\Gateway\Descriptor\RiskTier;
use App\Gateway\OutcomeClass;
use App\Media\Pipeline\Exceptions\MediaPipelineRefused;
use App\Media\Pipeline\Exceptions\MediaPipelineUnavailable;
use App\Media\Pipeline\MediaJobSpec;
use App\Media\Pipeline\MediaPipeline;
use App\Media\Pipeline\VeoMediaPipeline;
use App\Models\Brand;
use App\Models\Business;
use App\Models\ChatAttachment;
use App\Models\MediaDraft;
use App\Support\Audit\Auditor;
use App\Tools\PreviewableTool;
use App\Tools\ToolContext;
use App\Tools\ToolResult;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * `media.draft_reel`: ask the media pipeline for a short video DRAFT from a brief (docs/144-media-pipeline).
 * R4 (it spends provider credit), so it waits for approval. Draft only: the result is a draft asset marked as
 * generated; nothing is published, and publishing stays the content gate's own approval. Refused while Pause All AI is on.
 */
final class DraftReelTool extends ConnectorTool implements PreviewableTool
{
    public function key(): string
    {
        return 'media.draft_reel';
    }

    protected function title(): string
    {
        return 'Ask for a reel draft';
    }

    protected function purpose(): string
    {
        return 'Asks the media pipeline provider for a short video draft from a brief. The result is a DRAFT a person approves or rejects; '
            .'nothing is posted or published. Needs the provider key and approval, and is refused while all AI is paused.';
    }

    protected function flag(): string
    {
        return 'connectors.media_pipeline';
    }

    protected function permission(): string
    {
        return 'hq.tools.media.draft';
    }

    protected function credentialSources(): array
    {
        return [VeoMediaPipeline::CREDENTIAL => '=hq'];
    }

    protected function arguments(): array
    {
        return [
            'brief' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 2000],
            'seconds' => ['type' => 'integer', 'minimum' => 1, 'maximum' => MediaJobSpec::MAX_SECONDS],
            'aspect' => ['type' => 'string', 'enum' => MediaJobSpec::ASPECTS],
            'brand_slug' => ['type' => 'string', 'maxLength' => 64],
            'source_attachment_uuids' => ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 36], 'maxItems' => 6],
        ];
    }

    protected function required(): array
    {
        return ['brief'];
    }

    protected function riskTier(): RiskTier
    {
        return RiskTier::R4;
    }

    public function preview(array $payload, ToolContext $context): array
    {
        return ['would_ask' => 'the media provider for a '.($payload['seconds'] ?? 8).' second '.($payload['aspect'] ?? '9:16').' draft video', 'brief' => $payload['brief'] ?? null,
            'external_effect' => 'one provider job that spends credit; the result is a draft for a person to review, never published'];
    }

    protected function run(?Business $business, array $payload, ToolContext $context): ToolResult
    {
        try {
            app(AiPause::class)->assertRunning('media draft');
        } catch (AiPaused $e) {
            return ToolResult::failed($e->getMessage(), [], OutcomeClass::PolicyDenied);
        }
        try {
            $spec = new MediaJobSpec((string) $payload['brief'], (int) ($payload['seconds'] ?? 8), (string) ($payload['aspect'] ?? '9:16'));
        } catch (InvalidArgumentException $e) {
            return ToolResult::failed($e->getMessage(), [], OutcomeClass::InvalidArguments);
        }

        $brandId = null;
        if (isset($payload['brand_slug'])) {
            $brand = Brand::query()->where('slug', $payload['brand_slug'])->where('business_id', $business->id)->first();
            if ($brand === null) {
                return ToolResult::failed('That brand is not one of this business\'s.', [], OutcomeClass::ScopeDenied);
            }
            $brandId = $brand->id;
        }
        // Source photos must be this business's own attachments.
        $assets = [];
        foreach ((array) ($payload['source_attachment_uuids'] ?? []) as $uuid) {
            $attachment = ChatAttachment::query()->where('uuid', $uuid)->where('business_id', $business->id)->where('status', '!=', 'purged')->first();
            if ($attachment === null) {
                return ToolResult::failed('A source file is not one of this business\'s attachments.', [], OutcomeClass::ScopeDenied);
            }
            $assets[] = $attachment->media_asset_id;
        }

        $registry = app(ConnectorRegistry::class);
        $resolution = $registry->resolve(new ConnectorRequest(Capabilities::MEDIA_VIDEO_GENERATE, $business->id));
        if (! $resolution->resolvedOk()) {
            return ToolResult::failed('Not connected yet: '.($resolution->reason ?? $resolution->outcome->value), ['connector_state' => $resolution->outcome->value], OutcomeClass::CapabilityUnavailable);
        }
        $pipeline = $registry->adapter($resolution);
        if (! $pipeline instanceof MediaPipeline) {
            return ToolResult::failed('The connector is not a media pipeline.', [], OutcomeClass::InternalError);
        }

        try {
            $handle = $pipeline->start($spec);
        } catch (MediaPipelineRefused $e) {
            return ToolResult::failed($e->getMessage(), [], OutcomeClass::ConnectorError);
        } catch (MediaPipelineUnavailable $e) {
            return ToolResult::failed($e->getMessage(), [], OutcomeClass::ProviderUnavailable);
        } catch (Throwable $e) {
            return ToolResult::uncertain($e::class);
        }

        $draft = MediaDraft::create([
            'uuid' => (string) Str::uuid(), 'business_id' => $business->id, 'brand_id' => $brandId, 'kind' => 'reel', 'brief' => $spec->brief,
            'source_asset_ids' => $assets, 'provider' => $pipeline->key(), 'provider_job_ref' => $handle, 'status' => 'generating', 'requested_by' => $context->actorUserId,
        ]);
        app(Auditor::class)->recordSystem('media.draft_requested', $draft, null, ['business_id' => $business->id, 'provider' => $pipeline->key(), 'seconds' => $spec->seconds]);

        return ToolResult::succeeded(['draft_uuid' => $draft->uuid, 'status' => $draft->status], $handle);
    }
}
