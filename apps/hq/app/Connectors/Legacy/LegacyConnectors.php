<?php

namespace App\Connectors\Legacy;

use App\Ai\Providers\ProviderRegistry;
use App\Commerce\Adapters\LaravelShopAdapter;
use App\Commerce\Support\CommerceRegistry;
use App\Connectors\Capabilities;
use App\Connectors\Transport;
use App\Erp\ErpRegistry;
use App\Platform\Contracts\AdsReadPlatform;
use App\Platform\Contracts\CommentChannel;
use App\Platform\Contracts\MediaGenerator;
use App\Platform\Contracts\MessagingChannel;
use App\Platform\Contracts\PlatformProvider;
use App\Platform\Contracts\PublishingPlatform;
use App\Platform\Contracts\ResearchSource;
use App\Platform\Contracts\TelephonyProvider;
use App\Platform\PlatformRegistry;
use App\Voice\SpeechProviders;

/**
 * Read-only bridges from the five legacy provider shapes to the registry's vocabulary (docs/95 §10
 * item 1, docs/97 §6 D2: report now, converge over time, no blind rewrite).
 *
 * ⛔ **Reads only.** Nothing here registers, builds an adapter, opens a credential or calls a
 * provider; a bridge that could would be a sixth resolution path. Each method reads what its shape
 * already exposes (a constant, `keys()`, `platforms()`, the two speech slots) and describes it. A
 * shape that serves nothing today (the platform registry, the speech providers) reports nothing,
 * which is the truth readiness should show.
 */
final class LegacyConnectors
{
    /** `MessagingChannel` platforms to the channel-specific send capabilities. */
    private const MESSAGING = [
        'whatsapp_number' => Capabilities::MESSAGING_WHATSAPP_SEND,
        'facebook_page' => Capabilities::MESSAGING_MESSENGER_SEND,
        'instagram_account' => Capabilities::MESSAGING_INSTAGRAM_SEND,
    ];

    public function __construct(
        private readonly PlatformRegistry $platforms,
        private readonly ProviderRegistry $models,
        private readonly SpeechProviders $speech,
    ) {}

    /** @return list<LegacyConnector> */
    public function all(): array
    {
        return [
            ...$this->commerce(),
            ...$this->erp(),
            ...$this->models(),
            ...$this->speech(),
            ...$this->platforms(),
        ];
    }

    /** `CommerceRegistry`: a hardcoded adapter list, one class serving both shops. */
    public function commerce(): array
    {
        return array_map(static fn (string $adapter) => new LegacyConnector(
            capability: Capabilities::COMMERCE_SHOP,
            provider: $adapter,
            adapter: LaravelShopAdapter::class,
            transport: Transport::DirectApi,
            credentials: [CommerceRegistry::ADMIN_PROVIDER.' <- store.slug', CommerceRegistry::ASSISTANT_PROVIDER.' <- store.slug'],
            shape: CommerceRegistry::class,
            note: 'resolved per Store row by CommerceRegistry::for(); per-store readiness is in the store rows',
        ), CommerceRegistry::SUPPORTED_ADAPTERS);
    }

    /** `ErpRegistry`: one ERP, one connector class, a token per business. */
    public function erp(): array
    {
        return [new LegacyConnector(
            capability: Capabilities::ERP_READ,
            provider: 'bdg_erp',
            adapter: ErpRegistry::ADAPTER,
            transport: Transport::DirectApi,
            credentials: [ErpRegistry::PROVIDER.' <- business.slug'],
            shape: ErpRegistry::class,
            note: 'resolved per Business by ErpRegistry::for(); per-business readiness is in the business rows',
        )];
    }

    /** `ProviderRegistry`: the model drivers from `config('hq.ai.providers')`. */
    public function models(): array
    {
        $out = [];
        foreach ($this->models->keys() as $key) {
            $provider = $this->models->get($key);
            [$kind, $account] = $provider->credentialPair();
            $out[] = new LegacyConnector(
                capability: Capabilities::AI_COMPLETE,
                provider: $key,
                adapter: $provider::class,
                transport: Transport::DirectApi,
                credentials: ["{$kind} <- ={$account}"],
                shape: ProviderRegistry::class,
                note: 'selected by model_tiers.provider through ModelRouter; the serving key is in the hq model provider row',
            );
        }

        return $out;
    }

    /** `SpeechProviders`: two slots, both empty until gate P7-G2. */
    public function speech(): array
    {
        $out = [];
        foreach ([[Capabilities::VOICE_TRANSCRIBE, $this->speech->speechToText()], [Capabilities::VOICE_SYNTHESIZE, $this->speech->textToSpeech()]] as [$capability, $provider]) {
            if ($provider === null) {
                continue;
            }
            // The speech contracts carry no credential method; the shipped adapters name theirs in constants.
            $kind = $provider::class.'::CREDENTIAL_PROVIDER';
            $account = $provider::class.'::ACCOUNT_REF';
            $out[] = new LegacyConnector(
                capability: $capability,
                provider: $provider->key(),
                adapter: $provider::class,
                transport: Transport::DirectApi,
                credentials: defined($kind) && defined($account) ? [constant($kind).' <- ='.constant($account)] : [],
                shape: SpeechProviders::class,
                note: 'a registered speech slot; activation is gate P7-G2',
            );
        }

        return $out;
    }

    /** `PlatformRegistry`: one row per contract each registered platform provider implements. */
    public function platforms(): array
    {
        $out = [];
        foreach ($this->platforms->platforms() as $platform) {
            $provider = $this->platforms->get($platform);
            if (! $provider instanceof PlatformProvider) {
                continue;
            }
            foreach ($this->platformCapabilities($provider, $platform) as $capability) {
                $out[] = new LegacyConnector(
                    capability: $capability,
                    provider: $platform,
                    adapter: $provider::class,
                    transport: Transport::DirectApi,
                    credentials: [],
                    shape: PlatformRegistry::class,
                    note: 'routed per connected account by CapabilityRouter; account grants decide what it may do',
                );
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function platformCapabilities(PlatformProvider $provider, string $platform): array
    {
        $capabilities = [];
        if ($provider instanceof MessagingChannel) {
            $capabilities[] = self::MESSAGING[$platform] ?? "messaging.{$platform}.send";
        }
        if ($provider instanceof CommentChannel) {
            $capabilities[] = Capabilities::COMMENTS_MANAGE;
        }
        if ($provider instanceof PublishingPlatform) {
            $capabilities[] = Capabilities::CONTENT_PUBLISH;
        }
        if ($provider instanceof AdsReadPlatform) {
            $capabilities[] = Capabilities::ADS_READ;
        }
        if ($provider instanceof ResearchSource) {
            $capabilities[] = Capabilities::RESEARCH_SEARCH;
        }
        if ($provider instanceof MediaGenerator) {
            $capabilities[] = Capabilities::MEDIA_IMAGE_GENERATE;
        }
        if ($provider instanceof TelephonyProvider) {
            $capabilities[] = Capabilities::VOICE_CALL;
        }

        return $capabilities === [] ? ["platform.{$platform}"] : $capabilities;
    }
}
