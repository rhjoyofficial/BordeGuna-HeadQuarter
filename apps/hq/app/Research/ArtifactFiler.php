<?php

namespace App\Research;

use App\Briefing\EvidenceKind;
use App\Context\Provenance;
use App\Models\ResearchArtifact;
use App\Models\ResearchSource;
use App\Models\ResearchTask;
use App\Models\User;
use App\Support\Features\Features;
use Illuminate\Support\Facades\DB;

/**
 * Files a research artifact (docs/85 C.5 to C.7, ADR-051 accepted with clarifications, docs/88 A.5).
 *
 * Research output is evidence, not truth, and every claim says which kind it is:
 *
 * - **fact**: stated by a fetched source of THIS task, with the span (a character range and the exact
 *   excerpt) that supports it, checked against the page as it was read. Still third-party, still
 *   untrusted, but citable. A statement may not carry a figure its excerpt does not.
 * - **metric**: a number from a system of record (the ERP, a shop, HQ's own read models) with its
 *   reference. Never from the web.
 * - **inference** and **recommendation**: the engine's own reasoning, labelled so, never quoted as a
 *   source's claim. An inference names the claims it rests on and may not introduce a figure none of
 *   them carries.
 *
 * ⛔ Memory is never a source (ADR-033): a claim that points at memory is refused, not relabelled.
 * ⛔ Nothing a source says becomes an instruction, a scope, a permission or a task: a claim is data.
 */
final class ArtifactFiler
{
    private const KINDS = ['fact', 'metric', 'inference', 'recommendation'];

    private const RECORD_SYSTEMS = ['erp', 'shop', 'hq'];

    public function __construct(private readonly Features $features) {}

    /**
     * @param  list<array<string, mixed>>  $claims
     * @param  list<array{source: string, reason: string}>  $discarded
     */
    public function file(ResearchTask $task, array $claims, array $discarded = [], ?User $by = null): ResearchArtifact
    {
        if (! $this->features->enabled(ResearchService::FLAG)) {
            throw new ResearchRefused('The research engine is off (flag research.engine). No artifact was filed.');
        }
        if (! $task->isOpen()) {
            throw new ResearchRefused('An artifact is filed while its task is open: once a task closes its pages are purged and no span can be checked.');
        }
        if ($claims === []) {
            throw new ResearchRefused('An artifact with no claim says nothing. Close the task as not_enough_sources instead.');
        }

        /** @var array<string, ResearchSource> $sources uuid => source of this task */
        $sources = ResearchSource::query()->where('task_id', $task->getKey())->get()->keyBy('uuid')->all();

        $filed = [];
        $used = [];
        foreach ($claims as $i => $claim) {
            $n = $i + 1;
            if (! is_array($claim)) {
                throw new ResearchRefused("Claim {$n} is not a claim.");
            }

            $id = (string) ($claim['id'] ?? '');
            if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $id) !== 1 || isset($filed[$id])) {
                throw new ResearchRefused("Claim {$n} needs a unique id of lower-case letters, digits, dots, dashes or underscores.");
            }

            $kind = (string) ($claim['kind'] ?? '');
            if (! in_array($kind, self::KINDS, true)) {
                throw new ResearchRefused("Claim [{$id}] must say whether it is a fact, a metric, an inference or a recommendation.");
            }

            $statement = trim((string) ($claim['statement'] ?? ''));
            if ($statement === '' || mb_strlen($statement) > 1000) {
                throw new ResearchRefused("Claim [{$id}] needs a statement of at most 1,000 characters.");
            }

            $pointer = strtolower((string) ($claim['source'] ?? '').' '.(string) ($claim['source_type'] ?? ''));
            if (str_contains($pointer, 'memory')) {
                throw new ResearchRefused("Claim [{$id}] cites memory. Memory may suggest a search; it is never a source (ADR-033).");
            }

            $filed[$id] = match ($kind) {
                'fact' => $this->fact($id, $statement, $claim, $sources, $used),
                'metric' => $this->metric($id, $statement, $claim),
                default => $this->reasoning($id, $kind, $statement, $claim, $filed),
            };
        }

        $discardedOut = [];
        foreach ($discarded as $d) {
            $uuid = (string) ($d['source'] ?? '');
            if (! isset($sources[$uuid])) {
                throw new ResearchRefused("A discarded source [{$uuid}] is not one this task fetched.");
            }
            $discardedOut[] = ['source' => $uuid, 'url' => $sources[$uuid]->url, 'reason' => mb_substr((string) ($d['reason'] ?? ''), 0, 500)];
        }

        return DB::transaction(function () use ($task, $filed, $used, $discardedOut, $by): ResearchArtifact {
            ResearchTask::query()->whereKey($task->getKey())->lockForUpdate()->first();
            $version = (int) ResearchArtifact::query()->where('task_id', $task->getKey())->max('version') + 1;
            $asOf = now();

            $claims = array_values($filed);
            $sourcesUsed = array_values($used);
            $digest = hash('sha256', (string) json_encode([
                'task' => $task->uuid, 'version' => $version, 'contract' => $task->contract_version,
                'question' => $task->question, 'claims' => $claims, 'sources_used' => $sourcesUsed,
                'as_of' => $asOf->toIso8601String(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return ResearchArtifact::query()->create([
                'task_id' => $task->getKey(),
                'version' => $version,
                'contract_version' => $task->contract_version,
                'question' => $task->question,
                'scope' => $task->scope,
                'sources_used' => $sourcesUsed,
                'sources_discarded' => $discardedOut,
                'claims' => $claims,
                'budget_spent' => [
                    'searches' => (int) $task->searches_used, 'max_searches' => (int) $task->max_searches,
                    'fetches' => (int) $task->fetches_used, 'max_fetches' => (int) $task->max_fetches,
                    'cost_usd' => (string) $task->cost_spent_usd,
                ],
                'as_of' => $asOf,
                'digest' => $digest,
                'filed_by' => $by?->getKey(),
            ]);
        });
    }

    /** @param  array<string, ResearchSource>  $sources */
    private function fact(string $id, string $statement, array $claim, array $sources, array &$used): array
    {
        $uuid = (string) ($claim['source'] ?? '');
        $source = $sources[$uuid] ?? null;
        if ($source === null) {
            throw new ResearchRefused("Fact [{$id}] cites [{$uuid}], which is not a page this task fetched. A fact without its own source is at most an inference.");
        }
        if ($source->body === null) {
            throw new ResearchRefused("Fact [{$id}] cites a page whose text was purged; its span cannot be checked.");
        }

        $start = $claim['span']['start'] ?? null;
        $end = $claim['span']['end'] ?? null;
        $excerpt = (string) ($claim['excerpt'] ?? '');
        if (! is_int($start) || ! is_int($end) || $start < 0 || $end <= $start || $end - $start > 1200 || $excerpt === '') {
            throw new ResearchRefused("Fact [{$id}] needs a span (start before end, at most 1,200 characters) and its excerpt. A claim with no span is not a fact.");
        }
        if (mb_substr($source->body, $start, $end - $start) !== $excerpt) {
            throw new ResearchRefused("Fact [{$id}]'s excerpt is not the text of its source at characters {$start} to {$end}. A quote must be the page's own words.");
        }

        $unsupported = Figures::unsupported($statement, [$excerpt]);
        if ($unsupported !== []) {
            throw new ResearchRefused("Fact [{$id}] states ".implode(', ', $unsupported).', which its excerpt does not. A source-less number is dropped, or labelled an inference over facts that carry it.');
        }

        $value = null;
        if (array_key_exists('value', $claim) && $claim['value'] !== null) {
            $value = Figures::in((string) $claim['value'])[0] ?? null;
            if ($value === null || ! in_array($value, Figures::in($excerpt), true)) {
                throw new ResearchRefused("Fact [{$id}]'s value is not a figure its excerpt carries.");
            }
        }

        $used[$source->uuid] = ['source' => $source->uuid, 'url' => $source->url, 'content_hash' => $source->content_hash, 'retrieved_at' => $source->retrieved_at?->toIso8601String()];

        return [
            'id' => $id,
            'kind' => EvidenceKind::Fact->value,
            'statement' => $statement,
            'provenance' => Provenance::ThirdParty->value,
            'trusted' => false,
            'source' => $source->uuid,
            'url' => $source->url,
            'retrieved_at' => $source->retrieved_at?->toIso8601String(),
            'content_hash' => $source->content_hash,
            'span' => ['start' => $start, 'end' => $end],
            'excerpt' => $excerpt,
            'excerpt_hash' => hash('sha256', $excerpt),
            'value' => $value,
            'unit' => isset($claim['unit']) ? mb_substr((string) $claim['unit'], 0, 24) : null,
        ];
    }

    private function metric(string $id, string $statement, array $claim): array
    {
        $system = (string) ($claim['system'] ?? '');
        $ref = trim((string) ($claim['ref'] ?? ''));
        if (! in_array($system, self::RECORD_SYSTEMS, true) || $ref === '' || isset($claim['source'])) {
            throw new ResearchRefused("Metric [{$id}] must come from a system of record (erp, shop or hq) with its reference. A web page never supplies a metric.");
        }

        $value = Figures::in((string) ($claim['value'] ?? ''))[0] ?? null;
        if ($value === null) {
            throw new ResearchRefused("Metric [{$id}] needs a numeric value.");
        }
        $unsupported = Figures::unsupported($statement, [(string) $value]);
        if ($unsupported !== []) {
            throw new ResearchRefused("Metric [{$id}] states ".implode(', ', $unsupported).', which is not its value.');
        }

        return [
            'id' => $id,
            'kind' => EvidenceKind::Metric->value,
            'statement' => $statement,
            'provenance' => Provenance::Business->value,
            'trusted' => true,
            'system' => $system,
            'ref' => mb_substr($ref, 0, 250),
            'as_of' => isset($claim['as_of']) ? (string) $claim['as_of'] : null,
            'value' => $value,
            'unit' => isset($claim['unit']) ? mb_substr((string) $claim['unit'], 0, 24) : null,
        ];
    }

    /** @param  array<string, array<string, mixed>>  $filed */
    private function reasoning(string $id, string $kind, string $statement, array $claim, array $filed): array
    {
        $basis = array_values(array_filter(array_map('strval', (array) ($claim['based_on'] ?? []))));
        if ($kind === 'inference' && $basis === []) {
            throw new ResearchRefused("Inference [{$id}] names no claim it rests on.");
        }

        $support = [];
        foreach ($basis as $ref) {
            $base = $filed[$ref] ?? null;
            if ($base === null) {
                throw new ResearchRefused("[{$id}] rests on [{$ref}], which is not an earlier claim of this artifact.");
            }
            $support[] = (string) $base['statement'];
            $support[] = (string) ($base['excerpt'] ?? '');
            $support[] = (string) ($base['value'] ?? '');
        }

        $unsupported = Figures::unsupported($statement, $support);
        if ($unsupported !== []) {
            throw new ResearchRefused("[{$id}] introduces ".implode(', ', $unsupported).', which none of the claims it rests on carries. Nothing is computed into a claim.');
        }

        return [
            'id' => $id,
            'kind' => $kind === 'inference' ? EvidenceKind::Inference->value : EvidenceKind::Recommendation->value,
            'statement' => $statement,
            'provenance' => Provenance::Generated->value,
            'trusted' => false,
            'based_on' => $basis,
        ];
    }
}
