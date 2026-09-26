<?php

namespace App\Research;

use App\Platform\Contracts\ResearchSource;

/**
 * A web search provider behind the provider-neutral contract (ADR-051 accepted: "Provider-specific
 * research APIs remain replaceable adapters").
 *
 * The key is never an argument: the adapter asks `CredentialVault` for it, and the vault answers only
 * inside a scope the gateway opened for a declared credential. With no key stored, there is nothing
 * to open, and the adapter refuses.
 */
interface SearchProvider extends ResearchSource
{
    /** `brave` or `tavily`; matches `research_tasks.search_provider` and `content.research.provider`. */
    public function name(): string;

    /** The `integration_credentials.provider` the key is stored under (account_ref `hq`). */
    public function credentialProvider(): string;

    /** @return list<SearchResult> */
    public function search(string $query, int $count, ?int $freshnessDays): array;
}
