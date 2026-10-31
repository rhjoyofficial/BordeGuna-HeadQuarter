<?php

use App\Ads\Draft\FakeAdsDraftPlatform;
use App\Ads\Draft\MetaAdsDraftPlatform;
use App\Courier\Drivers\CarryBeeCourier;
use App\Courier\Drivers\PathaoCourier;
use App\Courier\Drivers\RedxCourier;
use App\Courier\Drivers\SteadfastCourier;
use App\Courier\Fake\FakeCarryBeeCourier;
use App\Courier\Fake\FakePathaoCourier;
use App\Courier\Fake\FakeRedxCourier;
use App\Courier\Fake\FakeSteadfastCourier;
use App\Erp\Write\FakeErpDraftBillWriter;
use App\Erp\Write\HttpErpDraftBillWriter;
use App\Ingestion\Mailbox\FakeMailbox;
use App\Ingestion\Mailbox\GmailMailbox;
use App\Ingestion\Mailbox\GraphMailbox;
use App\Media\Pipeline\FakeMediaPipeline;
use App\Media\Pipeline\VeoMediaPipeline;
use App\Voice\Fake\FakeSpeechToText;
use App\Voice\Fake\FakeTextToSpeech;
use App\Voice\Providers\AzureTextToSpeech;
use App\Voice\Providers\ElevenLabsTextToSpeech;
use App\Voice\Providers\GoogleSpeechToText;
use App\Voice\Providers\OpenAiCompatibleSpeechToText;
use App\Voice\Providers\OpenAiCompatibleTextToSpeech;

/*
 | The ConnectorRegistry (docs/97 §6 D2, docs/98): which provider serves which capability.
 |
 | ⛔ Every row here is OFF: `enabled_by` names a feature flag that ships OFF (the migration
 | `seed_connector_flags_off`), and a row also needs its credential stored before it can serve anything.
 | The rows below (docs/144-*) are the HQ-completion connectors. Every NEW external adapter enters here and
 | nowhere else (a structural test holds it); the five legacy shapes are reported by read-only bridges.
 |
 | One row per connector:
 |
 |   [
 |       'capability' => 'voice.transcribe',          // a name from App\Connectors\Capabilities
 |       'provider' => 'google_chirp',                 // lower-case key, unique per capability
 |       'adapter' => SomeAdapter::class,              // implements the capability's contract
 |       'transport' => 'direct_api',                  // direct_api | mcp | n8n | internal
 |       'credentials' => [                            // integration_credentials kinds, never values
 |           ['kind' => 'google_speech_access_token', 'account_ref_from' => '=hq'],
 |       ],                                            // account_ref_from: =literal | business.slug | brand.slug | platform_account.ref
 |       'priority' => 10,                             // lower is tried first; a tie at the top is refused
 |       'enabled_by' => 'flag:connectors.voice.google', // flag:<feature flag> | config:<config key>; never on by default
 |       'fallback' => false,                          // a fallback is tried only when no primary is ready
 |       'businesses' => null,                         // optional: business slugs served; null = any
 |       'platform' => null,                           // optional: a platform_accounts.platform
 |   ],
 |
 | Sandbox: with `HQ_CONNECTORS_SANDBOX=true` (never set in production) the fake adapters are registered too, last in
 | priority and under the same flags, so a person can try every screen before any account is connected.
 */

$business = 'business.slug';

$real = [
    // Couriers: one capability each (docs/144-courier-connectors). Reads and listens; books nothing.
    ['capability' => 'courier.pathao.track', 'provider' => 'pathao', 'adapter' => PathaoCourier::class, 'transport' => 'direct_api',
        'credentials' => [['kind' => PathaoCourier::CREDENTIAL, 'account_ref_from' => $business]], 'priority' => 10, 'enabled_by' => 'flag:connectors.courier'],
    ['capability' => 'courier.carrybee.track', 'provider' => 'carrybee', 'adapter' => CarryBeeCourier::class, 'transport' => 'direct_api',
        'credentials' => [['kind' => CarryBeeCourier::CREDENTIAL, 'account_ref_from' => $business]], 'priority' => 10, 'enabled_by' => 'flag:connectors.courier'],
    ['capability' => 'courier.steadfast.track', 'provider' => 'steadfast', 'adapter' => SteadfastCourier::class, 'transport' => 'direct_api',
        'credentials' => [['kind' => SteadfastCourier::CREDENTIAL, 'account_ref_from' => $business]], 'priority' => 10, 'enabled_by' => 'flag:connectors.courier'],
    ['capability' => 'courier.redx.track', 'provider' => 'redx', 'adapter' => RedxCourier::class, 'transport' => 'direct_api',
        'credentials' => [['kind' => RedxCourier::CREDENTIAL, 'account_ref_from' => $business]], 'priority' => 10, 'enabled_by' => 'flag:connectors.courier'],

    // Voice (docs/144-voice-provider): OpenAI-compatible is the default; the adapters that already existed stay as alternatives.
    ['capability' => 'voice.transcribe', 'provider' => 'openai_compatible', 'adapter' => OpenAiCompatibleSpeechToText::class, 'transport' => 'direct_api',
        'credentials' => [['kind' => OpenAiCompatibleSpeechToText::CREDENTIAL, 'account_ref_from' => '=hq']], 'priority' => 10, 'enabled_by' => 'flag:connectors.voice'],
    ['capability' => 'voice.transcribe', 'provider' => 'google_speech', 'adapter' => GoogleSpeechToText::class, 'transport' => 'direct_api',
        'credentials' => [['kind' => GoogleSpeechToText::CREDENTIAL_PROVIDER, 'account_ref_from' => '=hq']], 'priority' => 20, 'enabled_by' => 'flag:connectors.voice'],
    ['capability' => 'voice.synthesize', 'provider' => 'openai_compatible', 'adapter' => OpenAiCompatibleTextToSpeech::class, 'transport' => 'direct_api',
        'credentials' => [['kind' => OpenAiCompatibleSpeechToText::CREDENTIAL, 'account_ref_from' => '=hq']], 'priority' => 10, 'enabled_by' => 'flag:connectors.voice'],
    ['capability' => 'voice.synthesize', 'provider' => 'azure_speech', 'adapter' => AzureTextToSpeech::class, 'transport' => 'direct_api',
        'credentials' => [['kind' => AzureTextToSpeech::CREDENTIAL_PROVIDER, 'account_ref_from' => '=hq']], 'priority' => 20, 'enabled_by' => 'flag:connectors.voice'],
    ['capability' => 'voice.synthesize', 'provider' => 'elevenlabs', 'adapter' => ElevenLabsTextToSpeech::class, 'transport' => 'direct_api',
        'credentials' => [['kind' => ElevenLabsTextToSpeech::CREDENTIAL_PROVIDER, 'account_ref_from' => '=hq']], 'priority' => 30, 'enabled_by' => 'flag:connectors.voice'],

    // Video drafts (docs/144-media-pipeline).
    ['capability' => 'media.video.generate', 'provider' => 'veo', 'adapter' => VeoMediaPipeline::class, 'transport' => 'direct_api',
        'credentials' => [['kind' => VeoMediaPipeline::CREDENTIAL, 'account_ref_from' => '=hq']], 'priority' => 10, 'enabled_by' => 'flag:connectors.media_pipeline'],

    // Supplier mailbox (docs/144-email-ingestion): reads only.
    ['capability' => 'mail.inbox.read', 'provider' => 'gmail', 'adapter' => GmailMailbox::class, 'transport' => 'direct_api',
        'credentials' => [['kind' => GmailMailbox::CREDENTIAL, 'account_ref_from' => $business]], 'priority' => 10, 'enabled_by' => 'flag:connectors.mail_ingestion'],
    ['capability' => 'mail.inbox.read', 'provider' => 'msgraph', 'adapter' => GraphMailbox::class, 'transport' => 'direct_api',
        'credentials' => [['kind' => GraphMailbox::CREDENTIAL, 'account_ref_from' => $business]], 'priority' => 20, 'enabled_by' => 'flag:connectors.mail_ingestion'],

    // ERP draft bill (docs/144-erp-write-contract): a different token from the read token.
    ['capability' => 'erp.draft_bill.create', 'provider' => 'erp_http', 'adapter' => HttpErpDraftBillWriter::class, 'transport' => 'direct_api',
        'credentials' => [['kind' => HttpErpDraftBillWriter::CREDENTIAL, 'account_ref_from' => $business]], 'priority' => 10, 'enabled_by' => 'flag:connectors.erp_draft_bill'],

    // Ad drafts (docs/144-meta-ads-drafts): creates paused objects only.
    ['capability' => 'ads.draft.create', 'provider' => 'meta_ads_draft', 'adapter' => MetaAdsDraftPlatform::class, 'transport' => 'direct_api',
        'credentials' => [['kind' => MetaAdsDraftPlatform::CREDENTIAL, 'account_ref_from' => $business]], 'priority' => 10, 'enabled_by' => 'flag:connectors.ads_draft'],
];

$sandbox = [
    ['capability' => 'courier.pathao.track', 'provider' => 'sandbox', 'adapter' => FakePathaoCourier::class, 'flag' => 'connectors.courier'],
    ['capability' => 'courier.carrybee.track', 'provider' => 'sandbox', 'adapter' => FakeCarryBeeCourier::class, 'flag' => 'connectors.courier'],
    ['capability' => 'courier.steadfast.track', 'provider' => 'sandbox', 'adapter' => FakeSteadfastCourier::class, 'flag' => 'connectors.courier'],
    ['capability' => 'courier.redx.track', 'provider' => 'sandbox', 'adapter' => FakeRedxCourier::class, 'flag' => 'connectors.courier'],
    ['capability' => 'voice.transcribe', 'provider' => 'sandbox', 'adapter' => FakeSpeechToText::class, 'flag' => 'connectors.voice'],
    ['capability' => 'voice.synthesize', 'provider' => 'sandbox', 'adapter' => FakeTextToSpeech::class, 'flag' => 'connectors.voice'],
    ['capability' => 'media.video.generate', 'provider' => 'sandbox', 'adapter' => FakeMediaPipeline::class, 'flag' => 'connectors.media_pipeline'],
    ['capability' => 'mail.inbox.read', 'provider' => 'sandbox', 'adapter' => FakeMailbox::class, 'flag' => 'connectors.mail_ingestion'],
    ['capability' => 'erp.draft_bill.create', 'provider' => 'sandbox', 'adapter' => FakeErpDraftBillWriter::class, 'flag' => 'connectors.erp_draft_bill'],
    ['capability' => 'ads.draft.create', 'provider' => 'sandbox', 'adapter' => FakeAdsDraftPlatform::class, 'flag' => 'connectors.ads_draft'],
];

return [
    'connectors' => [
        ...$real,
        ...(filter_var(env('HQ_CONNECTORS_SANDBOX', false), FILTER_VALIDATE_BOOL)
            ? array_map(static fn (array $s) => [
                'capability' => $s['capability'], 'provider' => $s['provider'], 'adapter' => $s['adapter'], 'transport' => 'internal',
                'credentials' => [], 'priority' => 900, 'enabled_by' => 'flag:'.$s['flag'],
            ], $sandbox)
            : []),
    ],
];
