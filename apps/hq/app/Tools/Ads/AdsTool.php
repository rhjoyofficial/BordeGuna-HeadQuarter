<?php

namespace App\Tools\Ads;

use App\Ads\AdsCredential;
use App\Ads\AdsReader;
use App\Commerce\Exceptions\Refused;
use App\Commerce\Exceptions\Unavailable;
use App\Commerce\Exceptions\Unsupported;
use App\Commerce\Exceptions\ValidationFailed;
use App\Gateway\Descriptor\CredentialRequirement;
use App\Gateway\Descriptor\DataClass;
use App\Gateway\Descriptor\ReadWrite;
use App\Gateway\Descriptor\RiskTier;
use App\Gateway\Descriptor\ToolCardinality;
use App\Gateway\Descriptor\ToolDescriptor;
use App\Gateway\Descriptor\ToolScope;
use App\Models\Brand;
use App\Models\Business;
use App\Models\PlatformAccount;
use App\Platform\CapabilityRequest;
use App\Platform\CapabilityRouter;
use App\Platform\Contracts\AdsReadPlatform;
use App\Platform\PlatformRegistry;
use App\Tools\Tool;
use App\Tools\ToolContext;
use App\Tools\ToolResult;
use Carbon\CarbonImmutable;

/**
 * What every Phase 5 ads tool shares (stream S, `docs/88` A point 4, `docs/84` C.7).
 *
 * ⛔ **Defined, not registered.** No ads tool is in `config('hq.tools')`, so the gateway refuses every
 * one of them as an unknown tool; no agent holds any (`agents.allowed_tools` is untouched); no ads
 * credential exists; and the Meta provider is not in `PlatformRegistry`, so the router refuses even a
 * registered, credentialed call. Four independent locks, each held by a test. Registering the READ
 * tools is gate P5-G1, with the owner's system-user token.
 *
 * ⛔ **Reads only at the platform.** The posture is fixed here: business-scoped, the business named
 * in a REQUIRED `business_slug`, the credential the business's `meta_ads_token`, the account chosen by
 * the router (`docs/84` C.7 rule 2: never named by the caller; two candidates are refused, never
 * guessed). A subclass says what it reads; it cannot state a write at the platform.
 *
 * ⚠ **Provenance on every answer**: the business and brand, the account, `third_party`, the source
 * and API version, and when HQ read it (ADR-024: the platform's own count, never the business's truth).
 */
abstract class AdsTool implements Tool
{
    public function __construct(
        protected readonly CapabilityRouter $router,
        protected readonly PlatformRegistry $platforms,
    ) {}

    abstract protected function title(): string;

    abstract protected function purpose(): string;

    /** The platform capability this tool needs: `AdsPlatform::READ_INSIGHTS` or `READ_CAMPAIGNS`. */
    abstract protected function capability(): string;

    /** @return array<string, array<string, mixed>> the tool's own arguments, besides business_slug and brand_slug */
    abstract protected function arguments(): array;

    /** @return array<string, mixed> output properties besides the provenance */
    abstract protected function outputProperties(): array;

    /** @return array<string, mixed>|ToolResult */
    abstract protected function read(AdsReader $reader, PlatformAccount $account, array $payload, ToolContext $context): array|ToolResult;

    protected function riskTier(): RiskTier
    {
        return RiskTier::R2;
    }

    protected function readWrite(): ReadWrite
    {
        return ReadWrite::Read;
    }

    /** Reading is not acting (ADR-027): an agent COULD be given a read. None is. */
    protected function agentExecutable(): bool
    {
        return true;
    }

    public function descriptor(): ToolDescriptor
    {
        return new ToolDescriptor(
            key: $this->key(),
            version: '1.0.0',
            title: $this->title(),
            purpose: $this->purpose(),
            riskTier: $this->riskTier(),
            permission: 'hq.tools.ads.read',
            auditAction: 'tool.'.str_replace('.', '_', $this->key()),
            scope: ToolScope::Business,
            readWrite: $this->readWrite(),
            agentExecutable: $this->agentExecutable(),
            scopeFrom: 'business:args.business_slug',
            cardinality: ToolCardinality::OnePerBusiness,
            credentials: [new CredentialRequirement(AdsCredential::PROVIDER, 'args.business_slug|business.slug')],
            idempotent: true,
            idempotencyKeyFrom: array_merge(['business_slug', 'brand_slug'], array_keys($this->arguments())),
            timeoutSeconds: 60,
            outputSchema: $this->outputSchema(),
            replayWithinSeconds: $this->readWrite() === ReadWrite::Read ? 60 : 0,
            dataClass: DataClass::Internal,
        );
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'business_slug' => ['type' => 'string', 'maxLength' => 64],
                'brand_slug' => ['type' => 'string', 'maxLength' => 64],
            ] + $this->arguments(),
            'required' => ['business_slug'],
            'additionalProperties' => false,
        ];
    }

    public function outputSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'business' => ['type' => 'string'],
            'brand' => ['type' => 'string'],
            'account_ref' => ['type' => 'string'],
            'platform' => ['type' => 'string'],
            'provenance' => ['type' => 'string'],
            'source' => ['type' => 'string'],
            'api_version' => ['type' => 'string'],
            'read_live_at' => ['type' => 'string'],
        ] + $this->outputProperties()];
    }

    public function execute(array $payload, ToolContext $context): ToolResult
    {
        $businessId = $context->businessId ?? Business::where('slug', $payload['business_slug'] ?? null)->value('id');
        $business = $businessId === null ? null : Business::find($businessId);
        if ($business === null) {
            return ToolResult::failed('Name the business whose ad account to read.');
        }

        $brandId = null;
        if (isset($payload['brand_slug'])) {
            $brand = Brand::where('slug', $payload['brand_slug'])->first();
            if ($brand === null || (int) $brand->business_id !== (int) $business->id) {
                return ToolResult::failed("Brand [{$payload['brand_slug']}] is not a brand of {$business->slug}.");
            }
            $brandId = $brand->id;
        }

        // The router picks the ONE account, or refuses and says why: no provider registered, no
        // recorded grants, two candidates. It never guesses (ADR-029).
        $routing = $this->router->route(new CapabilityRequest($this->capability(), businessId: $business->id, brandId: $brandId));
        if (! $routing->available || $routing->account === null) {
            // The per-account reasons too, so "no provider registered" or "no grants recorded" is said,
            // not hidden behind the summary.
            $why = array_map(static fn (array $r) => "{$r['platform']}/{$r['account']}: {$r['reason']}", $routing->rejected);

            return ToolResult::failed('No ad account can serve this: '.($routing->reason ?? 'unavailable').($why === [] ? '' : ' ('.implode('; ', $why).')'),
                ['routing' => $routing->toArray()]);
        }
        $account = $routing->account;

        $provider = $this->platforms->get($account->platform);
        if (! $provider instanceof AdsReadPlatform) {
            return ToolResult::failed("No ads read provider for [{$account->platform}].");
        }

        try {
            $reader = $provider->reader($business, $account);
            $out = $this->read($reader, $account, $payload, $context);
        } catch (Unsupported|Refused|ValidationFailed $e) {
            // A token without the permission, an expired token, an answer HQ does not understand:
            // an answer to report, not a fault to retry.
            return ToolResult::failedBy($e);
        } catch (Unavailable $e) {
            throw $e; // throttled or down: the caller retries later, never `failed`
        }

        if ($out instanceof ToolResult) {
            return $out;
        }

        return ToolResult::succeeded([
            'business' => $business->slug,
            // Read, not lazy-loaded: the routed account comes from a list of every account in scope, and
            // a business with a Page, a dataset and an ad account (LaLaDia since the 3 Oct seed) made the
            // lazy load an error outside production.
            'brand' => (string) $account->brand()->value('slug'),
            'account_ref' => (string) $account->account_ref,
            'platform' => (string) $account->platform,
            'provenance' => 'third_party',
            'source' => 'meta_ads',
            'api_version' => $reader->apiVersion(),
            'read_live_at' => CarbonImmutable::now((string) config('hq.timezone', 'Asia/Dhaka'))->toIso8601String(),
        ] + $out);
    }

    public function reconcile(ToolContext $context): ?ToolResult
    {
        return null;
    }

    /** A Y-m-d argument, or the default, in the account's reporting days (the caller's calendar). */
    protected static function day(?string $value, CarbonImmutable $default): CarbonImmutable
    {
        if ($value === null || $value === '') {
            return $default->startOfDay();
        }

        return CarbonImmutable::createFromFormat('!Y-m-d', $value) ?: $default->startOfDay();
    }
}
