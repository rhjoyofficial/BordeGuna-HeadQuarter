<?php

namespace App\Tools\Connectors;

use App\Ads\Draft\AdsDraftGuard;
use App\Ads\Draft\AdsDraftPlatform;
use App\Ads\Draft\AdsDraftSpec;
use App\Ads\Draft\Exceptions\AdsDraftRefused;
use App\Ads\Draft\Exceptions\AdsDraftUnavailable;
use App\Ads\Draft\MetaAdsDraftPlatform;
use App\Connectors\Capabilities;
use App\Connectors\ConnectorRegistry;
use App\Connectors\ConnectorRequest;
use App\Gateway\Descriptor\RiskTier;
use App\Gateway\OutcomeClass;
use App\Models\AdsDraft;
use App\Models\Business;
use App\Models\PlatformAccount;
use App\Support\Audit\Auditor;
use App\Tools\PreviewableTool;
use App\Tools\ToolContext;
use App\Tools\ToolResult;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * `ads.create_draft`: prepare ONE paused campaign draft (one ad set, one ad) at the platform (docs/144-meta-ads-drafts).
 * R4, so it waits for approval; the approval card shows the guard's verdict in its preview.
 *
 * ⛔ Paused only, and checked twice: the guard (USD 5 a day cap, Bangla-only copy, health claims) runs in the
 * preview and again here before the platform is called, and after creation every object's status is read back; a
 * draft the platform did not leave paused is reported as a failure with the object ids, never as success.
 * ⛔ The ad account must be one of the business's own (`platform_accounts`), never a number from the request alone.
 * No tool exists to switch a draft on, change its budget or delete it (`AdsHumanOnly`).
 */
final class CreateAdsDraftTool extends ConnectorTool implements PreviewableTool
{
    public function key(): string
    {
        return 'ads.create_draft';
    }

    protected function title(): string
    {
        return 'Prepare a paused ad draft';
    }

    protected function purpose(): string
    {
        return 'Creates one paused ad campaign draft for the business at the ad platform: Bangla-only copy, no unproven health claims, '
            .'a daily budget within the per-campaign cap. It never switches anything on; a person does that in Ads Manager.';
    }

    protected function flag(): string
    {
        return 'connectors.ads_draft';
    }

    protected function permission(): string
    {
        return 'hq.tools.ads.draft';
    }

    protected function credentialSources(): array
    {
        return [MetaAdsDraftPlatform::CREDENTIAL => 'args.business_slug|business.slug'];
    }

    protected function arguments(): array
    {
        return [
            'ad_account_ref' => ['type' => 'string', 'maxLength' => 32],
            'name' => ['type' => 'string', 'maxLength' => 120],
            'objective' => ['type' => 'string', 'enum' => AdsDraftSpec::OBJECTIVES],
            'daily_budget' => ['type' => 'string', 'maxLength' => 12],
            'currency' => ['type' => 'string', 'enum' => ['USD', 'BDT']],
            'page_id' => ['type' => 'string', 'maxLength' => 20],
            'link_url' => ['type' => 'string', 'maxLength' => 500],
            'primary_text' => ['type' => 'string', 'maxLength' => 1000],
            'headline' => ['type' => 'string', 'maxLength' => 120],
            'description' => ['type' => 'string', 'maxLength' => 200],
            'image_hash' => ['type' => 'string', 'maxLength' => 64],
            'claim_proofs' => ['type' => 'object'],
        ];
    }

    protected function required(): array
    {
        return ['ad_account_ref', 'name', 'objective', 'daily_budget', 'currency', 'page_id', 'link_url', 'primary_text', 'headline'];
    }

    protected function riskTier(): RiskTier
    {
        return RiskTier::R4;
    }

    protected function idempotent(): bool
    {
        return true;
    }

    protected function idempotencyKeyFrom(): array
    {
        return ['business_slug', 'ad_account_ref', 'name', 'daily_budget', 'primary_text', 'headline'];
    }

    public function preview(array $payload, ToolContext $context): array
    {
        try {
            $spec = self::spec($payload);
        } catch (InvalidArgumentException $e) {
            return ['would_create' => 'nothing: '.$e->getMessage(), 'external_effect' => 'none'];
        }

        return [
            'would_create' => 'one PAUSED campaign, one PAUSED ad set at '.$spec->dailyBudget.' '.$spec->currency.' a day, one ad',
            'checks' => app(AdsDraftGuard::class)->check($spec),
            'external_effect' => 'paused objects at the ad platform; nothing is switched on and nothing is spent',
        ];
    }

    protected function run(?Business $business, array $payload, ToolContext $context): ToolResult
    {
        try {
            $spec = self::spec($payload);
        } catch (InvalidArgumentException $e) {
            return ToolResult::failed($e->getMessage(), [], OutcomeClass::InvalidArguments);
        }

        $checks = app(AdsDraftGuard::class)->check($spec);
        if (! $checks['ok']) {
            return ToolResult::failed('The draft did not pass the checks, so nothing was created.', ['checks' => $checks], OutcomeClass::ContentPolicyRejected);
        }

        $account = PlatformAccount::query()->where('platform', 'meta_ad_account')->where('account_ref', $payload['ad_account_ref'])
            ->whereHas('brand', fn ($q) => $q->where('business_id', $business->id))->first();
        if ($account === null) {
            return ToolResult::failed('That ad account is not one of this business\'s own.', [], OutcomeClass::ScopeDenied);
        }

        $resolution = app(ConnectorRegistry::class)->resolve(new ConnectorRequest(Capabilities::ADS_DRAFT_CREATE, $business->id));
        if (! $resolution->resolvedOk()) {
            return ToolResult::failed('Not connected yet: '.($resolution->reason ?? $resolution->outcome->value), ['connector_state' => $resolution->outcome->value], OutcomeClass::CapabilityUnavailable);
        }
        $platform = app(ConnectorRegistry::class)->adapter($resolution);
        if (! $platform instanceof AdsDraftPlatform) {
            return ToolResult::failed('The connector is not an ads draft platform.', [], OutcomeClass::InternalError);
        }

        try {
            $result = $platform->createPausedDraft($business->slug, (string) $account->account_ref, $spec);
        } catch (AdsDraftRefused $e) {
            $this->record($business, $account, $spec, $checks, 'failed', $e->created, $e->getMessage(), $context);

            return ToolResult::failed($e->getMessage(), ['created_paused' => $e->created], OutcomeClass::ConnectorError);
        } catch (AdsDraftUnavailable $e) {
            return ToolResult::uncertain($e->getMessage());
        } catch (Throwable $e) {
            return ToolResult::uncertain($e::class);
        }

        if (! $result->allPaused()) {
            $this->record($business, $account, $spec, $checks, 'failed', $result->refs, 'The platform did not leave every object paused.', $context);

            return ToolResult::failed('The platform did not leave every object paused. Check these objects in Ads Manager now.', ['objects' => $result->refs, 'statuses' => $result->statuses], OutcomeClass::ConnectorError);
        }

        $draft = $this->record($business, $account, $spec, $checks, 'created_paused', $result->refs, null, $context);

        return ToolResult::succeeded(['draft_uuid' => $draft->uuid, 'objects' => $result->refs, 'statuses' => $result->statuses], $result->refs['campaign']);
    }

    /** @param  array<string, mixed>  $payload */
    private static function spec(array $payload): AdsDraftSpec
    {
        return new AdsDraftSpec(
            name: (string) $payload['name'], objective: (string) $payload['objective'], dailyBudget: (string) $payload['daily_budget'],
            currency: (string) $payload['currency'], pageId: (string) $payload['page_id'], linkUrl: (string) $payload['link_url'],
            primaryText: (string) $payload['primary_text'], headline: (string) $payload['headline'],
            description: isset($payload['description']) ? (string) $payload['description'] : null,
            imageHash: isset($payload['image_hash']) ? (string) $payload['image_hash'] : null,
            claimProofs: array_map('strval', (array) ($payload['claim_proofs'] ?? [])),
        );
    }

    /** @param  array<string, mixed>  $checks  @param  array<string, string>  $refs */
    private function record(Business $business, PlatformAccount $account, AdsDraftSpec $spec, array $checks, string $status, array $refs, ?string $error, ToolContext $context): AdsDraft
    {
        $draft = AdsDraft::create([
            'uuid' => (string) Str::uuid(), 'business_id' => $business->id, 'brand_id' => $account->brand_id, 'ad_account_ref' => (string) $account->account_ref,
            'name' => mb_substr($spec->name, 0, 191), 'spec' => ['objective' => $spec->objective, 'daily_budget' => $spec->dailyBudget, 'currency' => $spec->currency, 'link_url' => $spec->linkUrl,
                'primary_text' => $spec->primaryText, 'headline' => $spec->headline, 'description' => $spec->description],
            'checks' => $checks, 'status' => $status, 'platform_refs' => $refs, 'error' => $error, 'created_by' => $context->actorUserId,
        ]);
        app(Auditor::class)->recordSystem('ads.draft_'.$status, $draft, null, ['business_id' => $business->id, 'refs' => $refs]);

        return $draft;
    }
}
