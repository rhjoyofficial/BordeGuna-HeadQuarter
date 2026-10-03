<?php

namespace App\Connectors;

use App\Ai\Providers\ModelProvider;
use App\Commerce\Contracts\CommercePlatform;
use App\Conversation\Channels\OutboundChannelAdapter;
use App\Erp\ErpPlatform;
use App\Platform\Contracts\AdsReadPlatform;
use App\Platform\Contracts\CommentChannel;
use App\Platform\Contracts\PublishingPlatform;
use App\Platform\Contracts\StorageDriver;
use App\Research\SearchProvider;
use App\Voice\SpeechToText;
use App\Voice\TextToSpeech;

/**
 * The capability catalogue: every stable capability name HQ knows, and the PHP contract an adapter
 * must implement to serve it (docs/97 §6 D1 and D2, docs/96 §5).
 *
 * ⭐ **The capability name is the durable object, not the vendor.** `voice.transcribe` outlives Google
 * and ElevenLabs alike; a provider is a row in `config/hq-connectors.php`, an adapter is a class that
 * implements the contract named here, and replacing one never touches anything that asks for the
 * capability.
 *
 * Names are `domain.object.verb` (or `domain.verb` where the object is the domain), lower case,
 * dot-separated. A name is never renamed once used; a changed contract is a new name.
 *
 * ⛔ **Reserved names have no contract yet** and nothing can be registered against them: the
 * registry refuses at load. They are listed so the name is settled before the adapter work starts
 * (docs/97 §3 item 1), not invented twice by two streams. Defining the contract interface is the
 * first step of building one; it moves the name from `RESERVED` to `CONTRACTS`.
 */
final class Capabilities
{
    public const AI_COMPLETE = 'ai.complete';

    public const VOICE_TRANSCRIBE = 'voice.transcribe';

    public const VOICE_SYNTHESIZE = 'voice.synthesize';

    public const COMMERCE_SHOP = 'commerce.shop';

    public const ERP_READ = 'erp.read';

    public const RESEARCH_SEARCH = 'research.search';

    public const MESSAGING_WHATSAPP_SEND = 'messaging.whatsapp.send';

    public const MESSAGING_MESSENGER_SEND = 'messaging.messenger.send';

    public const MESSAGING_INSTAGRAM_SEND = 'messaging.instagram.send';

    public const COMMENTS_MANAGE = 'comments.manage';

    public const CONTENT_PUBLISH = 'content.publish';

    public const ADS_READ = 'ads.read';

    public const STORAGE_OBJECT = 'storage.object';

    public const MEDIA_IMAGE_GENERATE = 'media.image.generate';

    public const MEDIA_VIDEO_GENERATE = 'media.video.generate';

    public const VOICE_CALL = 'voice.call';

    public const COURIER_TRACK = 'courier.track';

    /**
     * Capability => the interface an adapter must implement.
     *
     * `erp.read` names `ErpPlatform`, the interface extracted from the one ERP client on ruling 9.2
     * (docs/101 §11; docs/98 §5.5). It used to name the final class, which no second adapter could be.
     *
     * @var array<string, class-string>
     */
    public const CONTRACTS = [
        self::AI_COMPLETE => ModelProvider::class,
        self::VOICE_TRANSCRIBE => SpeechToText::class,
        self::VOICE_SYNTHESIZE => TextToSpeech::class,
        self::COMMERCE_SHOP => CommercePlatform::class,
        self::ERP_READ => ErpPlatform::class,
        self::RESEARCH_SEARCH => SearchProvider::class,
        self::MESSAGING_WHATSAPP_SEND => OutboundChannelAdapter::class,
        self::MESSAGING_MESSENGER_SEND => OutboundChannelAdapter::class,
        self::MESSAGING_INSTAGRAM_SEND => OutboundChannelAdapter::class,
        self::COMMENTS_MANAGE => CommentChannel::class,
        self::CONTENT_PUBLISH => PublishingPlatform::class,
        self::ADS_READ => AdsReadPlatform::class,
        self::STORAGE_OBJECT => StorageDriver::class,
    ];

    /** Names settled, contracts not yet written (docs/97 §2 INTEGRATE rows). */
    public const RESERVED = [
        self::MEDIA_IMAGE_GENERATE,
        self::MEDIA_VIDEO_GENERATE,
        self::VOICE_CALL,
        self::COURIER_TRACK,
    ];

    public static function known(string $capability): bool
    {
        return isset(self::CONTRACTS[$capability]) || in_array($capability, self::RESERVED, true);
    }

    public static function reserved(string $capability): bool
    {
        return in_array($capability, self::RESERVED, true);
    }

    /** @return class-string|null null for a reserved or unknown name */
    public static function contract(string $capability): ?string
    {
        return self::CONTRACTS[$capability] ?? null;
    }

    /** @return list<string> every name, contracted first */
    public static function names(): array
    {
        return [...array_keys(self::CONTRACTS), ...self::RESERVED];
    }

    /** @return list<class-string> the distinct adapter contracts, for the structural test */
    public static function contracts(): array
    {
        return array_values(array_unique(array_values(self::CONTRACTS)));
    }
}
