<?php

namespace App\Research;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Normalizer;
use Throwable;

/**
 * Reads one permitted page as text (docs/85 C.4). It is not a browser.
 *
 * ⛔ **Redirects are not followed.** A redirect is a page choosing where HQ goes next, which is exactly
 * what a research task may not let a page do; the target is reported, and fetching it is a new
 * request the boundary judges on its own. The size is capped, only HTML and plain text are read,
 * scripts and styles are dropped, and nothing on the page is executed or followed.
 */
final class PageFetcher
{
    public function fetch(ResearchUrl $url): FetchedPage
    {
        try {
            $response = Http::withOptions(['allow_redirects' => false])
                ->timeout((int) config('content.research.fetch_timeout_seconds', 15))
                ->withHeaders(['User-Agent' => (string) config('content.research.user_agent'), 'Accept' => 'text/html, text/plain'])
                ->get($url->url);
        } catch (ConnectionException $e) {
            throw new ResearchRefused("[{$url->url}] did not answer: ".$e->getMessage());
        }

        if ($response->redirect()) {
            $to = (string) $response->header('Location');

            throw new ResearchRefused("[{$url->url}] redirects to [{$to}]. A redirect is not followed; that address must itself be permitted and fetched on its own.");
        }

        if (! $response->successful()) {
            throw new ResearchRefused("[{$url->url}] answered HTTP {$response->status()}; nothing was read.");
        }

        $type = strtolower((string) $response->header('Content-Type'));
        $isHtml = str_contains($type, 'text/html') || str_contains($type, 'application/xhtml');
        if (! $isHtml && ! str_contains($type, 'text/plain')) {
            throw new ResearchRefused("[{$url->url}] is [{$type}]; research reads HTML and plain text only.");
        }

        $raw = $response->body();
        $max = (int) config('content.research.max_page_bytes', 2_000_000);
        if (strlen($raw) > $max) {
            throw new ResearchRefused("[{$url->url}] is larger than {$max} bytes; it was not read.");
        }

        [$title, $text] = $isHtml ? self::htmlToText($raw) : [null, self::normalise($raw)];

        return new FetchedPage(
            url: $url->url,
            host: $url->host,
            title: $title,
            text: $text,
            contentHash: hash('sha256', $text),
            bytes: strlen($raw),
            retrievedAt: CarbonImmutable::now(),
            publishedAt: self::publishedAt($raw),
        );
    }

    /** @return array{0: ?string, 1: string} */
    public static function htmlToText(string $html): array
    {
        $title = null;
        if (preg_match('/<title[^>]*>(.*?)<\/title>/isu', $html, $m) === 1) {
            $title = mb_substr(self::normalise(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')), 0, 250);
        }

        $body = preg_replace('/<(script|style|noscript|template|svg|head)\b[^>]*>.*?<\/\1>/isu', ' ', $html) ?? '';
        $body = preg_replace('/<!--.*?-->/su', ' ', $body) ?? '';
        $body = preg_replace('/<(br|p|div|li|h[1-6]|tr|section|article)\b[^>]*>/iu', "\n", $body) ?? '';
        $text = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return [$title === '' ? null : $title, self::normalise($text)];
    }

    /** NFC first (a span is counted in characters of THIS text), then whitespace folded. */
    public static function normalise(string $text): string
    {
        if (class_exists(Normalizer::class)) {
            $text = Normalizer::normalize($text, Normalizer::FORM_C) ?: $text;
        }
        $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s*\n\s*/u', "\n", $text) ?? $text;

        return trim($text);
    }

    private static function publishedAt(string $html): ?CarbonImmutable
    {
        if (preg_match('/<meta[^>]+property=["\']article:published_time["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::parse($m[1]);
        } catch (Throwable) {
            return null;
        }
    }
}
