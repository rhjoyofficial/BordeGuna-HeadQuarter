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
 * Brave Web Search (docs/85 F O-11).
 *
 * The request, as Brave's own documentation states it (read 22 September 2026,
 * api-dashboard.search.brave.com/app/documentation/web-search/get-started): `GET
 * https://api.search.brave.com/res/v1/web/search`, the key in the `X-Subscription-Token` header, `q`
 * the query, `count` at most 20, `freshness` one of `pd`, `pw`, `pm`, `py` or a range
 * `YYYY-MM-DDtoYYYY-MM-DD`. Results are `web.results[]` with `url`, `title` and `description`.
 * ⚠ UNVERIFIED on that page: a per-result age field (`page_age`); it is read when present and never
 * relied on.
 *
 * ⚠ Brave's terms (read 21 September, brave.com/search/api): storing results "in part or whole" needs
 * a plan that grants storage rights. HQ keeps only the URL and the rank, for the length of the task.
 */
final class BraveSearch implements SearchProvider
{
    public const CREDENTIAL = 'brave_search_api_key';

    public function __construct(private readonly CredentialVault $vault) {}

    public function name(): string
    {
        return 'brave';
    }

    public function credentialProvider(): string
    {
        return self::CREDENTIAL;
    }

    public function platform(): string
    {
        return 'brave_search';
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

        $params = ['q' => $query, 'count' => max(1, min(20, $count))];
        if ($freshnessDays !== null) {
            $params['freshness'] = self::freshness($freshnessDays);
        }

        try {
            $response = Http::baseUrl((string) config('content.research.providers.brave.base_url'))
                ->timeout((int) config('content.research.search_timeout_seconds', 15))
                ->withHeaders(['X-Subscription-Token' => $key, 'Accept' => 'application/json'])
                ->get('/web/search', $params);
        } catch (ConnectionException $e) {
            throw new ResearchRefused('Brave search did not answer: '.$e->getMessage());
        }

        if (! $response->successful()) {
            throw new ResearchRefused("Brave search answered HTTP {$response->status()}; no result was taken.");
        }

        $results = [];
        foreach ((array) $response->json('web.results', []) as $i => $row) {
            if (! is_array($row) || ! is_string($row['url'] ?? null)) {
                continue;
            }
            $results[] = new SearchResult(
                url: $row['url'],
                title: (string) ($row['title'] ?? ''),
                rank: count($results) + 1,
                provider: $this->name(),
                publishedAt: is_string($row['page_age'] ?? null) ? $row['page_age'] : null,
            );
        }

        return $results;
    }

    /** Days to Brave's freshness value: the nearest documented window that is not narrower. */
    public static function freshness(int $days): string
    {
        return match (true) {
            $days <= 1 => 'pd',
            $days <= 7 => 'pw',
            $days <= 31 => 'pm',
            $days <= 365 => 'py',
            default => now()->subDays($days)->toDateString().'to'.now()->toDateString(),
        };
    }
}
