<?php

namespace App\Research\Providers;

use App\Gateway\Credentials\CredentialVault;
use App\Platform\Capabilities;
use App\Platform\Contracts\ResearchSource;
use App\Research\ResearchRefused;
use App\Research\SearchProvider;
use App\Research\SearchResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Tavily search (docs/85 F O-11).
 *
 * The request, as Tavily's API reference states it (read 22 September 2026,
 * docs.tavily.com/documentation/api-reference/endpoint/search): `POST https://api.tavily.com/search`,
 * `Authorization: Bearer <key>`, a JSON body with `query`, `max_results`, `search_depth` (`basic` here:
 * one credit) and `time_range` (`day`, `week`, `month`, `year`). Results are `results[]` with `title`,
 * `url`, `content`, `score` and, when Tavily can estimate it, `published_date`. The `content` excerpt
 * is not kept: only the URL and the rank are.
 */
final class TavilySearch implements SearchProvider
{
    public const CREDENTIAL = 'tavily_api_key';

    public function __construct(private readonly CredentialVault $vault) {}

    public function name(): string
    {
        return 'tavily';
    }

    public function credentialProvider(): string
    {
        return self::CREDENTIAL;
    }

    public function platform(): string
    {
        return 'tavily';
    }

    public function ceiling(): Capabilities
    {
        return Capabilities::of([ResearchSource::SEARCH]);
    }

    public function requiresApproval(): array
    {
        return [];
    }

    public function search(string $query, int $count, ?int $freshnessDays): array
    {
        $key = $this->vault->secret(self::CREDENTIAL, 'hq');

        $body = ['query' => $query, 'max_results' => max(1, min(20, $count)), 'search_depth' => 'basic'];
        if ($freshnessDays !== null) {
            $body['time_range'] = match (true) {
                $freshnessDays <= 1 => 'day',
                $freshnessDays <= 7 => 'week',
                $freshnessDays <= 31 => 'month',
                default => 'year',
            };
        }

        try {
            $response = Http::baseUrl((string) config('content.research.providers.tavily.base_url'))
                ->timeout((int) config('content.research.search_timeout_seconds', 15))
                ->withToken($key)
                ->acceptJson()
                ->post('/search', $body);
        } catch (ConnectionException $e) {
            throw new ResearchRefused('Tavily search did not answer: '.$e->getMessage());
        }

        if (! $response->successful()) {
            throw new ResearchRefused("Tavily search answered HTTP {$response->status()}; no result was taken.");
        }

        $results = [];
        foreach ((array) $response->json('results', []) as $row) {
            if (! is_array($row) || ! is_string($row['url'] ?? null)) {
                continue;
            }
            $results[] = new SearchResult(
                url: $row['url'],
                title: (string) ($row['title'] ?? ''),
                rank: count($results) + 1,
                provider: $this->name(),
                publishedAt: is_string($row['published_date'] ?? null) ? $row['published_date'] : null,
            );
        }

        return $results;
    }
}
