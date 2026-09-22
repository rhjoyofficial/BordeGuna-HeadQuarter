<?php

namespace App\Voice;

use App\Context\Provenance;
use App\Conversation\Channels\InboundMessage;
use App\Conversation\ConversationService;
use App\Media\MediaAssetService;
use App\Models\ConversationMessage;
use App\Models\MediaAsset;
use App\Support\Audit\Auditor;
use App\Voice\Exceptions\SpeechProviderRefused;
use App\Voice\Exceptions\SpeechProviderUnavailable;

/**
 * A customer's voice note into the conversation core (Phase 7, docs/88 A point 6).
 *
 * In order: a redelivered voice note is recognised and nothing is transcribed twice; the audio is kept as
 * a media asset labelled `Provenance::Customer`, personal data with a retention period; the registered
 * speech-to-text provider (if any) turns it into a `Transcript`; and the message goes into
 * `ConversationService::ingest()` with the transcript as its text, exactly the path a typed message takes.
 *
 * ⛔ **The same words, the same authority.** The transcript is the message body and nothing else: no flag,
 * no label, no metadata field that any boundary reads as authority. `ingest()` records every inbound
 * message untrusted by construction, the escalation triggers read the text as they read typed text, and
 * the agent's context shows it under the customer's provenance. A voice channel never grants authority;
 * the lineage beside the text (audio asset, provider, model, language, confidence, time) is there so a
 * person can check what the machine heard, not to make it more believable.
 *
 * ⛔ **Inert in this release.** `SpeechProviders` ships empty, so the audio is kept and the message is
 * recorded without text, as before this class existed, and no audio leaves HQ. A provider that refuses or
 * fails leaves the same result: a voice note with no transcript, never a guess.
 */
final readonly class VoiceNoteIngestion
{
    public function __construct(
        private ConversationService $conversations,
        private MediaAssetService $media,
        private SpeechProviders $providers,
        private Auditor $auditor,
    ) {}

    public function ingest(InboundMessage $inbound, string $audio, string $mime): ConversationMessage
    {
        // A redelivered webhook must not send the same audio to a provider again (it is paid per use).
        $existing = ConversationMessage::query()
            ->where('channel', $inbound->channel->value)
            ->where('external_message_ref', $inbound->externalMessageRef)
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        $asset = $this->media->store($audio, $mime, 'audio', Provenance::Customer, [
            'source_ref' => "channel:{$inbound->channel->value}:{$inbound->externalMessageRef}",
            'data_class' => 'personal',
            'retention_days' => (int) config('hq.voice.retention_days', 90),
        ]);

        [$transcript, $lineage] = $this->transcribe($audio, $mime, $asset);

        $body = $inbound->body;
        if ($transcript !== null && ! $transcript->isEmpty()) {
            // The transcript IS the message, as if the customer had typed it; a caption they also typed stays first.
            $body = trim(implode("\n", array_filter([trim((string) $inbound->body), $transcript->text], static fn ($t) => $t !== '')));
        }

        $message = $this->conversations->ingest(new InboundMessage(
            channel: $inbound->channel,
            platformAccountRef: $inbound->platformAccountRef,
            externalThreadRef: $inbound->externalThreadRef,
            externalMessageRef: $inbound->externalMessageRef,
            senderExternalId: $inbound->senderExternalId,
            body: $body,
            receivedAt: $inbound->receivedAt,
            digest: $inbound->digest,
            attachments: $inbound->attachments,
            metadata: [...$inbound->metadata, 'voice_note' => $lineage],
        ));

        // The asset learns its business once the core has decided which business the message belongs to.
        if ($asset->business_id === null) {
            $conversation = $message->conversation()->first(['id', 'business_id', 'brand_id']);
            $asset->forceFill(['business_id' => $conversation?->business_id, 'brand_id' => $conversation?->brand_id])->save();
        }

        $this->auditor->recordSystem($transcript !== null ? 'voice_note.transcribed' : 'voice_note.kept_without_transcript', $message, after: [
            // The lineage only: the words are in the message, under the customer's provenance, and not repeated here.
            ...$lineage,
            'text_length' => $transcript !== null ? mb_strlen($transcript->text) : 0,
        ]);

        return $message;
    }

    /**
     * @return array{0: ?Transcript, 1: array<string, mixed>}
     */
    private function transcribe(string $audio, string $mime, MediaAsset $asset): array
    {
        $provider = $this->providers->speechToText();
        if ($provider === null) {
            return [null, [
                'source' => 'voice_note',
                'transcribed' => false,
                'audio_asset_uuid' => $asset->uuid,
                'reason' => 'no speech-to-text provider is registered',
            ]];
        }

        try {
            $transcript = $provider->transcribe($audio, $mime);
        } catch (SpeechProviderRefused|SpeechProviderUnavailable $e) {
            return [null, [
                'source' => 'voice_note',
                'transcribed' => false,
                'audio_asset_uuid' => $asset->uuid,
                'provider' => $provider->key(),
                'reason' => substr($e->getMessage(), 0, 200),
            ]];
        }

        return [$transcript, [
            ...$transcript->lineage($asset->uuid),
            'low_confidence' => $transcript->isLowConfidence((float) config('hq.voice.low_confidence', 0.6)),
            'empty' => $transcript->isEmpty(),
        ]];
    }
}
