<?php

namespace App\Console\Commands;

use App\Conversation\Language\LanguagePolicies;
use App\Models\LanguagePolicy;
use App\Models\User;
use Illuminate\Console\Command;
use Throwable;

/**
 * The one audited activation of a customer language policy version (docs/92 A.8).
 *
 * ⛔ Without `--apply` it only reads: every reason the version cannot become active, for this person,
 * now. With `--apply` it refuses unless there is none. `--by` must name an enabled HQ user holding
 * `hq.policies.manage`, and `--reason` is required. The previously active version is retired in the
 * same transaction. The database refuses the same things again underneath.
 *
 * ⛔ This is the only way the reply-script rule changes. The model never chooses it, a prompt never
 * overrides it, and the runtime enforces whichever version is active (docs/92 A.1).
 */
class LanguagePolicyActivate extends Command
{
    protected $signature = 'hq:language-policy:activate
        {version : the proposed version to activate}
        {--by= : email of the HQ user whose decision this is}
        {--reason= : why, in words, and on whose decision}
        {--apply : actually activate it. Without it the command only reports.}';

    protected $description = 'Check, and with --apply activate, a proposed customer language policy version.';

    public function handle(LanguagePolicies $policies): int
    {
        $policy = LanguagePolicy::query()->where('version', (int) $this->argument('version'))->first();
        if ($policy === null) {
            $this->error("There is no language policy version {$this->argument('version')}.");

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
        foreach ((array) data_get($policy->rules, 'rules', []) as $key => $rule) {
            $this->line(sprintf('  %-9s -> %s in %s script', $key, (string) ($rule['language'] ?? '?'), (string) ($rule['script'] ?? '?')));
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

        $this->info("Version {$policy->version} is ACTIVE (digest {$policy->content_digest}). Every Support reply from now on is held to it.");

        return self::SUCCESS;
    }
}
