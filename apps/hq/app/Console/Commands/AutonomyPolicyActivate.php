<?php

namespace App\Console\Commands;

use App\Autonomy\Evidence\EvidencePolicies;
use App\Models\AutonomyEvidencePolicy;
use App\Models\User;
use Illuminate\Console\Command;
use Throwable;

/**
 * The one audited activation of an autonomy evidence policy version (ADR-048).
 *
 * ⛔ Without `--apply` it only reads: every reason the version cannot become active, for this person,
 * now. With `--apply` it refuses unless there is none. The version must hold no undecided (NULL)
 * field, `--by` must name an enabled HQ user holding `hq.policies.manage`, and `--reason` is
 * required. The previously active version is retired in the same transaction. The database
 * refuses the same things again underneath.
 *
 * ⛔ Activating a policy grants nothing: it is a ceiling the resolver combines by minimum, and with
 * no matching autonomy grant the answer stays A0.
 */
class AutonomyPolicyActivate extends Command
{
    protected $signature = 'hq:autonomy-policy:activate
        {version : the proposed version to activate}
        {--by= : email of the HQ user whose decision this is}
        {--reason= : why, in words, and on whose decision}
        {--apply : actually activate it. Without it the command only reports.}';

    protected $description = 'Check, and with --apply activate, a proposed autonomy evidence policy version.';

    public function handle(EvidencePolicies $policies): int
    {
        $policy = AutonomyEvidencePolicy::query()->where('version', (int) $this->argument('version'))->first();
        if ($policy === null) {
            $this->error("There is no autonomy evidence policy version {$this->argument('version')}.");

            return self::FAILURE;
        }

        $by = null;
        if (($email = $this->option('by')) !== null) {
            $by = User::query()->where('email', $email)->first();
            if ($by === null) {
                $this->error("No HQ user has the email [{$email}].");

                return self::FAILURE;
            }
        }

        $refusals = $policies->activationRefusals($policy, $by, $this->option('reason'));
        $this->line("Version {$policy->version} ({$policy->name}), {$policy->status}: ".($refusals === [] ? '<info>it can be activated</info>' : '<error>'.count($refusals).' reason(s) it cannot</error>'));
        foreach ($refusals as $refusal) {
            $this->line("  - {$refusal}");
        }

        if (! $this->option('apply')) {
            $this->info('dry run: nothing was changed. Add --apply (with --by and --reason) to activate it.');

            return $refusals === [] ? self::SUCCESS : self::FAILURE;
        }
        if ($refusals !== []) {
            $this->error('Not activated.');

            return self::FAILURE;
        }

        try {
            $policy = $policies->activate($policy, $by, (string) $this->option('reason'));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Version {$policy->version} is ACTIVE (digest {$policy->content_digest}). It is a ceiling, never a grant.");

        return self::SUCCESS;
    }
}
