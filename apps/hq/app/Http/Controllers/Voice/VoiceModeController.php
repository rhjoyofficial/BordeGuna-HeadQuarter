<?php

namespace App\Http\Controllers\Voice;

use App\Http\Controllers\Controller;
use App\Voice\SpeechProviders;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The owner's voice mode screen (docs/135 W-UI5, artboard 5), INERT by construction.
 *
 * Owner voice mode is Phase 7 (`docs/89` section 3.14) and needs a ruling before any build (docs/135 (e), ruling
 * item 2): a browser microphone, a speech provider, speech in and out. None of it exists, so this screen only
 * shows what voice mode will look like and says it is not available. It reads one fact, whether any speech provider
 * is registered (it ships empty), and writes nothing. The page itself uses no microphone and sends no audio; a
 * structural test holds that no screen does.
 */
class VoiceModeController extends Controller
{
    public function __invoke(SpeechProviders $providers): Response
    {
        return Inertia::render('voice', [
            'voice' => [
                'available' => false,
                'phase' => 'Phase 7',
                'speech_provider_registered' => ! $providers->isEmpty(),
            ],
        ]);
    }
}
