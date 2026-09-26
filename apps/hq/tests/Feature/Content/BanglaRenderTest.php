<?php

use App\Content\Render\BanglaText;
use App\Content\Render\BrowserCardRenderer;
use App\Content\Render\CardRenderRequest;
use App\Research\ResearchRefused;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 | Stream T (docs/85 A.6, C.8): Bangla on a card is laid out by a shaping engine (headless Chromium),
 | never by a library that draws code points in order. The fixture holds the two words GraphicsMagick
 | broke on 12 July 2026. What PHP can prove is here; the pixels are the render service's self-test,
 | which a person runs and looks at at gate P6-G2.
 */

beforeEach(fn () => Http::preventStrayRequests());

function brFixture(): array
{
    return json_decode((string) file_get_contents(base_path('tests/fixtures/content/bangla-shaping.json')), true, flags: JSON_THROW_ON_ERROR);
}

it('knows that the words which broke need a shaping engine, and what their broken form was', function () {
    foreach (brFixture()['cases'] as $case) {
        expect(BanglaText::needsShaping($case['text']))->toBeTrue($case['text'])
            // The broken picture read as text is a different string: comparing the laid-out text with
            // the source is what catches it.
            ->and($case['broken_render_read_as'])->not->toBe($case['text']);
    }
    foreach (brFixture()['controls'] as $control) {
        expect(BanglaText::needsShaping($control['text']))->toBe($control['needs_shaping'], $control['text']);
    }
});

it('builds a request that only a browser engine with a Bengali font can satisfy', function () {
    $request = CardRenderRequest::for('গার্ডেন', 'instagram');
    $payload = $request->toPayload();

    expect($payload['engine'])->toBe('chromium')
        ->and($payload['lang'])->toBe('bn')
        ->and($payload['format'])->toBe('jpeg')
        ->and(in_array($payload['font'], CardRenderRequest::BANGLA_FONTS, true))->toBeTrue()
        ->and($payload['html'])->toContain('lang="bn"')->and($payload['html'])->toContain('গার্ডেন')
        ->and($payload['text_sha256'])->toBe(hash('sha256', 'গার্ডেন'));

    expect(fn () => CardRenderRequest::for('তেল', 'facebook_page', 'Arial'))->toThrow(ResearchRefused::class, 'Bengali script');
});

it('renders the approved text in NFC and escapes it, never interprets it', function () {
    $decomposed = Normalizer::normalize('সরিষার তেল <script>x()</script>', Normalizer::FORM_D);
    $request = CardRenderRequest::for($decomposed, 'facebook_page');

    expect($request->text)->toBe(Normalizer::normalize($decomposed, Normalizer::FORM_C))
        ->and($request->html())->not->toContain('<script>')
        ->and($request->html())->toContain('&lt;script&gt;');
});

it('refuses to render while the render service is not started', function () {
    expect(fn () => app(BrowserCardRenderer::class)->render(CardRenderRequest::for('তেল', 'instagram')))->toThrow(ResearchRefused::class, 'not started');
    Http::assertNothingSent();
});

it('refuses a card not laid out by a browser, or laid out from other words', function () {
    config(['content.render.url' => 'http://content-render:8080']);
    $request = CardRenderRequest::for("সরিষার তেল\n৪৫০ টাকা", 'instagram');

    Http::fake(['content-render:8080/render' => Http::sequence()
        ->push('JPEGBYTES', 200, ['Content-Type' => 'image/jpeg', 'X-Render-Engine' => 'chromium', 'X-Text-Sha256' => $request->textHash()])
        ->push('JPEGBYTES', 200, ['Content-Type' => 'image/jpeg', 'X-Render-Engine' => 'graphicsmagick', 'X-Text-Sha256' => $request->textHash()])
        ->push('JPEGBYTES', 200, ['Content-Type' => 'image/jpeg', 'X-Render-Engine' => 'chromium', 'X-Text-Sha256' => hash('sha256', 'গার্ডনে')]),
    ]);

    $card = app(BrowserCardRenderer::class)->render($request);
    expect($card['mime'])->toBe('image/jpeg')->and($card['engine'])->toBe('chromium')
        // A line break is laid out, not lost: the service folds whitespace the same way.
        ->and($request->textHash())->toBe(hash('sha256', 'সরিষার তেল ৪৫০ টাকা'));
    expect(fn () => app(BrowserCardRenderer::class)->render($request))->toThrow(ResearchRefused::class, 'browser engine')
        ->and(fn () => app(BrowserCardRenderer::class)->render($request))->toThrow(ResearchRefused::class, 'not the approved text');
    Http::assertSent(fn (Request $r) => $r['engine'] === 'chromium' && $r['format'] === 'jpeg');
});
