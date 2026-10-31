<?php

namespace App\Connectors;

use App\Ads\AdsCredential;
use App\Ads\Draft\MetaAdsDraftPlatform;
use App\Content\Publishing\FacebookPagePublisher;
use App\Content\Publishing\InstagramPublisher;
use App\Http\Controllers\Webhooks\MetaWebhookController;
use App\Models\Business;
use App\Models\IntegrationCredential;
use App\Models\User;
use App\Support\Authorization\BusinessScope;
use App\Support\Features\Features;

/**
 * What the Connectors screen says (docs/144-*): for each external capability HQ can use, whether its switch is on,
 * which provider could serve it, and in plain words what is still needed before it works. Metadata only: no
 * credential value is read, and nothing here calls a provider.
 *
 * Business-scoped: a person limited to some businesses sees credentials of those businesses only, and a credential
 * with no business (an HQ-wide key) only by its kind, never its account reference.
 */
final class ConnectorHub
{
    /** family => [title, flag, capabilities, extra things only a person can supply] */
    private const FAMILIES = [
        'courier' => ['Courier tracking', 'connectors.courier', [Capabilities::COURIER_PATHAO_TRACK, Capabilities::COURIER_CARRYBEE_TRACK, Capabilities::COURIER_STEADFAST_TRACK, Capabilities::COURIER_REDX_TRACK],
            ['A courier webhook secret per business and courier (kind courier_webhook_secret), and the webhook URL given to the courier.']],
        'voice' => ['Voice', 'connectors.voice', [Capabilities::VOICE_TRANSCRIBE, Capabilities::VOICE_SYNTHESIZE], ['A browser that allows the microphone, when a person clicks to talk.']],
        'media_upload' => ['Photos, videos and files in chat', 'connectors.media_upload', [], ['Optional: a ClamAV scanner (hq.media.scanner = clamd).']],
        'media_pipeline' => ['Reel and video drafts', 'connectors.media_pipeline', [Capabilities::MEDIA_VIDEO_GENERATE], ['A person approves each draft before anything is published.']],
        'mail_ingestion' => ['Supplier mailbox and bill drafts', 'connectors.mail_ingestion', [Capabilities::MAIL_INBOX_READ], ['A read-only mailbox token per business.']],
        'erp_draft_bill' => ['ERP draft supplier bill', 'connectors.erp_draft_bill', [Capabilities::ERP_DRAFT_BILL_CREATE], ['The ERP must offer the draft bill endpoint (docs/144-erp-write-contract.md), and a second person approves each request.']],
        'ads_draft' => ['Paused ad drafts', 'connectors.ads_draft', [Capabilities::ADS_DRAFT_CREATE], ['An ad account linked to the business, and a person switches each draft on in Ads Manager.']],
    ];

    public function __construct(private readonly ConnectorRegistry $registry, private readonly Features $features, private readonly BusinessScope $scope) {}

    /** @return list<array<string, mixed>> */
    public function families(User $user): array
    {
        $businesses = Business::query()->when($this->scope->all($user) !== null, fn ($q) => $q->whereIn('id', (array) $this->scope->all($user)))->orderBy('name')->get(['id', 'slug', 'name']);

        $out = [];
        foreach (self::FAMILIES as $key => [$title, $flag, $capabilities, $extra]) {
            $providers = [];
            foreach ($capabilities as $capability) {
                foreach ($this->registry->for($capability) as $connector) {
                    $providers[] = $this->provider($connector, $businesses->all());
                }
            }
            $enabled = $this->features->enabled($flag);
            $out[] = [
                'key' => $key, 'title' => $title, 'flag' => $flag, 'enabled' => $enabled,
                'state' => $this->state($enabled, $providers, $capabilities === []),
                'providers' => $providers, 'also_needs' => $extra,
            ];
        }

        return $out;
    }

    /** @return array<string, mixed> the Meta readiness block: webhook secrets, app id and the token health rows */
    public function meta(User $user): array
    {
        $kinds = [FacebookPagePublisher::CREDENTIAL, InstagramPublisher::CREDENTIAL, 'whatsapp_access_token', AdsCredential::PROVIDER, MetaAdsDraftPlatform::CREDENTIAL];
        $has = static fn (string $kind) => IntegrationCredential::query()->where('provider', $kind)->where('status', 'active')->exists();

        $soon = now()->addDays((int) config('hq.meta_health.expiring_within_days', 14));
        $tokens = IntegrationCredential::query()->visibleTo($user)->whereIn('provider', $kinds)->orderBy('provider')->orderBy('account_ref')->get()
            ->map(fn (IntegrationCredential $c) => [
                'id' => $c->id, 'kind' => $c->provider, 'account_ref' => $c->account_ref, 'status' => $c->status,
                'expires_at' => $c->expires_at?->toIso8601String(), 'last_verified_at' => $c->last_verified_at?->toIso8601String(),
                'scopes' => (array) $c->scopes, 'expiring_soon' => $c->expires_at !== null && $c->expires_at->lt($soon),
                'valid' => data_get($c->meta, 'meta_health.valid'),
            ])->all();

        return [
            'app_id_configured' => (string) config('hq.meta_health.app_id', '') !== '',
            'app_secret_stored' => $has(MetaWebhookController::SECRET_PROVIDER),
            'verify_token_stored' => $has(MetaWebhookController::VERIFY_TOKEN_PROVIDER),
            'webhook_flag' => $this->features->enabled(MetaWebhookController::FLAG),
            'health_flag' => $this->features->enabled('connectors.meta_health'),
            'tokens' => $tokens,
        ];
    }

    /** @param  list<Business>  $businesses */
    private function provider(ConnectorDefinition $connector, array $businesses): array
    {
        $sandbox = $connector->provider === 'sandbox';
        $needs = [];
        $ready = $connector->credentials === [];
        foreach ($connector->credentials as $requirement) {
            if (str_starts_with($requirement->accountRefFrom, '=')) {
                $stored = IntegrationCredential::query()->where('provider', $requirement->provider)->where('account_ref', substr($requirement->accountRefFrom, 1))->where('status', 'active')->exists();
                $needs[] = ['kind' => $requirement->provider, 'for' => 'HQ', 'stored' => $stored];
                $ready = $stored;
            } else {
                $with = IntegrationCredential::query()->where('provider', $requirement->provider)->where('status', 'active')
                    ->whereIn('account_ref', array_map(static fn (Business $b) => $b->slug, $businesses))->pluck('account_ref')->all();
                $needs[] = ['kind' => $requirement->provider, 'for' => 'each business', 'stored' => $with !== [], 'businesses' => $with];
                $ready = $with !== [];
            }
        }

        return [
            'capability' => $connector->capability, 'provider' => $connector->provider, 'sandbox' => $sandbox,
            'switch_on' => $this->registry->enabled($connector), 'needs' => $needs, 'credentials_stored' => $ready,
        ];
    }

    /** @param  list<array<string, mixed>>  $providers */
    private function state(bool $enabled, array $providers, bool $noProvider): string
    {
        if ($noProvider) {
            return $enabled ? 'ready' : 'off';
        }
        if (! $enabled) {
            return 'off';
        }
        foreach ($providers as $p) {
            if ($p['credentials_stored']) {
                return $p['sandbox'] ? 'sandbox' : 'ready';
            }
        }

        return 'needs_credentials';
    }
}
