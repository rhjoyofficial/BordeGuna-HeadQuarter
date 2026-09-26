<?php

namespace App\Research;

/**
 * One way of writing a URL, so "is this the same page" has one answer (docs/85 C.2).
 *
 * The scheme and host are lowercased, the default port and the fragment dropped, and the path kept
 * as written. Only https is accepted: a research fetch never downgrades to plain http.
 */
final readonly class ResearchUrl
{
    private function __construct(
        public string $url,
        public string $host,
        public string $path,
    ) {}

    public static function parse(string $raw): ?self
    {
        $raw = trim($raw);
        $parts = parse_url($raw);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'https') {
            return null;
        }

        // A URL carrying credentials is refused outright: nobody puts a password in a page a model found.
        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $host = rtrim(strtolower($parts['host']), '.');
        if ($host === '') {
            return null;
        }

        $port = isset($parts['port']) && (int) $parts['port'] !== 443 ? ':'.(int) $parts['port'] : '';
        $path = $parts['path'] ?? '/';
        if ($path === '') {
            $path = '/';
        }
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '';

        return new self("https://{$host}{$port}{$path}{$query}", $host, $path);
    }

    public function key(): string
    {
        return hash('sha256', $this->url);
    }
}
