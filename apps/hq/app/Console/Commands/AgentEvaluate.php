<?php

namespace App\Console\Commands;

use App\Agents\AgentEvaluationSubject;
use App\Agents\AgentRunner;
use App\Agents\PromptLibrary;
use App\Evaluation\DeterministicGrader;
use App\Evaluation\EvaluationRunner;
use App\Models\EvaluationResult;
use App\Models\EvaluationRun;
use App\Models\EvaluationSuite;
use Illuminate\Console\Command;
use Throwable;

/**
 * Run the required evaluation suites against a real agent, and record the evidence (ADR-023, 035, 036).
 *
 * ⭐ **This is the release gate.** With no `--suite` it runs EVERY suite that applies to the agent's
 * role - the cross-agent safety invariants and the agent's own role-behaviour suite - because
 * passing one layer is not evidence about the other, and a gate that only ran the layer somebody
 * remembered to name would be a gate with a hole in it.
 *
 * ⛔ **One blocker failure fails the whole thing, whatever the rate.** `EvaluationRunner` enforces
 * that per run; this exits non-zero if ANY suite failed, so a pipeline cannot record a red gate as a
 * green step.
 *
 * ⚠ It costs real money: at least one model call per case, across every applicable suite. The
 * agent, the suites and the case count are printed before anything runs.
 */
class AgentEvaluate extends Command
{
    protected $signature = 'hq:agent:evaluate
        {agent : the agent key to evaluate}
        {--suite= : run one named suite instead of every suite that applies to this agent}
        {--triggered-by=manual : what caused this run}';

    protected $description = 'Run the required evaluation suites against an agent and record the result.';

    public function handle(EvaluationRunner $runner, AgentRunner $agents, PromptLibrary $prompts): int
    {
        $agentKey = (string) $this->argument('agent');
        $subject = new AgentEvaluationSubject($agents, $agentKey);
        $role = $subject->subjectRole();

        if ($role === null) {
            $this->error("Agent [{$agentKey}] does not exist, or declares no role. A suite is only evidence about an agent whose role makes its expectations meaningful.");

            return self::FAILURE;
        }

        $suites = $this->suitesFor($role);

        if ($suites->isEmpty()) {
            // A gate that matched no suite must never read as a pass.
            $this->error("No active evaluation suite applies to role [{$role}]. Nothing was measured, so nothing passed.");

            return self::FAILURE;
        }

        $this->line("Evaluating agent <comment>{$agentKey}</comment> (role <comment>{$role}</comment>) against "
            .$suites->count().' suite(s): '.$suites->pluck('key')->implode(', '));
        $this->line('Each case is at least one model call, so this costs real money.');
        $this->newLine();

        $runs = [];
        $failed = false;

        foreach ($suites as $suite) {
            try {
                $run = $runner->withGrader(new DeterministicGrader)->run($suite, $subject, [
                    'agent_key' => $agentKey,
                    'triggered_by' => (string) $this->option('triggered-by'),
                    // The stamp that says which prompt this evidence is about. The first two gate
                    // runs carried none, which is the attribution gap PromptLibrary exists to close.
                    'prompt_version' => $prompts->has($role) ? $prompts->version($role) : null,
                ]);
            } catch (Throwable $e) {
                $this->error("[{$suite->key}] could not be run: ".$e->getMessage());
                $failed = true;

                continue;
            }

            $runs[] = $run;
            $failed = $failed || $run->status !== 'passed';
            $this->report($suite->key, $run);
        }

        $this->newLine();
        $this->line($failed
            ? '<bg=red;fg=white> GATE FAILED </> Autonomy does not widen, and no shadow period begins on this.'
            : '<bg=green;fg=black> GATE PASSED </> Every required blocker case held.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The suites that apply to a role: the cross-agent ones, plus the role's own.
     *
     * ⛔ Never another role's suite. Passing the support suite says nothing about the executive
     * agent, and counting it would be the same category error that produced the first failed gate.
     */
    private function suitesFor(string $role)
    {
        if (($named = $this->option('suite')) !== null) {
            return EvaluationSuite::where('key', $named)->get();
        }

        return EvaluationSuite::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('role_key')->orWhere('role_key', $role))
            ->orderBy('key')
            ->get();
    }

    private function report(string $key, EvaluationRun $run): void
    {
        $verdict = $run->status === 'passed' ? '<info>passed</info>' : '<error>failed</error>';

        $this->line("<comment>{$key}</comment>  {$verdict}  "
            ."graded {$run->cases_total}, skipped {$run->cases_skipped} (another role), "
            ."passed {$run->cases_passed}, failed {$run->cases_failed}, agreement {$run->agreement_rate}"
            .($run->cases_unmeasured > 0 ? ", <error>unmeasured {$run->cases_unmeasured}</error> (provider refused; not evidence, never green)" : ''));

        // ⭐ Per check and per dimension, because one number averages things that fail for different
        // reasons. "must_not_call 12/12 while the suite scored 0.333" is the finding a single figure
        // cannot express (docs/61).
        foreach ((array) $run->check_rates as $check => $r) {
            $this->line(sprintf('    check  %-16s %d passed, %d failed', $check, $r['passed'] ?? 0, $r['failed'] ?? 0));
        }
        foreach ((array) $run->dimension_rates as $dimension => $r) {
            $this->line(sprintf('    dim    %-16s %d passed, %d failed  (rate %s)', $dimension, $r['passed'] ?? 0, $r['failed'] ?? 0, $r['rate'] ?? '-'));
        }
        // ⭐ Per family (ADR-040): authority safety and task integrity answered separately, because
        // gate 5 answered them differently and one number would have hidden which one moved.
        foreach ((array) $run->family_rates as $family => $r) {
            $this->line(sprintf('    family %-24s %d passed, %d failed  (rate %s)', $family, $r['passed'] ?? 0, $r['failed'] ?? 0, $r['rate'] ?? '-'));
        }

        $failures = EvaluationResult::with('evaluationCase')
            ->where('run_id', $run->id)->where('passed', false)->get();

        if ($failures->isNotEmpty()) {
            $this->table(
                ['case', 'dimension', 'why'],
                $failures->map(fn ($r) => [
                    $r->evaluationCase?->key ?? $r->case_id,
                    $r->unmeasured ? 'UNMEASURED' : $r->dimension,
                    substr((string) $r->reason, 0, 80),
                ])->all(),
            );
        }

        if ($run->regressions !== null) {
            $this->error('  Regressions against the previous run: '.implode(', ', $run->regressions));
        }

        $this->newLine();
    }
}
