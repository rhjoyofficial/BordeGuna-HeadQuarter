<?php

namespace App\Console\Commands;

use App\Agents\AgentEvaluationSubject;
use App\Agents\AgentRunner;
use App\Evaluation\DeterministicGrader;
use App\Evaluation\EvaluationRunner;
use App\Models\EvaluationResult;
use App\Models\EvaluationSuite;
use Database\Seeders\SafetyCorpusSeeder;
use Illuminate\Console\Command;

/**
 * Run an evaluation suite against a real agent, and record the evidence (ADR-023).
 *
 * ⭐ **This is the M5 safety gate.** The twelve blocker cases seeded in Phase 2.5 - injection,
 * credential extraction, cross-business access, SQL and policy bypass - have never been run against
 * anything, because until now there was nothing to run them against.
 *
 * ⛔ **One blocker failure fails the whole run, whatever the rate.** `EvaluationRunner` enforces
 * that; this command exits non-zero on it so a pipeline cannot record a red run as a green step.
 *
 * ⚠ It costs real money: one model call per case, at least. The count and the agent are printed
 * before anything runs.
 */
class AgentEvaluate extends Command
{
    protected $signature = 'hq:agent:evaluate
        {agent : the agent key to evaluate}
        {--suite= : the suite key (default: the core safety corpus)}
        {--triggered-by=manual : what caused this run}';

    protected $description = 'Run an evaluation suite against an agent and record the result.';

    public function handle(EvaluationRunner $runner, AgentRunner $agents): int
    {
        $agentKey = (string) $this->argument('agent');
        $suiteKey = (string) ($this->option('suite') ?: SafetyCorpusSeeder::SUITE_KEY);

        $suite = EvaluationSuite::where('key', $suiteKey)->first();

        if ($suite === null) {
            $this->error("There is no evaluation suite [{$suiteKey}]. Seed it first.");

            return self::FAILURE;
        }

        $cases = $suite->cases()->where('is_active', true)->count();

        if ($cases === 0) {
            // A suite with no cases would otherwise "pass", which is the most misleading possible
            // outcome for a safety gate.
            $this->error("Suite [{$suiteKey}] has no active cases. A suite that tests nothing cannot pass.");

            return self::FAILURE;
        }

        $this->line("Running <comment>{$suiteKey}</comment> ({$cases} cases) against agent <comment>{$agentKey}</comment>.");
        $this->line('Each case is at least one model call, so this costs real money.');
        $this->newLine();

        $run = $runner
            ->withGrader(new DeterministicGrader)
            ->run(
                $suite,
                new AgentEvaluationSubject($agents, $agentKey),
                ['agent_key' => $agentKey, 'triggered_by' => (string) $this->option('triggered-by')],
            );

        $this->line("<comment>Run</comment> {$run->uuid}");
        $this->line("<comment>Status</comment> {$run->status}   <comment>passed</comment> {$run->cases_passed}"
            ."   <comment>failed</comment> {$run->cases_failed}   <comment>agreement</comment> {$run->agreement_rate}");

        $failures = EvaluationResult::with('evaluationCase')
            ->where('run_id', $run->id)
            ->where('passed', false)
            ->get();

        if ($failures->isNotEmpty()) {
            $this->newLine();
            $this->line('<comment>Failures</comment>');
            $this->table(
                ['case', 'severity', 'why'],
                $failures->map(fn ($r) => [
                    $r->evaluationCase?->key ?? $r->case_id,
                    $r->evaluationCase?->severity ?? '-',
                    substr((string) $r->reason, 0, 90),
                ])->all(),
            );
        }

        if ($run->regressions !== null) {
            $this->newLine();
            $this->error('Regressions against the previous run: '.implode(', ', $run->regressions));
        }

        return $run->status === 'passed' ? self::SUCCESS : self::FAILURE;
    }
}
