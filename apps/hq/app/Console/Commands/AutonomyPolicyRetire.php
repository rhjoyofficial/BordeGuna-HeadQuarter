<?php

namespace App\Console\Commands;

use App\Autonomy\Evidence\EvidencePolicies;
use App\Models\AutonomyEvidencePolicy;
use App\Models\User;
use Illuminate\Console\Command;
use Throwable;

/**
 * Withdraw the active autonomy evidence policy version (ADR-048), by a named person with a reason.
 *
 * Afterwards no version is active, so evidence can widen nothing until another is activated. A dry
 * run without `--apply`.
 */
class AutonomyPolicyRetire extends Command
{
    protected $signature = 'hq:autonomy-policy:retire
        {version : the active version to retire}
        {--by= : email of the HQ user whose decision this is}
        {--reason= : why, in words}
        {--apply : actually retire it. Without it the command only reports.}';

    protected $description = 'Check, and with --apply retire, the active autonomy evidence policy version.';

    public function handle(EvidencePolicies $policies): int
    {
        $policy = AutonomyEvidencePolicy::query()->where('version', (int) $this->argument('version'))->first();
        if ($policy === null) {
            $this->error("There is no autonomy evidence policy version {$this->argument('version')}.");

            return self::FAILURE;
        }

        $by = ($email = $this->option('by')) === null ? null : User::query()->where('email', $email)->first();
        if ($email !== null && $by === null) {
            $this->error("No HQ user has the email [{$email}].");

            return self::FAILURE;
        }

        $this->line("Version {$policy->version} ({$policy->name}) is {$policy->status}.");

        if (! $this->option('apply')) {
            $this->info('dry run: nothing was changed. Add --apply (with --by and --reason) to retire it.');

            return $policy->isActive() ? self::SUCCESS : self::FAILURE;
        }

        try {
            $policies->retire($policy, $by, $this->option('reason'));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Version {$policy->version} is retired. No autonomy evidence policy is active now.");

        return self::SUCCESS;
    }
}
