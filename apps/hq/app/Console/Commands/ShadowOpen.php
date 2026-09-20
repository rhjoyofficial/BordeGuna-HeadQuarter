<?php

namespace App\Console\Commands;

use App\Agents\Shadow\ShadowPeriods;
use App\Agents\Shadow\ShadowProbes;
use App\Models\Agent;
use App\Models\User;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * The one audited activation of an agent's A0 shadow period (docs/77 C.2.2, ChatGPT's point 6).
 *
 * ⛔ Without `--apply` it only reads: every precondition with its answer, and the evaluation runs the
 * period would rest on. With `--apply` it refuses unless every precondition holds, then turns
 * `ai.runtime` on, sets the agent active at its unchanged A0 ceiling, and writes the period, each
 * step audited with the reason given. `--by` names the person on whose word this is done; the audit
 * rows carry that user as the actor.
 *
 * ⛔ On production `--by` is mandatory and must name an enabled HQ user, and `--no-schedule` is
 * refused (docs/81 points 11 and 12). Both are enforced in `ShadowPeriods::open()`; this command
 * only says so earlier. Local and testing keep the convenience path.
 */
class ShadowOpen extends Command
{
    protected $signature = 'hq:shadow:open
        {agent : the agent to shadow (this milestone: executive)}
        {--reason= : why, in words, and on whose decision}
        {--by= : email of the HQ user whose decision this is}
        {--question=* : a Shadow Probe question (repeatable); the three default probes when none}
        {--no-schedule : no Shadow Probes; manual and test use only, refused on production}
        {--apply : actually open it. Without it the command only reports.}';

    protected $description = 'Check, and with --apply open, an agent\'s seven-day A0 shadow period.';

    public function handle(ShadowPeriods $periods): int
    {
        $key = (string) $this->argument('agent');
        $refusals = $periods->refusals($key);
        $agent = Agent::query()->where('key', $key)->first();

        $this->line("Shadow period for <comment>{$key}</comment>: ".($refusals === [] ? '<info>every precondition holds</info>' : '<error>'.count($refusals).' precondition(s) fail</error>'));
        foreach ($refusals as $refusal) {
            $this->line("  - {$refusal}");
        }
        if ($agent !== null) {
            foreach ($periods->evidenceFor($agent) as $suite => $row) {
                $this->line(sprintf('  evidence %-22s run #%s %s, unmeasured %s, prompt %s, by %s',
                    $suite, $row['run_id'] ?? '-', $row['status'] ?? '-', $row['unmeasured'] ?? '-', $row['prompt_version'] ?? '-', $row['triggered_by'] ?? '-'));
            }
        }
        // docs/81 points 11 and 12: the opener is read before anything is reported, so a dry run
        // says what an --apply would say. A named person must exist and be enabled everywhere; on
        // production one must be named.
        $user = null;
        $openerRefusal = null;
        if (($email = $this->option('by')) !== null) {
            $user = User::query()->where('email', $email)->first();
            if ($user === null) {
                $openerRefusal = "No HQ user has the email [{$email}].";
            }
        }
        if ($openerRefusal === null) {
            try {
                $periods->assertOpener($user?->getKey());
            } catch (RuntimeException $e) {
                $openerRefusal = $user === null ? $e->getMessage() : "[{$email}] is disabled, so nothing opens on their word.";
            }
        }
        $this->line('  opener: '.($user === null ? 'none named (--by)' : "{$user->email} (user #{$user->getKey()})")
            .($openerRefusal === null ? '' : ' - REFUSED: '.$openerRefusal));

        $questions = (array) $this->option('question');
        $scheduled = ! $this->option('no-schedule');
        if (! $scheduled) {
            $this->line('  shadow probes: none (--no-schedule)'.(app()->isProduction() ? ' - REFUSED on production' : ''));
        }
        foreach ($scheduled ? ShadowProbes::define($questions) : [] as $probe) {
            $this->line("  shadow probe {$probe['key']}: {$probe['question']} (09:00 and 17:00 Dhaka)");
        }

        if (! $this->option('apply')) {
            $this->info('dry run: nothing was changed. Add --apply (with --reason and --by) to open it.');

            return $refusals === [] && $openerRefusal === null ? self::SUCCESS : self::FAILURE;
        }
        if ($refusals !== [] || $openerRefusal !== null) {
            $this->error($openerRefusal === null ? 'Not opened.' : "Not opened. {$openerRefusal}");

            return self::FAILURE;
        }

        try {
            $period = $periods->open($key, (string) $this->option('reason'), $questions, $user?->getKey(), scheduled: $scheduled);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Shadow period #{$period->id} OPEN for {$key} at A0, from {$period->opened_at->toIso8601String()} to {$period->planned_end_at->toIso8601String()}.");

        return self::SUCCESS;
    }
}
