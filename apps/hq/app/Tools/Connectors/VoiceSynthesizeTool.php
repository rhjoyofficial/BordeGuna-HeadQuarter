<?php

namespace App\Tools\Connectors;

use App\Ai\Exceptions\AiPaused;
use App\Ai\Pause\AiPause;
use App\Connectors\Capabilities;
use App\Connectors\ConnectorRegistry;
use App\Connectors\ConnectorRequest;
use App\Context\Provenance;
use App\Gateway\OutcomeClass;
use App\Media\MediaAssetService;
use App\Models\Business;
use App\Models\ChatAttachment;
use App\Support\Audit\Auditor;
use App\Tools\ToolContext;
use App\Tools\ToolResult;
use App\Voice\Exceptions\SpeechProviderRefused;
use App\Voice\Exceptions\SpeechProviderUnavailable;
use App\Voice\Providers\AzureTextToSpeech;
use App\Voice\Providers\ElevenLabsTextToSpeech;
use App\Voice\Providers\OpenAiCompatibleSpeechToText;
use App\Voice\SpeechSynthesisRequest;
use App\Voice\TextToSpeech;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * `voice.synthesize`: read an answer aloud to the person who is looking at it (docs/144-voice-provider). A stock
 * voice only (a cloned voice is refused before this tool sees it). The audio is kept for the person alone, for one day.
 * It sends nothing to anyone: sending audio to a customer is an outbound message with its own tool, consent and
 * approval. Refused while Pause All AI is on.
 */
final class VoiceSynthesizeTool extends ConnectorTool
{
    public function key(): string
    {
        return 'voice.synthesize';
    }

    protected function title(): string
    {
        return 'Read an answer aloud';
    }

    protected function purpose(): string
    {
        return 'Turns a piece of text into speech in a stock voice and keeps the audio for the person for one day. Sends it to no one.';
    }

    protected function flag(): string
    {
        return 'connectors.voice';
    }

    protected function permission(): string
    {
        return 'hq.voice.use';
    }

    protected function businessScoped(): bool
    {
        return false;
    }

    protected function credentialSources(): array
    {
        return [OpenAiCompatibleSpeechToText::CREDENTIAL => '=hq', AzureTextToSpeech::CREDENTIAL_PROVIDER => '=hq', ElevenLabsTextToSpeech::CREDENTIAL_PROVIDER => '=hq'];
    }

    protected function arguments(): array
    {
        return [
            'text' => ['type' => 'string', 'minLength' => 1, 'maxLength' => SpeechSynthesisRequest::MAX_CHARACTERS],
            'voice_id' => ['type' => 'string', 'maxLength' => 64],
            'language' => ['type' => 'string', 'enum' => ['bn-BD', 'en-US']],
        ];
    }

    protected function required(): array
    {
        return ['text'];
    }

    protected function run(?Business $business, array $payload, ToolContext $context): ToolResult
    {
        try {
            app(AiPause::class)->assertRunning('speech synthesis');
        } catch (AiPaused $e) {
            return ToolResult::failed($e->getMessage(), [], OutcomeClass::PolicyDenied);
        }

        $registry = app(ConnectorRegistry::class);
        $resolution = $registry->resolve(new ConnectorRequest(Capabilities::VOICE_SYNTHESIZE));
        $tts = $resolution->resolvedOk() ? $registry->adapter($resolution) : null;
        if (! $tts instanceof TextToSpeech) {
            return ToolResult::failed('Not connected yet: '.($resolution->reason ?? $resolution->outcome->value), ['connector_state' => $resolution->outcome->value], OutcomeClass::CapabilityUnavailable);
        }

        $voice = $payload['voice_id'] ?? config('hq.voice.default_voice.'.$tts->key());
        try {
            $speech = $tts->synthesize(new SpeechSynthesisRequest((string) $payload['text'], (string) $voice, (string) ($payload['language'] ?? 'bn-BD')));
        } catch (InvalidArgumentException|SpeechProviderRefused $e) {
            return ToolResult::failed($e->getMessage(), ['connector_state' => 'refused'], OutcomeClass::ConnectorError);
        } catch (SpeechProviderUnavailable $e) {
            return ToolResult::failed($e->getMessage(), ['connector_state' => 'unavailable'], OutcomeClass::ProviderUnavailable);
        }

        $asset = app(MediaAssetService::class)->store($speech->audio, $speech->mime, 'audio', Provenance::Generated, [
            'source_ref' => 'voice_synthesis', 'retention_days' => 1, 'expires_at' => now()->addDay(), 'created_by' => $context->actorUserId,
            'generator' => ['provider' => $speech->provider, 'model' => $speech->model, 'voice' => $speech->voiceId],
        ]);
        $attachment = ChatAttachment::create([
            'uuid' => (string) Str::uuid(), 'user_id' => $context->actorUserId, 'business_id' => null, 'media_asset_id' => $asset->id,
            'original_name' => 'answer.mp3', 'kind' => 'audio', 'status' => 'stored', 'expires_at' => now()->addDay(),
        ]);
        app(Auditor::class)->recordSystem('voice.synthesized', $attachment, null, ['provider' => $speech->provider, 'chars' => mb_strlen((string) $payload['text']), 'user_id' => $context->actorUserId]);

        return ToolResult::succeeded(['attachment_uuid' => $attachment->uuid, 'mime' => $speech->mime, 'provider' => $speech->provider]);
    }
}
