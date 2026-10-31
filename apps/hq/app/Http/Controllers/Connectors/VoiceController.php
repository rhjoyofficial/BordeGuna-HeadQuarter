<?php

namespace App\Http\Controllers\Connectors;

use App\Gateway\OutcomeStatus;
use App\Media\Upload\AttachmentRejected;
use App\Media\Upload\ChatMediaUploader;
use App\Media\Upload\MediaRetention;
use App\Support\Features\Features;
use App\Voice\SpeechSynthesisRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Voice (docs/144-voice-provider): push to talk in, an answer read aloud out. Both go through the Tool Gateway as the
 * signed-in person. A recording reaches HQ only because the person clicked to send it; the transcript comes back to the
 * browser as plain text for the composer and is sent through Ask HQ like typed text, with no extra authority.
 */
final class VoiceController extends ConnectorController
{
    public function transcribe(Request $request, ChatMediaUploader $uploader): JsonResponse
    {
        $data = $request->validate([
            'audio' => ['required', 'file', 'max:'.(int) config('hq.media.limits.audio_mb', 10) * 1024],
            'language' => ['nullable', 'in:bn,en'],
            // Consent to KEEP the transcript, for this recording only. Without it the transcript is shown and dropped.
            'keep_transcript' => ['nullable', 'boolean'],
        ]);
        if (! app(Features::class)->enabled('connectors.voice')) {
            return response()->json(['error' => 'Not connected yet: voice is switched off.', 'state' => 'disabled'], 409);
        }
        $file = $request->file('audio');

        try {
            $attachment = $uploader->store($request->user(), (string) file_get_contents((string) $file->getRealPath()), 'recording', null, ['audio'], personal: true, retentionDays: 1);
        } catch (AttachmentRejected $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $outcome = $this->runTool($request->user(), 'voice.transcribe', array_filter([
            'attachment_uuid' => $attachment->uuid, 'language' => $data['language'] ?? null, 'keep_transcript' => (bool) ($data['keep_transcript'] ?? false),
        ], static fn ($v) => $v !== null));

        if (! in_array($outcome->status, [OutcomeStatus::Succeeded, OutcomeStatus::Replayed], true)) {
            // The recording must not outlive a refusal either (the tool discards it once it ran; a refusal before that does not).
            $left = $attachment->fresh();
            if ($left !== null && $left->status !== 'purged') {
                app(MediaRetention::class)->discard($left);
            }

            return response()->json(['error' => (string) ($outcome->error ?? $outcome->policyReason ?? 'Voice is not available.'), 'state' => $outcome->output['connector_state'] ?? $outcome->status->value], 409);
        }

        return response()->json($outcome->output);
    }

    public function speak(Request $request): JsonResponse
    {
        $data = $request->validate(['text' => ['required', 'string', 'max:'.SpeechSynthesisRequest::MAX_CHARACTERS], 'language' => ['nullable', 'in:bn-BD,en-US']]);
        $outcome = $this->runTool($request->user(), 'voice.synthesize', array_filter($data, static fn ($v) => $v !== null));
        if (! in_array($outcome->status, [OutcomeStatus::Succeeded, OutcomeStatus::Replayed], true)) {
            return response()->json(['error' => (string) ($outcome->error ?? $outcome->policyReason ?? 'Voice is not available.'), 'state' => $outcome->output['connector_state'] ?? $outcome->status->value], 409);
        }

        return response()->json($outcome->output + ['url' => route('media.show', $outcome->output['attachment_uuid'])]);
    }
}
