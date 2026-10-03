<?php

namespace App\Support\Credentials;

use App\Ads\AdsCredential;
use App\Ai\Providers\AnthropicProvider;
use App\Ai\Providers\OpenAiProvider;
use App\Commerce\Support\CommerceRegistry;
use App\Conversation\Channels\Meta\MetaInstagramChannel;
use App\Conversation\Channels\Meta\MetaMessengerChannel;
use App\Conversation\Channels\Meta\WhatsAppCloudChannel;
use App\Erp\ErpRegistry;
use App\Http\Controllers\Webhooks\MetaForwardController;
use App\Http\Controllers\Webhooks\MetaWebhookController;
use App\Research\Providers\BraveSearch;
use App\Research\Providers\TavilySearch;
use App\Voice\Providers\AzureTextToSpeech;
use App\Voice\Providers\ElevenLabsTextToSpeech;
use App\Voice\Providers\GoogleSpeechToText;

/**
 * Every credential kind (`integration_credentials.provider`) the code reads, and nothing else
 * (docs/95 §5 "Operations", stream W2b item 4).
 *
 * The provider field on `/credentials` was free text with a hint naming kinds no code reads
 * (`meta`, `telegram`, `mimsms`), so a row stored under a wrong name was silently useless. This list
 * is now the only thing the screen offers and the only thing the store accepts. Each entry says which
 * account reference the reader looks the value up by, because a right kind under a wrong reference is
 * just as useless; and its purpose in one line.
 *
 * Built from the readers' own constants, so renaming one moves it here. A structural test greps the
 * application for every kind it reads and fails when one is missing from this list. Telegram is env,
 * not vault, and has no entry.
 */
final class CredentialKinds
{
    public const ACCOUNT_STORE = 'store slug';

    public const ACCOUNT_BUSINESS = 'business slug';

    public const ACCOUNT_HQ = 'hq';

    public const ACCOUNT_PAGE = 'Facebook Page id';

    public const ACCOUNT_INSTAGRAM = 'Instagram account id';

    public const ACCOUNT_WHATSAPP = 'WhatsApp phone_number_id';

    /**
     * @return array<string, array{account_ref: string, purpose: string}> kind => its account reference rule and purpose
     */
    public static function all(): array
    {
        return [
            CommerceRegistry::ADMIN_PROVIDER => ['account_ref' => self::ACCOUNT_STORE, 'purpose' => 'The shop admin API token HQ reads orders with.'],
            CommerceRegistry::ASSISTANT_PROVIDER => ['account_ref' => self::ACCOUNT_STORE, 'purpose' => 'The shop assistant API key for catalogue and order lookups.'],
            'shop_webhook_secret' => ['account_ref' => self::ACCOUNT_STORE, 'purpose' => 'The secret that signs the webhooks a shop sends to HQ.'],
            ErpRegistry::PROVIDER => ['account_ref' => self::ACCOUNT_BUSINESS, 'purpose' => 'The ERP API token for one business.'],
            OpenAiProvider::CREDENTIAL_PROVIDER => ['account_ref' => OpenAiProvider::ACCOUNT_REF, 'purpose' => 'The OpenAI API key the AI runtime uses.'],
            AnthropicProvider::CREDENTIAL_PROVIDER => ['account_ref' => AnthropicProvider::ACCOUNT_REF, 'purpose' => 'The Anthropic API key the AI runtime uses.'],
            MetaMessengerChannel::TOKEN_PROVIDER => ['account_ref' => self::ACCOUNT_PAGE, 'purpose' => 'A Facebook Page access token: Messenger replies and Page posts.'],
            MetaInstagramChannel::TOKEN_PROVIDER => ['account_ref' => self::ACCOUNT_INSTAGRAM, 'purpose' => 'The token for Instagram DMs and Instagram posts.'],
            WhatsAppCloudChannel::TOKEN_PROVIDER => ['account_ref' => self::ACCOUNT_WHATSAPP, 'purpose' => 'The WhatsApp Cloud API system-user token for one number.'],
            MetaWebhookController::SECRET_PROVIDER => ['account_ref' => MetaWebhookController::ACCOUNT_REF, 'purpose' => 'The Meta app secret: checks webhook signatures and signs Graph calls.'],
            MetaWebhookController::VERIFY_TOKEN_PROVIDER => ['account_ref' => MetaWebhookController::ACCOUNT_REF, 'purpose' => 'The verify token Meta sends when the webhook is subscribed.'],
            MetaForwardController::SECRET_PROVIDER => ['account_ref' => MetaForwardController::ACCOUNT_REF, 'purpose' => 'The secret n8n signs forwarded Meta webhooks with.'],
            AdsCredential::PROVIDER => ['account_ref' => self::ACCOUNT_BUSINESS, 'purpose' => 'The Meta system-user token the ads read tools use.'],
            BraveSearch::CREDENTIAL => ['account_ref' => self::ACCOUNT_HQ, 'purpose' => 'The Brave Search API key for research.'],
            TavilySearch::CREDENTIAL => ['account_ref' => self::ACCOUNT_HQ, 'purpose' => 'The Tavily API key for research.'],
            GoogleSpeechToText::CREDENTIAL_PROVIDER => ['account_ref' => GoogleSpeechToText::ACCOUNT_REF, 'purpose' => 'The Google Speech-to-Text access token for voice notes.'],
            ElevenLabsTextToSpeech::CREDENTIAL_PROVIDER => ['account_ref' => ElevenLabsTextToSpeech::ACCOUNT_REF, 'purpose' => 'The ElevenLabs API key for text to speech.'],
            AzureTextToSpeech::CREDENTIAL_PROVIDER => ['account_ref' => AzureTextToSpeech::ACCOUNT_REF, 'purpose' => 'The Azure Speech key for text to speech.'],
        ];
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /** @return list<array{key: string, account_ref: string, purpose: string}> for the screen */
    public static function options(): array
    {
        return array_map(
            static fn (string $key, array $kind): array => ['key' => $key] + $kind,
            array_keys(self::all()),
            self::all(),
        );
    }
}
