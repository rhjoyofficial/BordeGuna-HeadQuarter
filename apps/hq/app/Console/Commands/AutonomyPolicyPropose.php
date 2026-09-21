<?php

namespace App\Console\Commands;

use App\Autonomy\Evidence\EvidencePolicies;
use App\Models\User;
use Illuminate\Console\Command;
use Throwable;

/**
 * Write an autonomy evidence policy version down as PROPOSED (ADR-048).
 *
 * ⛔ A proposal is inert: nothing reads a proposed version when deciding autonomy. Its numbers may be
 * null, which is how an undecided value is written down and shown before anybody decides it.
 * Without `--apply` it only validates and reports; with it the version, an event row and an audit
 * row are written. Nothing here activates anything.
 */
class AutonomyPolicyPropose extends Command
{
    protected $signature = 'hq:autonomy-policy:propose
        {file : a JSON file: name, summary, requirements[], hard_checks[] (numbers may be null)}
        {--by= : email of the HQ user proposing it}
        {--reason= : why, in words}
        {--apply : actually write the proposed version. Without it the command only validates.}';

    protected $description = 'Validate, and with --apply write, a proposed autonomy evidence policy version (never active).';

    public function handle(EvidencePolicies $policies): int
    {
        $path = (string) $this->argument('file');
        $raw = is_file($path) ? file_get_contents($path) : false;
        $definition = $raw === false ? null : json_decode($raw, true);
        if (! is_array($definition)) {
            $this->error("[{$path}] is not a readable JSON object.");

            return self::FAILURE;
        }

        $problems = $policies->definitionProblems($definition);
        foreach ($problems as $problem) {
            $this->line("  - {$problem}");
        }
        if ($problems !== []) {
            $this->error('The definition is not valid; nothing was written.');

            return self::FAILURE;
        }

        // A missing number is the same as a null one: not decided.
        $undecided = 0;
        foreach ($definition['requirements'] ?? [] as $row) {
            foreach (['min_agreement_rate', 'min_sample_count', 'max_evidence_age_days'] as $field) {
                $undecided += ($row[$field] ?? null) === null ? 1 : 0;
            }
        }
        foreach ($definition['hard_checks'] ?? [] as $row) {
            foreach (['min_sample_count', 'max_evidence_age_days'] as $field) {
                $undecided += ($row[$field] ?? null) === null ? 1 : 0;
            }
        }
        $this->line(sprintf('  %d requirement(s), %d hard check(s), %d undecided field(s). A version with an undecided field can be proposed and never activated.',
            count($definition['requirements'] ?? []), count($definition['hard_checks'] ?? []), $undecided));

        $by = null;
        if (($email = $this->option('by')) !== null) {
            $by = User::query()->where('email', $email)->first();
            if ($by === null || ! $by->is_active) {
                $this->error("No enabled HQ user has the email [{$email}].");

                return self::FAILURE;
            }
        }

        if (! $this->option('apply')) {
            $this->info('dry run: nothing was written. Add --apply (with --by and --reason) to write it as proposed.');

            return self::SUCCESS;
        }

        try {
            $policy = $policies->propose($definition, $by, $this->option('reason'));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Version {$policy->version} proposed (not in force). Activating it is a separate act: hq:autonomy-policy:activate {$policy->version}.");

        return self::SUCCESS;
    }
}
