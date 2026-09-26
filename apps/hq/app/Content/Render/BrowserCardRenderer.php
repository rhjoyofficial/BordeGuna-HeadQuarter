<?php

namespace App\Content\Render;

use App\Research\ResearchRefused;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Asks the render service (deploy/content-render, headless Chromium) for one card.
 *
 * ⛔ The service is DEFINED, NOT STARTED: `content.render.url` is null, and this refuses. When it runs,
 * the service answers with the image and two headers this checks: `X-Render-Engine: chromium`, and
 * `X-Text-Sha256`, the hash of the text it found in the rendered page's DOM. A mismatch means the words
 * that reached the shaping engine are not the approved words, and the card is refused.
 *
 * What this proves, and what it does not: that the exact approved text was laid out by a browser with
 * a Bengali font. It does not look at the pixels. The pixel check of the two words that broke on 12
 * July is the service's own self-test (`selftest.mjs`), run by a person at gate P6-G2.
 */
final class BrowserCardRenderer
{
    /** @return array{bytes: string, mime: string, text_sha256: string, engine: string} */
    public function render(CardRenderRequest $request): array
    {
        $url = config('content.render.url');
        if (! is_string($url) || $url === '') {
            throw new ResearchRefused('The Bangla render service is defined (deploy/content-render) but not started; nothing was rendered.');
        }

        try {
            $response = Http::timeout((int) config('content.render.timeout_seconds', 30))
                ->post(rtrim($url, '/').'/render', $request->toPayload());
        } catch (ConnectionException $e) {
            throw new ResearchRefused('The render service did not answer: '.$e->getMessage());
        }

        if (! $response->successful()) {
            throw new ResearchRefused("The render service answered HTTP {$response->status()}.");
        }
        if ($response->header('X-Render-Engine') !== CardRenderRequest::ENGINE) {
            throw new ResearchRefused('The card was not rendered by a browser engine; a renderer without shaping draws Bangla wrong.');
        }
        if (! hash_equals($request->textHash(), (string) $response->header('X-Text-Sha256'))) {
            throw new ResearchRefused('The text the render engine laid out is not the approved text; the card is refused.');
        }

        $mime = $request->format === 'jpeg' ? 'image/jpeg' : 'image/png';
        if (! str_starts_with((string) $response->header('Content-Type'), $mime)) {
            throw new ResearchRefused("The render service returned {$response->header('Content-Type')}, not {$mime}.");
        }

        return ['bytes' => $response->body(), 'mime' => $mime, 'text_sha256' => $request->textHash(), 'engine' => CardRenderRequest::ENGINE];
    }
}
