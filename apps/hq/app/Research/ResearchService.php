<?php

namespace App\Research;

use App\Commerce\Exceptions\Unsupported;
use App\Context\ContextBlock;
use App\Context\Provenance;
use App\Models\Business;
use App\Models\ResearchCandidate;
use App\Models\ResearchSource;
use App\Models\ResearchTask;
use App\Models\User;
use App\Research\Providers\BraveSearch;
use App\Research\Providers\TavilySearch;
use App\Support\Features\Features;
use Illuminate\Support\Facades\DB;

/**
 * The research engine's deterministic core (docs/85 C.2 to C.4, ADR-051 accepted 22 September).
 *
 * A research task is an explicit trusted task: a person opens it with a question, a scope, an
 * owner-approved source list and a budget; the task may then search (through the provider the owner
 * chose) and fetch (only what the boundary permits), until its budget or its question is spent.
 *
 * ⛔ **Off.** Every method refuses while the flag `research.engine` has no enabled row, which it has
 * not. No tool that calls this is registered in `config('hq.tools')`, and no agent holds one.
 *
 * ⛔ **Nothing read can change the task.** A fetched page comes back as an untrusted `ThirdParty`
 * context block and as a stored source. Nothing in this class reads a page for instructions, and no
 * method takes a page's words as a scope, a budget, a source list, a new task or a permission: those
 * are fixed when a person opens the task.
 */
final class ResearchService
{
    public const CONTRACT = 'research.task.v1';

    public const FLAG = 'research.engine';

    /** @var array<string, class-string<SearchProvider>> */
    private const PROVIDERS = ['brave' => BraveSearch::class, 'tavily' => TavilySearch::class];

    public function __construct(
        private readonly Features $features,
        private readonly ResearchBoundary $boundary,
        private readonly PageFetcher $fetcher,
    ) {}

    /**
     * @param  array{business_id: int, brand_id?: ?int, question: string, scope?: ?array, approved_sources?: list<string>,
     *               sources_approved_by?: ?int, freshness_days?: ?int, max_searches: int, max_fetches: int, cost_ceiling_usd?: float}  $input
     */
    public function open(array $input, User $requester): ResearchTask
    {
        $this->assertOn();

        $question = trim((string) ($input['question'] ?? ''));
        if ($question === '' || mb_strlen($question) > 2000) {
            throw new ResearchRefused('A research task needs one question of at most 2,000 characters.');
        }

        $business = Business::query()->find((int) ($input['business_id'] ?? 0));
        if ($business === null) {
            throw new ResearchRefused('A research task belongs to one business, and that business does not exist.');
        }

        $maxSearches = (int) ($input['max_searches'] ?? 0);
        $maxFetches = (int) ($input['max_fetches'] ?? 0);
        $capS = (int) config('content.research.max_searches_per_task', 10);
        $capF = (int) config('content.research.max_fetches_per_task', 25);
        if ($maxSearches < 0 || $maxSearches > $capS || $maxFetches < 1 || $maxFetches > $capF) {
            throw new ResearchRefused("A task's budget is at most {$capS} searches and between 1 and {$capF} fetches.");
        }

        $sources = array_values(array_filter(array_map('trim', (array) ($input['approved_sources'] ?? [])), static fn ($s) => $s !== ''));
        foreach ($sources as $entry) {
            $ok = str_starts_with($entry, 'https://') ? ResearchUrl::parse($entry) !== null : preg_match('/^(\*\.)?[a-z0-9.-]+\.[a-z]{2,}$/i', $entry) === 1;
            if (! $ok) {
                throw new ResearchRefused("[{$entry}] is not a source entry: name a host, *.host or an https URL prefix.");
            }
        }

        $approver = isset($input['sources_approved_by']) ? User::query()->find((int) $input['sources_approved_by']) : null;
        if ($sources !== [] && $approver === null) {
            throw new ResearchRefused('An approved source list is approved by a person; name who approved it.');
        }
        if ($sources === [] && $maxSearches === 0) {
            throw new ResearchRefused('A task with no approved sources and no searches could read nothing.');
        }

        return ResearchTask::query()->create([
            'contract_version' => self::CONTRACT,
            'business_id' => $business->getKey(),
            'brand_id' => $input['brand_id'] ?? null,
            'question' => $question,
            'scope' => $input['scope'] ?? null,
            'approved_sources' => $sources,
            'sources_approved_by' => $approver?->getKey(),
            'freshness_days' => $input['freshness_days'] ?? null,
            'max_searches' => $maxSearches,
            'max_fetches' => $maxFetches,
            'cost_ceiling_usd' => (float) ($input['cost_ceiling_usd'] ?? 0),
            'search_provider' => config('content.research.provider'),
            'requester_user_id' => $requester->getKey(),
            'status' => 'open',
        ]);
    }

    /** @return list<ResearchCandidate> */
    public function search(ResearchTask $task, string $query): array
    {
        $this->assertOn();
        $this->assertOpen($task);

        $query = trim($query);
        if ($query === '' || mb_strlen($query) > 400) {
            throw new ResearchRefused('A search query is between 1 and 400 characters.');
        }
        if ($task->searches_used >= $task->max_searches) {
            throw new ResearchRefused("The task's search budget ({$task->max_searches}) is spent.");
        }

        $provider = $this->provider($task);

        // The budget is spent by the attempt, not by the success, so a failing provider cannot be
        // retried without limit.
        $task->increment('searches_used');

        try {
            $results = $provider->search($query, (int) config('content.research.results_per_search', 10), $task->freshness_days);
        } catch (Unsupported) {
            throw new ResearchRefused("No [{$provider->credentialProvider()}] key is open for this call: none is stored, or the gateway did not open it. Nothing was searched.");
        }

        $candidates = [];
        foreach ($results as $result) {
            $url = ResearchUrl::parse($result->url);
            if ($url === null) {
                continue;
            }
            $candidates[] = ResearchCandidate::query()->create([
                'task_id' => $task->getKey(),
                'provider' => $provider->name(),
                'query' => $query,
                'rank' => $result->rank,
                'url' => $url->url,
                'url_key' => $url->key(),
            ]);
        }

        return $candidates;
    }

    /** @return array{source: ResearchSource, block: ContextBlock} */
    public function fetch(ResearchTask $task, string $rawUrl): array
    {
        $this->assertOn();
        $this->assertOpen($task);

        if (($why = $this->boundary->refusal($task, $rawUrl)) !== null) {
            throw new ResearchRefused($why);
        }
        if ($task->fetches_used >= $task->max_fetches) {
            throw new ResearchRefused("The task's fetch budget ({$task->max_fetches}) is spent.");
        }

        $url = ResearchUrl::parse($rawUrl);
        [$via, $candidateId] = $this->boundary->foundVia($task, $url);

        $task->increment('fetches_used');
        $page = $this->fetcher->fetch($url);

        $source = ResearchSource::query()->create([
            'task_id' => $task->getKey(),
            'url' => $page->url,
            'url_key' => $url->key(),
            'canonical_host' => $page->host,
            'title' => $page->title,
            'retrieved_at' => $page->retrievedAt,
            'published_at' => $page->publishedAt,
            'content_hash' => $page->contentHash,
            'bytes' => $page->bytes,
            'found_via' => $via,
            'candidate_id' => $candidateId,
            'provenance' => Provenance::ThirdParty,
            'body' => $page->text,
        ]);

        return [
            'source' => $source,
            'block' => ContextBlock::thirdParty(
                "research:{$task->uuid}/source:{$source->uuid}",
                $page->text,
                ['url' => $page->url, 'content_hash' => $page->contentHash, 'retrieved_at' => $page->retrievedAt->toIso8601String()],
            ),
        ];
    }

    /** Ends a task and, unless storage rights are configured, purges every fetched body. */
    public function close(ResearchTask $task, string $status, ?string $reason = null): ResearchTask
    {
        if (! in_array($status, ['completed', 'not_enough_sources', 'refused'], true)) {
            throw new ResearchRefused("[{$status}] is not a research verdict.");
        }

        DB::transaction(function () use ($task, $status, $reason): void {
            $task->forceFill(['status' => $status, 'verdict_reason' => $reason, 'closed_at' => now()])->save();

            if (! (bool) config('content.research.store_bodies', false)) {
                ResearchSource::query()->where('task_id', $task->getKey())->whereNull('body_purged_at')
                    ->update(['body' => null, 'body_purged_at' => now()]);
            }
        });

        return $task->refresh();
    }

    public function provider(ResearchTask $task): SearchProvider
    {
        $name = $task->search_provider ?? config('content.research.provider');
        $class = is_string($name) ? (self::PROVIDERS[$name] ?? null) : null;
        if ($class === null) {
            throw new ResearchRefused('No search provider is chosen for the research engine (Brave or Tavily is the owner\'s decision, docs/85 I).');
        }

        return app($class);
    }

    private function assertOn(): void
    {
        if (! $this->features->enabled(self::FLAG)) {
            throw new ResearchRefused('The research engine is off (flag research.engine). Nothing was searched, fetched or stored.');
        }
    }

    private function assertOpen(ResearchTask $task): void
    {
        if (! $task->isOpen()) {
            throw new ResearchRefused("The research task is {$task->status}.");
        }
    }
}
