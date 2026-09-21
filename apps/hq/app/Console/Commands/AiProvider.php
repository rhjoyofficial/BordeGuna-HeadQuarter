<?php

namespace App\Console\Commands;

use App\Ai\Providers\OpenAiProvider;
use App\Ai\Providers\ProviderRegistry;
use App\Ai\ProviderSelection;
use App\Ai\ServingProvider;
use App\Gateway\Credentials\CredentialHealth;
use App\Gateway\Credentials\CredentialVault;
use App\Models\AiModel;
use Illuminate\Console\Command;
use Throwable;

/**
 * Which model provider serves HQ, and the one audited way to change it (docs/81 C.7).
 *
 *   hq:ai:provider                                   the state: every provider, its key, its rows, who serves
 *   hq:ai:provider openai                            dry run: every row it would switch on and off
 *   hq:ai:provider openai --apply --by=<email> --reason="..."
 *
 * ⛔ Without `--apply` nothing changes. With it, `ProviderSelection` refuses unless a named, enabled
 * person gives a reason, the provider has a driver, a key and a row at every chat tier, and no shadow
 * period is open. It switches rows only: never `ai.runtime`, an agent, a grant or a flag.
 */
class AiProvider extends Command
{
    protected $signature = 'hq:ai:provider
        {provider? : the provider to serve HQ (anthropic, openai); without it the state is shown}
        {--apply : actually switch. Without it the command only reports.}
        {--by= : email of the HQ user whose decision this is (required with --apply)}
        {--reason= : why, in words, and on whose decision (required with --apply)}';

    protected $description = 'Show which model provider serves HQ, and with --apply move every chat tier to one provider (audited).';

    public function handle(ProviderSelection $selection, ProviderRegistry $providers, ServingProvider $serving, CredentialVault $vault, CredentialHealth $health): int
    {
        $this->line('<comment>Model providers</comment>');
        foreach ($providers->keys() as $key) {
            [$credentialProvider, $account] = $providers->get($key)->credentialPair();
            $credential = $vault->active($credentialProvider, $account);
            $last = $credential === null ? null : $health->lastVerification($credential);
            $rows = AiModel::query()->where('provider', $key)->orderBy('tier')->orderBy('priority')->get();

            $this->line(sprintf('  %-10s key: %s; last check: %s',
                $key,
                $credential === null ? 'none stored' : "#{$credential->id} {$credential->status}",
                $last === null ? 'never' : (($last['ok'] ? 'ok' : (string) $last['class']).' via '.$last['source'].' at '.$last['at']),
            ));
            foreach ($rows as $row) {
                $this->line(sprintf('    %-3s %-9s %-26s priority %d', $row->is_active ? 'on' : 'off', $row->tier, $row->model_id, $row->priority));
            }
        }

        $current = $serving->current();
        $this->line('serving: '.match ($serving->state()) {
            ServingProvider::SINGLE => (string) $current,
            ServingProvider::NONE => 'none (no active row at a chat tier)',
            default => 'MIXED ('.implode(', ', $serving->active()).'); a measurement runs on exactly one provider',
        });
        if ($current === OpenAiProvider::PROVIDER) {
            $this->line('openai reasoning effort: '.OpenAiProvider::reasoningEffort().' (hq.ai.openai.reasoning_effort)');
        }

        $provider = $this->argument('provider');
        if ($provider === null) {
            return self::SUCCESS;
        }
        $provider = (string) $provider;

        $refusals = $selection->refusals($provider);
        $this->newLine();
        $this->line("Selecting <comment>{$provider}</comment>: ".($refusals === [] ? '<info>it can serve</info>' : '<error>'.count($refusals).' refusal(s)</error>'));
        foreach ($refusals as $refusal) {
            $this->line("  - {$refusal}");
        }

        if ($refusals === [] || $providers->has($provider)) {
            $plan = $selection->plan($provider);
            foreach ($plan['on'] as $row) {
                $this->line(sprintf('  %-4s %s %s %s', 'on', $row->provider, $row->tier, $row->model_id));
            }
            foreach ($plan['off'] as $row) {
                $this->line(sprintf('  %-4s %s %s %s', 'off', $row->provider, $row->tier, $row->model_id));
            }
            if ($plan['on'] === [] && $plan['off'] === []) {
                $this->line('  no row would change');
            }
        }

        if (! $this->option('apply')) {
            $this->info('Dry run: nothing was changed. Add --apply with --by and --reason to switch.');

            return $refusals === [] ? self::SUCCESS : self::FAILURE;
        }

        $person = $selection->personRefusals($this->option('by'), $this->option('reason'));
        if ($refusals !== [] || $person !== []) {
            foreach ($person as $refusal) {
                $this->line("  - {$refusal}");
            }
            $this->error('Not switched; nothing was changed.');

            return self::FAILURE;
        }

        try {
            $audit = $selection->select($provider, (string) $this->option('by'), (string) $this->option('reason'));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($audit === null) {
            $this->info("{$provider} is already the only provider serving; nothing changed.");

            return self::SUCCESS;
        }

        $this->info(sprintf('%s now serves: %d row(s) on, %d row(s) off (audit #%d, by %s).',
            $provider, count($audit->after['switched_on'] ?? []), count($audit->after['switched_off'] ?? []), $audit->id, $this->option('by')));
        $this->line('Next: hq:credentials:health --verify-model, then hq:ai:probe --live --schema, before any gate.');

        return self::SUCCESS;
    }
}
