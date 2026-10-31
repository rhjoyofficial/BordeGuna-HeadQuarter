<?php

namespace App\Http\Controllers\Voice;

use App\Connectors\ConnectorHub;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Support\Authorization\BusinessScope;
use App\Voice\SpeechProviders;
use App\Voice\SttBenchmarkGate;
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
    public function __invoke(Request $request, SpeechProviders $providers, ConnectorHub $hub, BusinessScope $scope): Response
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
                // Ruling 31 Oct item 8: no speech-to-text provider is a production default until the Bangla bake-off passes.
                'stt_benchmark_passed' => app(SttBenchmarkGate::class)->passedProvider() !== null,
            ],
            // Ruling 31 Oct item 12: reading aloud is paid from one business's media budget; only businesses this person may use.
            'businesses' => Business::query()->orderBy('name')->get(['id', 'slug', 'name'])
                ->filter(fn (Business $b) => $scope->allows($request->user(), (int) $b->id))->values()->map(fn (Business $b) => ['slug' => $b->slug, 'name' => $b->name])->all(),
        ]);
    }
}
