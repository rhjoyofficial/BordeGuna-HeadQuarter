<?php

namespace App\Decisions;

use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\Brand;
use App\Models\Business;
use App\Models\Conversation;
use App\Models\DecisionRecord;
use App\Models\User;
use App\Support\Authorization\BusinessScope;
use Illuminate\Support\Collection;

/**
 * The reader over `decision_records` (docs/111, ruling docs/103 §7): what people kept, edited and
 * refused of what the agents proposed, grouped so a recurring correction shows as a count rather than
 * as a feeling. Reporting and evaluation only.
 *
 * ⛔ It writes nothing: no row, no event, no audit, no cache. Every query is a SELECT; the result is
 * computed on each read and never stored. It changes no prompt, policy, playbook, grant or gate, and
 * nothing reads its output to do so.
 *
 * ⛔ It ships no customer text. `proposal`, `final`, `delta` and `reason` are read to derive labels and
 * measures, and the page gets the labels, the measures and a reference to the row (record id,
 * context type and id). The page the reference leads to is gated on its own permission.
 *
 * Business scope: `decision_records` has no business column, so each record's business is the one on
 * the context row it names (approval, agent run, conversation). A restricted viewer sees only records
 * that resolve to one of their businesses; a record whose context is gone or carries no business is
 * "unscoped" and counted for a group-wide viewer only.
 */
final class DecisionCorpusReader
{
    public const KIND_TOOL_ARGUMENTS = 'tool_arguments';

    public const KIND_REPLY_TEXT = 'reply_text';

    public const KIND_SHADOW_ANSWER = 'shadow_answer';

    /** The reader's one verdict axis over the two stored vocabularies (docs/95 §5). Reporting only; nothing is rewritten. */
    public const VERDICT_KEPT = 'kept';

    public const VERDICT_EDITED = 'edited';

    public const VERDICT_REJECTED = 'rejected';

    private const EXAMPLES = 3;

    public function __construct(
        private readonly BusinessScope $businessScope,
        private readonly EditClassifier $classifier,
    ) {}

    /**
     * @param  User|null  $viewer  null = the console: group-wide, every link allowed
     * @return array<string, mixed>
     */
    public function read(DecisionCorpusWindow $window, ?User $viewer = null, ?int $businessId = null): array
    {
        $scope = $viewer === null ? null : $this->businessScope->all($viewer);
        $restricted = $scope !== null;
        if ($businessId !== null) {
            // A restricted person narrowing to a business outside their rows gets nothing, not a 403:
            // the business does not exist for them.
            $scope = $scope === null || in_array($businessId, $scope, true) ? [$businessId] : [];
        }

        // `created_at` is timestamptz; a bound is sent as an ISO string WITH its offset, so the session
        // timezone cannot move the window (a zone-less Carbon binding is read in the session zone).
        $query = DecisionRecord::query()->orderBy('id');
        if ($window->since !== null) {
            $query->where('created_at', '>=', $window->since->toIso8601String());
        }
        $query->where('created_at', '<=', $window->until->toIso8601String());
        $records = $query->get();

        $rows = $this->resolve($records);
        $unscoped = $rows->filter(fn (array $r) => $r['business_id'] === null)->count();
        if ($scope !== null) {
            $rows = $rows->filter(fn (array $r) => $r['business_id'] !== null && in_array($r['business_id'], $scope, true))->values();
            $unscoped = 0;
        }

        $can = [
            'approvals' => $viewer?->can('hq.approvals.view') ?? true,
            'agents' => $viewer?->can('hq.agents.view') ?? true,
            'inbox' => $viewer?->can('hq.inbox.view') ?? true,
        ];
        $rows = $rows->map(fn (array $r) => $r + ['example' => $this->reference($r, $can)]);

        $edited = $rows->filter(fn (array $r) => $r['verdict'] === self::VERDICT_EDITED);

        return [
            'window' => $window->toArray(),
            'scope' => [
                'restricted' => $restricted,
                'business_id' => $businessId,
                'businesses' => $this->businessesVisible($scope),
                'unscoped_records' => $unscoped,
            ],
            'totals' => $this->totals($rows),
            'by_agent' => $this->group($rows, fn (array $r) => $r['agent_key'] ?? '(none)', fn (array $r, string $key) => [
                'agent_key' => $key,
                'agent_name' => $r['agent_name'],
                'role_key' => $r['role_key'],
            ]),
            'by_tool' => $this->group($rows, fn (array $r) => $r['tool'], fn (array $r, string $key) => ['tool' => $key]),
            'by_kind' => $this->group($rows, fn (array $r) => $r['kind'], fn (array $r, string $key) => ['kind' => $key]),
            // Keyed by name so the order is readable; the unscoped row ('~') sorts last among equals.
            'by_business' => $this->group($rows, fn (array $r) => ($r['business_slug'] ?? '~').':'.($r['brand_name'] ?? ''), fn (array $r, string $key) => [
                'business_id' => $r['business_id'],
                'business' => $r['business_name'],
                'slug' => $r['business_slug'],
                'brand' => $r['brand_name'],
            ]),
            'categories' => $this->categories($edited),
            'fields' => $this->fields($edited),
            'patterns' => $this->patterns($edited),
            'gaps' => [
                'edits_without_delta' => $edited->filter(fn (array $r) => $r['kind'] !== self::KIND_SHADOW_ANSWER && $r['delta'] === null)->count(),
                'shadow_reviews_edited_without_text' => $edited->filter(fn (array $r) => $r['kind'] === self::KIND_SHADOW_ANSWER)->count(),
                'contexts_missing' => $rows->filter(fn (array $r) => ! $r['context_found'])->count(),
                'edits_with_reason' => $edited->filter(fn (array $r) => $r['has_reason'])->count(),
            ],
            'can' => $can,
        ];
    }

    /**
     * One flat row per record: verdict, kind, tool, business, labels. Three batched lookups, no N+1.
     *
     * @param  Collection<int, DecisionRecord>  $records
     * @return Collection<int, array<string, mixed>>
     */
    private function resolve(Collection $records): Collection
    {
        $byType = $records->groupBy('context_type');

        $approvalIds = $byType->get('approval', collect())->pluck('context_id')->filter(fn ($id) => ctype_digit((string) $id))->map(fn ($id) => (int) $id)->all();
        $approvals = $approvalIds === [] ? collect() : Approval::query()->whereIn('id', $approvalIds)->get(['id', 'business_id', 'brand_id', 'tool_key'])->keyBy('id');

        $runUuids = $byType->get('agent_run', collect())->pluck('context_id')->all();
        $runs = $runUuids === [] ? collect() : AgentRun::query()->whereIn('uuid', $runUuids)->get(['id', 'uuid', 'business_id', 'brand_id'])->keyBy('uuid');

        $threadUuids = $byType->get('conversation', collect())->pluck('context_id')->all();
        $threads = $threadUuids === [] ? collect() : Conversation::query()->whereIn('uuid', $threadUuids)->get(['id', 'uuid', 'business_id', 'brand_id'])->keyBy('uuid');

        $agents = Agent::query()->get(['key', 'name', 'role_key'])->keyBy('key');
        $businessIds = collect([$approvals, $runs, $threads])->flatMap(fn ($c) => $c->pluck('business_id'))->filter()->unique()->values()->all();
        $businesses = $businessIds === [] ? collect() : Business::query()->whereIn('id', $businessIds)->get(['id', 'name', 'slug'])->keyBy('id');
        $brandIds = collect([$approvals, $runs, $threads])->flatMap(fn ($c) => $c->pluck('brand_id'))->filter()->unique()->values()->all();
        $brands = $brandIds === [] ? collect() : Brand::query()->whereIn('id', $brandIds)->get(['id', 'name'])->keyBy('id');

        return $records->map(function (DecisionRecord $record) use ($approvals, $runs, $threads, $agents, $businesses, $brands): array {
            $context = match ($record->context_type) {
                'approval' => $approvals->get((int) $record->context_id),
                'agent_run' => $runs->get($record->context_id),
                'conversation' => $threads->get($record->context_id),
                default => null,
            };
            $kind = match ($record->context_type) {
                'approval' => self::KIND_TOOL_ARGUMENTS,
                'conversation' => self::KIND_REPLY_TEXT,
                'agent_run' => self::KIND_SHADOW_ANSWER,
                default => $record->context_type,
            };
            $tool = match ($kind) {
                self::KIND_TOOL_ARGUMENTS => (string) (data_get($record->outcome, 'tool_key') ?? $context?->tool_key ?? '(unknown tool)'),
                self::KIND_REPLY_TEXT => 'messaging.send_reply',
                self::KIND_SHADOW_ANSWER => '(answer)',
                default => '(unknown tool)',
            };
            $delta = is_array($record->delta) && $record->delta !== [] ? $record->delta : null;
            $verdict = $this->verdict($record, $kind, $delta);
            [$labels, $keys] = $this->labels($kind, $verdict, $delta);
            $agent = $record->agent_key === null ? null : $agents->get($record->agent_key);
            $business = $context?->business_id === null ? null : $businesses->get($context->business_id);
            $brand = $context?->brand_id === null ? null : $brands->get($context->brand_id);

            return [
                'id' => $record->id,
                'created_at' => $record->created_at?->toIso8601String(),
                'context_type' => $record->context_type,
                'context_id' => $record->context_id,
                'context_found' => $context !== null,
                'context_row_id' => $context?->id,
                'agent_key' => $record->agent_key,
                'agent_name' => $agent?->name ?? $record->agent_key,
                'role_key' => $agent?->role_key,
                'kind' => $kind,
                'tool' => $tool,
                'stored_decision' => $record->decision,
                'verdict' => $verdict,
                'delta' => $delta,
                'labels' => $labels,
                'keys' => $keys,
                'has_reason' => is_string($record->reason) && trim($record->reason) !== '',
                'business_id' => $context?->business_id,
                'business_name' => $business?->name,
                'business_slug' => $business?->slug,
                'brand_id' => $context?->brand_id,
                'brand_name' => $brand?->name,
            ];
        });
    }

    /** One axis over `approved|edited|rejected` and the inbox's `send|discard`, from the row itself. */
    private function verdict(DecisionRecord $record, string $kind, ?array $delta): string
    {
        return match ($record->decision) {
            'approved' => self::VERDICT_KEPT,
            'edited' => self::VERDICT_EDITED,
            'rejected', 'discard' => self::VERDICT_REJECTED,
            'send' => (bool) data_get($record->final, 'edited', $delta !== null) ? self::VERDICT_EDITED : self::VERDICT_KEPT,
            default => $delta !== null ? self::VERDICT_EDITED : self::VERDICT_KEPT,
        };
    }

    /** @return array{0: list<string>, 1: list<string>} */
    private function labels(string $kind, string $verdict, ?array $delta): array
    {
        if ($verdict !== self::VERDICT_EDITED) {
            return [[], []];
        }
        if ($kind === self::KIND_SHADOW_ANSWER) {
            return [[EditClassifier::VERDICT_ONLY], []];
        }
        if ($delta === null) {
            return [[EditClassifier::UNCLASSIFIED], []];
        }
        if ($kind === self::KIND_REPLY_TEXT) {
            $from = $delta['from'] ?? null;
            $to = $delta['to'] ?? null;
            $labels = $this->classifier->classifyText(is_string($from) ? $from : null, is_string($to) ? $to : null);

            return [$labels === [] ? [EditClassifier::UNCLASSIFIED] : $labels, []];
        }

        $result = $this->classifier->classifyArguments($delta);

        return [$result['labels'] === [] ? [EditClassifier::UNCLASSIFIED] : $result['labels'], $result['keys']];
    }

    /** A reference, never the text. The link exists only when the viewer may open its page. */
    private function reference(array $row, array $can): array
    {
        $href = match ($row['context_type']) {
            'approval' => $can['approvals'] && $row['context_row_id'] !== null ? '/approvals/'.$row['context_row_id'] : null,
            'agent_run' => $can['agents'] && $row['context_row_id'] !== null ? '/agents/runs/'.$row['context_row_id'] : null,
            'conversation' => $can['inbox'] && $row['context_found'] ? '/inbox/'.$row['context_id'] : null,
            default => null,
        };

        return [
            'id' => $row['id'],
            'context_type' => $row['context_type'],
            'context_id' => $row['context_id'],
            'href' => $href,
            'at' => $row['created_at'],
        ];
    }

    private function totals(Collection $rows): array
    {
        $counts = $this->counts($rows);
        $stored = ['approved' => 0, 'edited' => 0, 'rejected' => 0, 'send' => 0, 'discard' => 0];
        foreach ($rows as $r) {
            $stored[$r['stored_decision']] = ($stored[$r['stored_decision']] ?? 0) + 1;
        }
        ksort($stored);

        return $counts + ['by_stored_decision' => $stored];
    }

    /** @return array{records: int, kept: int, edited: int, rejected: int, edit_rate: float|null, rejection_rate: float|null} */
    private function counts(Collection $rows): array
    {
        $n = $rows->count();
        $kept = $rows->filter(fn (array $r) => $r['verdict'] === self::VERDICT_KEPT)->count();
        $edited = $rows->filter(fn (array $r) => $r['verdict'] === self::VERDICT_EDITED)->count();
        $rejected = $rows->filter(fn (array $r) => $r['verdict'] === self::VERDICT_REJECTED)->count();

        return [
            'records' => $n,
            'kept' => $kept,
            'edited' => $edited,
            'rejected' => $rejected,
            'edit_rate' => $n === 0 ? null : round($edited / $n, 4),
            'rejection_rate' => $n === 0 ? null : round($rejected / $n, 4),
        ];
    }

    /**
     * Rows grouped by a key, counted, sorted by records desc then key asc.
     *
     * @param  callable(array): string  $keyOf
     * @param  callable(array, string): array  $describe
     */
    private function group(Collection $rows, callable $keyOf, callable $describe): array
    {
        $items = [];
        foreach ($rows->groupBy(fn (array $r) => $keyOf($r)) as $key => $group) {
            $items[] = [(string) $key, $describe($group->first(), (string) $key) + $this->counts($group) + ['examples' => $this->examples($group)]];
        }
        usort($items, fn (array $a, array $b) => [$b[1]['records'], $a[0]] <=> [$a[1]['records'], $b[0]]);

        return array_map(fn (array $item) => $item[1], $items);
    }

    private function categories(Collection $edited): array
    {
        $total = $edited->count();
        $out = [];
        foreach (EditClassifier::PRECEDENCE as $category) {
            $matching = $edited->filter(fn (array $r) => in_array($category, $r['labels'], true));
            if ($matching->isEmpty()) {
                continue;
            }
            $out[] = [
                'category' => $category,
                'label' => EditClassifier::LABELS[$category],
                'edits' => $matching->count(),
                'share' => $total === 0 ? null : round($matching->count() / $total, 4),
                'examples' => $this->examples($matching),
            ];
        }
        usort($out, fn (array $a, array $b) => [$b['edits'], $a['category']] <=> [$a['edits'], $b['category']]);

        return $out;
    }

    /** Which argument keys people change, per tool (tool-argument edits only). */
    private function fields(Collection $edited): array
    {
        $out = [];
        foreach ($edited->filter(fn (array $r) => $r['keys'] !== []) as $r) {
            foreach ($r['keys'] as $key) {
                $k = $r['tool'].'|'.$key;
                $out[$k] ??= ['tool' => $r['tool'], 'key' => $key, 'edits' => 0, 'rows' => collect()];
                $out[$k]['edits']++;
                $out[$k]['rows']->push($r);
            }
        }
        $out = array_map(fn (array $f) => ['tool' => $f['tool'], 'key' => $f['key'], 'edits' => $f['edits'], 'examples' => $this->examples($f['rows'])], array_values($out));
        usort($out, fn (array $a, array $b) => [$b['edits'], $a['tool'], $a['key']] <=> [$a['edits'], $b['tool'], $b['key']]);

        return $out;
    }

    /** (agent, tool, primary category) seen at least twice: the recurring edits. */
    private function patterns(Collection $edited): array
    {
        $out = [];
        foreach ($edited->groupBy(fn (array $r) => ($r['agent_key'] ?? '(none)').'|'.$r['tool'].'|'.($r['labels'][0] ?? EditClassifier::UNCLASSIFIED)) as $key => $group) {
            if ($group->count() < 2) {
                continue;
            }
            $first = $group->first();
            $category = $first['labels'][0] ?? EditClassifier::UNCLASSIFIED;
            $out[] = [
                'agent_key' => $first['agent_key'],
                'agent_name' => $first['agent_name'],
                'role_key' => $first['role_key'],
                'tool' => $first['tool'],
                'category' => $category,
                'label' => EditClassifier::LABELS[$category] ?? $category,
                'count' => $group->count(),
                'businesses' => $group->pluck('business_slug')->filter()->unique()->sort()->values()->all(),
                'first_at' => $group->min('created_at'),
                'last_at' => $group->max('created_at'),
                'examples' => $this->examples($group),
            ];
        }
        usort($out, fn (array $a, array $b) => [$b['count'], $a['agent_key'], $a['tool'], $a['category']] <=> [$a['count'], $b['agent_key'], $b['tool'], $b['category']]);

        return $out;
    }

    /** The first few references in record order. */
    private function examples(Collection $rows): array
    {
        return $rows->sortBy('id')->take(self::EXAMPLES)->pluck('example')->values()->all();
    }

    /** @param  list<int>|null  $scope */
    private function businessesVisible(?array $scope): array
    {
        $query = Business::query()->orderBy('name');
        if ($scope !== null) {
            $query->whereIn('id', $scope);
        }

        return $query->get(['id', 'name', 'slug'])->map(fn (Business $b) => ['id' => $b->id, 'name' => $b->name, 'slug' => $b->slug])->all();
    }
}
