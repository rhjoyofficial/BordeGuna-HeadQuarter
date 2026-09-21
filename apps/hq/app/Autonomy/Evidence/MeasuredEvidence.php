<?php

namespace App\Autonomy\Evidence;

use App\Evaluation\GradeResult;
use App\Models\EvaluationResult;
use App\Models\EvaluationRun;
use App\Models\EvaluationSuite;
use Illuminate\Support\Collection;

/**
 * One agent's evaluation evidence as it stands, counted from rows and never from a stored rate
 * (ADR-048, docs/80 C.3).
 *
 * Built by `EvidenceProducer::measure()` only. It is data plus arithmetic: every figure it returns
 * carries the run ids and result ids it was counted from, and the figures a run stored are kept
 * BESIDE the recount so a difference is visible rather than trusted.
 *
 * The rules it holds, each the same as the autonomy evidence view's:
 *
 * - Evidence is the latest FINISHED run of each applicable suite. History is never read here.
 * - A result the model provider refused is unmeasured: neither half of a rate (ADR-041).
 * - A result whose case belongs to another role is not in `$results` at all (ADR-036).
 * - `overall` is its own row; a dimension never borrows it (ADR-030).
 */
final class MeasuredEvidence
{
    public const OVERALL = 'overall';

    /**
     * @param  Collection<int, EvaluationSuite>  $suites  the applicable suites, by key
     * @param  array<int, EvaluationRun>  $latest  suite id => its latest finished run for this agent
     * @param  Collection<int, EvaluationResult>  $results  every result of those runs this role may count
     * @param  array<int, array<string, int>>  $declared  suite id => dimension => active applicable cases
     * @param  bool  $overCeiling  more result rows than the producer will read: nothing may be counted
     */
    public function __construct(
        public readonly string $agentKey,
        public readonly string $role,
        public readonly ?string $stamp,
        public readonly Collection $suites,
        public readonly array $latest,
        public readonly Collection $results,
        public readonly array $declared,
        public readonly bool $overCeiling = false,
    ) {}

    /**
     * What each latest run contributed to one row (`overall` or a dimension), with the result ids
     * behind each count. A run holding no result in the row contributes nothing and is not listed.
     *
     * @return list<array<string, mixed>>
     */
    public function sources(string $dimension): array
    {
        $mine = $dimension === self::OVERALL ? $this->results : $this->results->where('dimension', $dimension)->values();

        $sources = [];
        foreach ($this->suites as $suite) {
            $run = $this->latest[$suite->id] ?? null;
            if ($run === null) {
                continue;
            }
            $ofRun = $mine->where('run_id', $run->id)->values();
            if ($ofRun->isEmpty()) {
                continue;
            }
            $sources[] = $this->source($dimension, $run, $suite, $ofRun);
        }

        return $sources;
    }

    /**
     * One grader check (a key of `DeterministicGrader::CHECKS`) recounted from `evaluation_results.checks`
     * per latest run: the measured results where it passed and where it failed. `not_applicable` and
     * unmeasured results are neither. Never read from `evaluation_runs.check_rates`.
     *
     * @return list<array<string, mixed>>
     */
    public function checkSources(string $check): array
    {
        $out = [];
        foreach ($this->suites as $suite) {
            $run = $this->latest[$suite->id] ?? null;
            if ($run === null) {
                continue;
            }
            $measured = $this->results->where('run_id', $run->id)->where('unmeasured', false);
            $pass = $measured->filter(fn (EvaluationResult $r) => (((array) $r->checks)[$check] ?? null) === GradeResult::PASS);
            $fail = $measured->filter(fn (EvaluationResult $r) => (((array) $r->checks)[$check] ?? null) === GradeResult::FAIL);
            if ($pass->isEmpty() && $fail->isEmpty()) {
                continue;
            }
            $out[] = self::runLineage($run, $suite) + [
                'check' => $check,
                'passed' => $pass->count(),
                'failed' => $fail->count(),
                'result_ids' => ['passed' => $this->ids($pass), 'failed' => $this->ids($fail)],
            ];
        }

        return $out;
    }

    /** Active applicable cases this suite declares in the row (`overall` = every dimension). */
    public function declaredIn(EvaluationSuite $suite, string $dimension): int
    {
        return $dimension === self::OVERALL
            ? array_sum($this->declared[$suite->id] ?? [])
            : ($this->declared[$suite->id][$dimension] ?? 0);
    }

    /** @return list<int> */
    public function runIds(): array
    {
        $ids = array_map(fn (EvaluationRun $r) => (int) $r->id, array_values($this->latest));
        sort($ids);

        return $ids;
    }

    /**
     * sha256 over exactly what was counted: the agent, the stamp it runs with, and every counted
     * result (id, run, case, passed, unmeasured, dimension, checks). The same rows give the same
     * digest; one changed row gives another.
     */
    public function digest(): string
    {
        $rows = $this->results->map(fn (EvaluationResult $r) => [
            (int) $r->id, (int) $r->run_id, (int) $r->case_id, (bool) $r->passed, (bool) $r->unmeasured,
            (string) $r->dimension, $this->sortedChecks((array) $r->checks),
        ])->sortBy(fn (array $row) => $row[0])->values()->all();

        return hash('sha256', (string) json_encode([
            'agent' => $this->agentKey, 'role' => $this->role, 'stamp' => $this->stamp,
            'runs' => $this->runIds(), 'over_ceiling' => $this->overCeiling, 'results' => $rows,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @return array<string, mixed> */
    public static function runLineage(EvaluationRun $run, EvaluationSuite $suite): array
    {
        return [
            'run_id' => (int) $run->id,
            'run_uuid' => $run->uuid,
            'suite_key' => $suite->key,
            'role_scope' => $suite->role_key,
            'prompt_version' => $run->prompt_version,
            'model' => $run->model,
            'run_status' => $run->status,
            'run_cases_total' => (int) $run->cases_total,
            'run_cases_passed' => (int) $run->cases_passed,
            'run_cases_failed' => (int) $run->cases_failed,
            'run_cases_skipped' => (int) $run->cases_skipped,
            'run_cases_unmeasured' => (int) $run->cases_unmeasured,
            'run_agreement_rate' => $run->agreement_rate,
            'triggered_by' => $run->triggered_by,
            'finished_at' => $run->finished_at?->toIso8601String(),
        ];
    }

    public static function rate(int $passed, int $failed): ?float
    {
        return $passed + $failed === 0 ? null : round($passed / ($passed + $failed), 3);
    }

    /** Floored, never rounded, to whole thousandths: 0.9996 is 0.999, so it never reads as 1.000. */
    public static function flooredRate(int $passed, int $failed): ?float
    {
        return $passed + $failed === 0 ? null : intdiv($passed * 1000, $passed + $failed) / 1000;
    }

    /** Whole thousandths on both sides: no float comparison, no rounding up into a pass. */
    public static function meets(int $passed, int $failed, float $required): bool
    {
        return $passed * 1000 >= (int) round($required * 1000) * ($passed + $failed);
    }

    /**
     * @param  Collection<int, EvaluationResult>  $results
     * @return array<string, mixed>
     */
    private function source(string $dimension, EvaluationRun $run, EvaluationSuite $suite, Collection $results): array
    {
        $unmeasured = $results->where('unmeasured', true);
        $measured = $results->where('unmeasured', false);
        $passed = $measured->where('passed', true);
        $failed = $measured->where('passed', false);

        // The run's own stored figures, kept beside the recount so a difference is visible.
        $stored = $dimension === self::OVERALL
            ? ['passed' => (int) $run->cases_passed, 'failed' => (int) $run->cases_failed, 'rate' => $run->agreement_rate]
            : (is_array($run->dimension_rates[$dimension] ?? null) ? [
                'passed' => (int) ($run->dimension_rates[$dimension]['passed'] ?? 0),
                'failed' => (int) ($run->dimension_rates[$dimension]['failed'] ?? 0),
                // JSON gives back 1 for 1.0; a rate is a float wherever it is shown.
                'rate' => is_numeric($run->dimension_rates[$dimension]['rate'] ?? null) ? (float) $run->dimension_rates[$dimension]['rate'] : null,
            ] : null);

        return self::runLineage($run, $suite) + [
            'passed' => $passed->count(),
            'failed' => $failed->count(),
            'unmeasured' => $unmeasured->count(),
            'agreement_rate' => self::rate($passed->count(), $failed->count()),
            'result_ids' => ['passed' => $this->ids($passed), 'failed' => $this->ids($failed), 'unmeasured' => $this->ids($unmeasured)],
            'stored' => $stored,
            'stored_matches' => $stored === null
                ? $measured->isEmpty()
                : $stored['passed'] === $passed->count() && $stored['failed'] === $failed->count(),
        ];
    }

    /**
     * @param  Collection<int, EvaluationResult>  $rows
     * @return list<int>
     */
    private function ids(Collection $rows): array
    {
        return $rows->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
    }

    /** @return array<string, string> */
    private function sortedChecks(array $checks): array
    {
        ksort($checks);

        return $checks;
    }
}
