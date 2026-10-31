<?php

namespace App\Http\Controllers\Voice;

use App\Connectors\ConnectorHub;
use App\Http\Controllers\Controller;
use App\Voice\SpeechProviders;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The owner's voice mode screen (docs/135 W-UI5, docs/144-voice-provider).
 *
 * The page itself never touches the microphone: it only says whether voice is connected (the switch `connectors.voice`
 * on, and a speech credential stored) and what is still needed when it is not. The browser asks for the microphone only
 * when the person clicks to talk, and only when this says voice is available. A spoken request goes through Ask HQ like
 * typed text and carries no extra authority.
 */
class VoiceModeController extends Controller
{
    public function __invoke(Request $request, SpeechProviders $providers, ConnectorHub $hub): Response
    {
        $family = collect($hub->families($request->user()))->firstWhere('key', 'voice');
        $state = (string) ($family['state'] ?? 'off');

        return Inertia::render('voice', [
            'voice' => [
                'available' => in_array($state, ['ready', 'sandbox'], true),
                'state' => $state,
                'sandbox' => $state === 'sandbox',
                'needs' => collect($family['providers'] ?? [])->where('sandbox', false)->flatMap(fn ($p) => collect($p['needs'])->pluck('kind'))->unique()->values()->all(),
                'switch' => 'connectors.voice',
                'can_use' => $request->user()->can('hq.voice.use'),
                'phase' => 'Phase 7',
                'speech_provider_registered' => ! $providers->isEmpty(),
            ],
        ]);
    }
}
