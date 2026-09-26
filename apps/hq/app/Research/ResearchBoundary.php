<?php

namespace App\Research;

use App\Models\ResearchCandidate;
use App\Models\ResearchTask;

/**
 * What a research task may fetch (docs/85 C.2, ADR-051 point 1): a URL on the task's owner-approved
 * source list, or one of the task's OWN search results. Nothing else, ever.
 *
 * ⛔ There is no open browsing: a page's links, a redirect, a URL quoted in a fetched page, another
 * task's results, a URL in memory: none of them is on either list, so none can be fetched. And no
 * method here, or anywhere, adds to the approved list from inside a task: the list is written when a
 * person opens the task and never again.
 */
final class ResearchBoundary
{
    public function __construct(private readonly AddressGuard $guard) {}

    public function refusal(ResearchTask $task, string $rawUrl): ?string
    {
        if (! $task->isOpen()) {
            return "The research task is {$task->status}; nothing more is fetched for it.";
        }

        $url = ResearchUrl::parse($rawUrl);
        if ($url === null) {
            return "[{$rawUrl}] is not an https URL research may fetch.";
        }

        if (($why = $this->guard->refusal($url->host)) !== null) {
            return $why;
        }

        if ($this->onApprovedList($task, $url)) {
            return null;
        }

        $isOwnResult = ResearchCandidate::query()
            ->where('task_id', $task->getKey())
            ->where('url_key', $url->key())
            ->exists();
        if ($isOwnResult) {
            return null;
        }

        return "[{$url->url}] is neither on this task's approved source list nor among its own search results. "
            .'A page\'s links, a redirect or a URL quoted in a page never widen a task.';
    }

    public function foundVia(ResearchTask $task, ResearchUrl $url): array
    {
        if ($this->onApprovedList($task, $url)) {
            return ['approved_list', null];
        }

        $candidate = ResearchCandidate::query()->where('task_id', $task->getKey())->where('url_key', $url->key())->orderBy('id')->first();

        return ['candidate', $candidate?->getKey()];
    }

    /**
     * An entry is a host (`dls.gov.bd`), a subdomain wildcard (`*.bordeguna.com`) or a URL prefix
     * (`https://example.org/reports/`), which then matches only below that path.
     */
    private function onApprovedList(ResearchTask $task, ResearchUrl $url): bool
    {
        foreach ((array) $task->approved_sources as $entry) {
            $entry = strtolower(trim((string) $entry));
            if ($entry === '') {
                continue;
            }

            if (str_starts_with($entry, 'https://')) {
                $prefix = ResearchUrl::parse($entry);
                if ($prefix !== null && $prefix->host === $url->host && str_starts_with($url->url, $prefix->url)) {
                    return true;
                }

                continue;
            }

            if (str_starts_with($entry, '*.')) {
                if (str_ends_with($url->host, substr($entry, 1))) {
                    return true;
                }

                continue;
            }

            if ($entry === $url->host) {
                return true;
            }
        }

        return false;
    }
}
