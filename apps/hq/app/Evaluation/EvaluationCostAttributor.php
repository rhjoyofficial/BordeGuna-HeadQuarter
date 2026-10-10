<?php

namespace App\Evaluation;

use App\Models\EvaluationResult;
use App\Models\EvaluationRun;
use Illuminate\Support\Facades\DB;

/**
 * What an evaluation run cost, read from the `ai_runs` ledger (docs/106, ruling docs/103 §2).
 *
 * `evaluation_runs.cost_usd` was never written: P3-G2 attempt 5 recorded 0.000000 on both runs while
 * its 96 model calls cost about $0.89 estimated. This class computes it from evidence, never from a
 * guess.
 *
 * ## The chain
 *
 * `AgentRunner` mints one `correlation_id` per agent run, and `ModelRuntime` writes one `ai_runs` row per
 * provider attempt under it: every turn, the closing turn, the reply-script repair, every fallback and
 * retry attempt (`attempt` 2, 3), failed attempts included. A result names that correlation
 * (`evaluation_results.correlation_id`), and for results written before the column existed the
 * correlation is read from the `ai_runs` row the result links to (`ai_run_id`), which is itself evidence.
 *
 * ## Counted once
 *
 * ⭐ The evidence is a SET of `ai_runs` rows: those whose correlation is any result's, plus the rows the
 * results link to directly. Two results reaching the same call count it once, and attributing again
 * recomputes the same set and OVERWRITES the columns; nothing is ever added to a previous figure.
 *
 * ## Two facts, never merged
 *
 * - `cost_estimated_usd`: HQ's own estimate (`ai_runs.estimated_cost_usd`), every call.
 * - `cost_provider_usd`: the provider-reported cost (`ai_runs.actual_cost_usd`) over the calls that carry
 *   one, NULL when none does. ⚠ Today none does: `ModelRuntime::recordUsage` leaves `actual_cost_usd`
 *   NULL until a reconciliation against provider billing exists, so this is NULL, not a copy of the
 *   estimate.
 * - `cost_usd`: per call `COALESCE(actual, estimate)`, the convention `AiBudget`, `CostDashboard` and
 *   `ShadowEvidence` already use, so the evaluation figure and the budget ledger agree.
 *
 * ## Complete or not
 *
 * A result is accounted for when it reaches `ai_runs` evidence, or when its observation says the run
 * made no model call (`model_turns` 0: a thread the core gave to a person, a runtime verdict before
 * the model). Anything else (a run that threw before the subject could read its correlation, a case not
 * replayed, an old result with neither a link nor a turn count) is UNACCOUNTED, and `complete` is false.
 * Nothing fills that hole: no time window, no average.
 *
 * ⛔ Reporting only. It writes the four cost columns and nothing else: no status, rate, count, verdict
 * or autonomy input moves because of it.
 */
class EvaluationCostAttributor
{
    public const METHOD = 'ai_runs.correlation_id.v1';

    /**
     * @return array{cost_usd: string, cost_estimated_usd: string, cost_provider_usd: string|null, cost_attribution: array<string, mixed>}
     */
    public function compute(EvaluationRun $run): array
    {
        $results = EvaluationResult::query()
            ->where('run_id', $run->id)
            ->leftJoin('ai_runs as linked', 'linked.id', '=', 'evaluation_results.ai_run_id')
            ->orderBy('evaluation_results.id')
            ->get([
                'evaluation_results.id',
                'evaluation_results.ai_run_id',
                DB::raw('COALESCE(evaluation_results.correlation_id, linked.correlation_id::text) AS evidence_correlation'),
                DB::raw("evaluation_results.actual->>'model_turns' AS model_turns"),
            ]);

        $correlations = $results->pluck('evidence_correlation')->filter()->unique()->values()->all();
        $linkedIds = $results->pluck('ai_run_id')->filter()->unique()->values()->all();

        // Which correlations actually have rows in the ledger, so a result is "linked" only by evidence.
        $present = $correlations === [] ? [] : DB::table('ai_runs')
            ->whereNotNull('correlation_id')
            ->whereIn(DB::raw('correlation_id::text'), $correlations)
            ->selectRaw('DISTINCT correlation_id::text AS c')
            ->pluck('c')
            ->all();

        $linked = 0;
        $withoutCall = 0;
        $unaccounted = 0;
        foreach ($results as $result) {
            if ($result->ai_run_id !== null || in_array($result->evidence_correlation, $present, true)) {
                $linked++;
            } elseif ($result->model_turns === '0') {
                $withoutCall++;
            } else {
                $unaccounted++;
            }
        }

        $totals = $this->totals($correlations, $linkedIds);

        return [
            'cost_usd' => $totals['best'],
            'cost_estimated_usd' => $totals['estimated'],
            'cost_provider_usd' => $totals['provider_reported'] > 0 ? $totals['provider'] : null,
            'cost_attribution' => [
                'method' => self::METHOD,
                'complete' => $unaccounted === 0,
                'ai_runs' => $totals['rows'],
                'ai_runs_provider_reported' => $totals['provider_reported'],
                'retry_attempts' => $totals['retries'],
                'failed_attempts' => $totals['failed'],
                'correlations' => count($correlations),
                'results' => $results->count(),
                'results_linked' => $linked,
                'results_without_model_call' => $withoutCall,
                'results_unaccounted' => $unaccounted,
                'provider_cost_note' => $totals['provider_reported'] > 0
                    ? 'cost_provider_usd sums only the calls that carry a provider-reported cost'
                    : 'no model call in this run carries a provider-reported cost; cost_provider_usd is null, not the estimate',
                'computed_at' => now()->toIso8601String(),
            ],
        ];
    }

    /**
     * Computes and writes the cost columns, and only those. Running it twice writes the same figures.
     *
     * @return array<string, mixed> what was written
     */
    public function attribute(EvaluationRun $run): array
    {
        $computed = $this->compute($run);

        $run->forceFill($computed)->save();

        return $computed;
    }

    /**
     * One query over the evidence SET: a row reached by its correlation and by a direct link is one row.
     *
     * @param  list<string>  $correlations
     * @param  list<int>  $linkedIds
     * @return array{rows: int, estimated: string, provider: string, provider_reported: int, best: string, retries: int, failed: int}
     */
    private function totals(array $correlations, array $linkedIds): array
    {
        if ($correlations === [] && $linkedIds === []) {
            return ['rows' => 0, 'estimated' => '0.000000', 'provider' => '0.000000', 'provider_reported' => 0, 'best' => '0.000000', 'retries' => 0, 'failed' => 0];
        }

        $row = DB::table('ai_runs')
            ->where(function ($q) use ($correlations, $linkedIds) {
                if ($linkedIds !== []) {
                    $q->orWhereIn('id', $linkedIds);
                }
                if ($correlations !== []) {
                    $q->orWhere(fn ($c) => $c->whereNotNull('correlation_id')->whereIn(DB::raw('correlation_id::text'), $correlations));
                }
            })
            ->selectRaw('COUNT(*) AS rows')
            ->selectRaw('COALESCE(SUM(estimated_cost_usd), 0)::numeric(14,6)::text AS estimated')
            ->selectRaw('COALESCE(SUM(actual_cost_usd), 0)::numeric(14,6)::text AS provider')
            ->selectRaw('COUNT(actual_cost_usd) AS provider_reported')
            ->selectRaw('COALESCE(SUM(COALESCE(actual_cost_usd, estimated_cost_usd, 0)), 0)::numeric(14,6)::text AS best')
            ->selectRaw('COUNT(*) FILTER (WHERE attempt > 1) AS retries')
            ->selectRaw("COUNT(*) FILTER (WHERE status = 'failed') AS failed")
            ->first();

        return [
            'rows' => (int) $row->rows,
            'estimated' => (string) $row->estimated,
            'provider' => (string) $row->provider,
            'provider_reported' => (int) $row->provider_reported,
            'best' => (string) $row->best,
            'retries' => (int) $row->retries,
            'failed' => (int) $row->failed,
        ];
    }
}
