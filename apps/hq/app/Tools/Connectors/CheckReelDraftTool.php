<?php

namespace App\Tools\Connectors;

use App\Connectors\Capabilities;
use App\Connectors\ConnectorRegistry;
use App\Connectors\ConnectorRequest;
use App\Context\Provenance;
use App\Gateway\OutcomeClass;
use App\Media\MediaAssetService;
use App\Media\Pipeline\Exceptions\MediaPipelineRefused;
use App\Media\Pipeline\Exceptions\MediaPipelineUnavailable;
use App\Media\Pipeline\MediaJobStatus;
use App\Media\Pipeline\MediaPipeline;
use App\Media\Pipeline\VeoMediaPipeline;
use App\Media\Upload\AttachmentGuard;
use App\Models\Business;
use App\Models\MediaDraft;
use App\Support\Audit\Auditor;
use App\Tools\ToolContext;
use App\Tools\ToolResult;

/**
 * `media.check_draft`: ask the pipeline how a reel draft is doing; when it is ready, keep the video as a draft asset
 * (provenance generated, not trusted, rights unrecorded so it cannot be used for publication until a person records them).
 * Reads at the provider and writes only HQ's rows.
 */
final class CheckReelDraftTool extends ConnectorTool
{
    public function key(): string
    {
        return 'media.check_draft';
    }

    protected function title(): string
    {
        return 'Check a reel draft';
    }

    protected function purpose(): string
    {
        return 'Asks the media provider whether a requested reel draft is ready and, when it is, stores the video as a draft for review. Publishes nothing.';
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
        return ['draft_uuid' => ['type' => 'string', 'minLength' => 36, 'maxLength' => 36]];
    }

    protected function required(): array
    {
        return ['draft_uuid'];
    }

    protected function run(?Business $business, array $payload, ToolContext $context): ToolResult
    {
        $draft = MediaDraft::query()->where('business_id', $business->id)->where('uuid', $payload['draft_uuid'])->first();
        if ($draft === null) {
            return ToolResult::failed('That draft does not belong to this business.', [], OutcomeClass::ScopeDenied);
        }
        if ($draft->status !== 'generating') {
            return ToolResult::succeeded(['draft_uuid' => $draft->uuid, 'status' => $draft->status]);
        }

        $registry = app(ConnectorRegistry::class);
        $resolution = $registry->resolve(new ConnectorRequest(Capabilities::MEDIA_VIDEO_GENERATE, $business->id));
        $pipeline = $resolution->resolvedOk() ? $registry->adapter($resolution) : null;
        if (! $pipeline instanceof MediaPipeline) {
            return ToolResult::failed('Not connected yet: '.($resolution->reason ?? $resolution->outcome->value), ['connector_state' => $resolution->outcome->value], OutcomeClass::CapabilityUnavailable);
        }

        try {
            $status = $pipeline->check((string) $draft->provider_job_ref);
        } catch (MediaPipelineRefused $e) {
            $draft->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)])->save();

            return ToolResult::failed($e->getMessage(), [], OutcomeClass::ConnectorError);
        } catch (MediaPipelineUnavailable $e) {
            return ToolResult::failed($e->getMessage(), [], OutcomeClass::ProviderUnavailable);
        }

        if ($status->state === MediaJobStatus::FAILED) {
            $draft->forceFill(['status' => 'failed', 'error' => mb_substr((string) $status->error, 0, 500)])->save();
        } elseif ($status->state === MediaJobStatus::READY && is_string($status->video)) {
            // The provider's bytes get the same byte-level check as anything else HQ stores.
            $mime = app(AttachmentGuard::class)->sniff($status->video);
            if (! in_array($mime, ['video/mp4', 'video/webm', 'video/quicktime'], true)) {
                $draft->forceFill(['status' => 'failed', 'error' => 'The provider returned something that is not a video.'])->save();

                return ToolResult::failed('The provider returned something that is not a video.', [], OutcomeClass::ConnectorError);
            }
            $asset = app(MediaAssetService::class)->store($status->video, $mime, 'video', Provenance::Generated, [
                'business_id' => $business->id, 'brand_id' => $draft->brand_id, 'generator' => ['provider' => $draft->provider, 'draft_uuid' => $draft->uuid],
                'source_ref' => 'media_draft:'.$draft->uuid, 'created_by' => $context->actorUserId,
            ]);
            $draft->forceFill(['status' => 'ready', 'result_media_asset_id' => $asset->id])->save();
            app(Auditor::class)->recordSystem('media.draft_ready', $draft, null, ['business_id' => $business->id, 'asset' => $asset->uuid]);
        }

        return ToolResult::succeeded(['draft_uuid' => $draft->uuid, 'status' => $draft->status]);
    }
}
