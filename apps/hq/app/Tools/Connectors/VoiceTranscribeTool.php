<?php

namespace App\Tools\Connectors;

use App\Ai\Exceptions\AiPaused;
use App\Ai\Pause\AiPause;
use App\Connectors\Capabilities;
use App\Connectors\ConnectorRegistry;
use App\Connectors\ConnectorRequest;
use App\Gateway\OutcomeClass;
use App\Media\MediaAssetService;
use App\Media\Upload\MediaRetention;
use App\Models\Business;
use App\Models\ChatAttachment;
use App\Models\VoiceTranscript;
use App\Support\Audit\Auditor;
use App\Tools\ToolContext;
use App\Tools\ToolResult;
use App\Voice\Exceptions\SpeechProviderRefused;
use App\Voice\Exceptions\SpeechProviderUnavailable;
use App\Voice\Providers\GoogleSpeechToText;
use App\Voice\Providers\OpenAiCompatibleSpeechToText;
use App\Voice\SpeechToText;
use App\Voice\SttBenchmarkGate;
use Illuminate\Support\Str;

/**
 * `voice.transcribe`: turn the person's own recording into text through the speech provider (docs/144-voice-provider).
 *
 * ⛔ **No authority comes with a voice.** The answer is an untrusted transcript the screen puts in the composer; the
 * person sends it through the normal Ask HQ path like typed text, where the same gateway, approvals and business scope
 * apply. This tool decides nothing about what the words mean.
 * ⛔ **Consent.** The recording is deleted as soon as it is transcribed. The transcript is stored only when the
 * person said so for THIS recording (`keep_transcript`), visible only to them, and it expires.
 * ⛔ Refused while Pause All AI is on, and only the person's own recording can be named.
 */
final class VoiceTranscribeTool extends ConnectorTool
{
    public function key(): string
    {
        return 'voice.transcribe';
    }

    protected function title(): string
    {
        return 'Transcribe a recording';
    }

    protected function purpose(): string
    {
        return 'Sends the person\'s own voice recording to the speech provider and returns an untrusted transcript for the composer. '
            .'The recording is deleted afterwards; the transcript is kept only if the person agreed. A voice carries no extra authority.';
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
        return [OpenAiCompatibleSpeechToText::CREDENTIAL => '=hq', GoogleSpeechToText::CREDENTIAL_PROVIDER => '=hq'];
    }

    protected function arguments(): array
    {
        return [
            'attachment_uuid' => ['type' => 'string', 'minLength' => 36, 'maxLength' => 36],
            'language' => ['type' => 'string', 'enum' => ['bn', 'en']],
            'keep_transcript' => ['type' => 'boolean'],
        ];
    }

    protected function required(): array
    {
        return ['attachment_uuid'];
    }

    protected function run(?Business $business, array $payload, ToolContext $context): ToolResult
    {
        try {
            app(AiPause::class)->assertRunning('voice transcription');
        } catch (AiPaused $e) {
            return ToolResult::failed($e->getMessage(), [], OutcomeClass::PolicyDenied);
        }

        // Ruling 31 Oct item 8: no speech-to-text production default until a provider passes the Bangla bake-off. Refused here, before
        // the recording is read or any provider is resolved.
        if (($gate = app(SttBenchmarkGate::class))->passedProvider() === null) {
            return ToolResult::failed((string) $gate->refusalFor(''), ['connector_state' => 'benchmark_pending'], OutcomeClass::CapabilityUnavailable);
        }

        $attachment = ChatAttachment::query()->where('uuid', $payload['attachment_uuid'])->where('user_id', $context->actorUserId)
            ->where('kind', 'audio')->where('status', '!=', 'purged')->first();
        if ($attachment === null) {
            return ToolResult::failed('That recording is not yours or no longer exists.', [], OutcomeClass::ScopeDenied);
        }

        $registry = app(ConnectorRegistry::class);
        $resolution = $registry->resolve(new ConnectorRequest(Capabilities::VOICE_TRANSCRIBE));
        $stt = $resolution->resolvedOk() ? $registry->adapter($resolution) : null;
        if (! $stt instanceof SpeechToText) {
            return ToolResult::failed('Not connected yet: '.($resolution->reason ?? $resolution->outcome->value), ['connector_state' => $resolution->outcome->value], OutcomeClass::CapabilityUnavailable);
        }
        if (($refusal = $gate->refusalFor($stt->key())) !== null) {
            return ToolResult::failed($refusal, ['connector_state' => 'benchmark_pending'], OutcomeClass::CapabilityUnavailable);
        }
        $audio = $attachment->asset === null ? null : app(MediaAssetService::class)->contents($attachment->asset);
        if ($audio === null) {
            return ToolResult::failed('The recording is gone.', [], OutcomeClass::InvalidArguments);
        }

        try {
            $transcript = $stt->transcribe($audio, (string) $attachment->asset->mime, $payload['language'] ?? null);
        } catch (SpeechProviderRefused $e) {
            return ToolResult::failed($e->getMessage(), ['connector_state' => 'refused'], OutcomeClass::ConnectorError);
        } catch (SpeechProviderUnavailable $e) {
            return ToolResult::failed($e->getMessage(), ['connector_state' => 'unavailable'], OutcomeClass::ProviderUnavailable);
        } finally {
            // The recording is not kept: it is deleted whether or not the provider understood it.
            app(MediaRetention::class)->discard($attachment);
        }

        $stored = null;
        if (($payload['keep_transcript'] ?? false) === true) {
            $stored = VoiceTranscript::create([
                'uuid' => (string) Str::uuid(), 'user_id' => $context->actorUserId, 'provider' => $transcript->provider, 'language' => $transcript->languageCode,
                'text' => $transcript->text, 'confidence' => $transcript->confidence, 'expires_at' => now()->addDays((int) config('hq.voice.transcript_retention_days', 30)),
            ]);
        }
        app(Auditor::class)->recordSystem('voice.transcribed', $stored, null, ['provider' => $transcript->provider, 'kept' => $stored !== null, 'chars' => mb_strlen($transcript->text), 'user_id' => $context->actorUserId]);

        return ToolResult::succeeded([
            'text' => $transcript->text, 'language' => $transcript->languageCode, 'confidence' => $transcript->confidence, 'provider' => $transcript->provider,
            'provenance' => 'untrusted', 'transcript_uuid' => $stored?->uuid, 'note' => 'Send it through Ask HQ like typed text; speech adds no authority.',
        ]);
    }
}
