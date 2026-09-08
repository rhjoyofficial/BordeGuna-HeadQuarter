<?php

namespace App\Console\Commands;

use App\Ai\Budget\AiBudget;
use App\Ai\CircuitBreaker;
use App\Ai\ModelRouter;
use App\Ai\ModelRuntime;
use App\Ai\Providers\ProviderRegistry;
use App\Ai\RoutingContext;
use App\Ai\TaskType;
use App\Models\AiModel;
use Illuminate\Console\Command;
use Throwable;

/**
 * The M4 safety gate: prove the whole path with one real call, and record it completely.
 *
 * ⚠ `--dry` is the DEFAULT. Running this command must never spend money by accident, so a real call
 * needs `--live` typed deliberately. Dry mode reports exactly what would happen: which provider, which
 * model, why, what the breaker thinks, and what the budget looks like.
 */
class AiProbe extends Command
{
    protected $signature = 'hq:ai:probe
        {--live : Actually call the provider. Costs a fraction of a cent. Without this, nothing is sent.}
        {--task=diagnostic : The TaskType to route}
        {--business= : Route as this business id, so per-business budget applies}';

    protected $description = 'Show what the model router would choose, and optionally make one real call end to end.';

    public function handle(
        ProviderRegistry $providers,
        ModelRouter $router,
        ModelRuntime $runtime,
        CircuitBreaker $breaker,
        AiBudget $budget,
    ): int {
        $task = TaskType::tryFrom((string) $this->option('task'));
        if ($task === null) {
            $this->error('Unknown task. One of: '.implode(', ', array_column(TaskType::cases(), 'value')));

            return self::FAILURE;
        }

        $this->line('<comment>Providers</comment>');
        $availability = $providers->availability();
        if ($availability === []) {
            $this->warn('  none registered in config/hq.ai.providers');
        }
        foreach ($availability as $key => $available) {
            $this->line(sprintf('  %-12s credential: %-13s breaker: %s',
                $key,
                $available ? 'configured' : 'NOT CONFIGURED',
                $breaker->state($key),
            ));
        }

        $registered = AiModel::query()->orderBy('tier')->orderBy('priority')->get();
        $this->newLine();
        $this->line('<comment>Registered models</comment>');
        $this->table(
            ['tier', 'provider', 'model', 'ctx', 'in $/M', 'out $/M', 'prio', 'active'],
            $registered->map(fn (AiModel $m) => [
                $m->tier, $m->provider, $m->model_id, number_format($m->context_window),
                $m->input_price_per_mtok, $m->output_price_per_mtok, $m->priority,
                $m->is_active ? 'yes' : 'no',
            ])->all(),
        );

        $businessId = $this->option('business') !== null ? (int) $this->option('business') : null;
        $pressure = $budget->pressure($businessId);
        $this->line('<comment>Budget</comment>');
        $this->line(sprintf('  scope %s: spent $%s of $%s (%d%%)%s',
            $pressure['scope'],
            number_format($pressure['spent'], 4),
            is_finite($pressure['cap']) ? number_format($pressure['cap'], 2) : 'unbounded',
            $pressure['share_percent'],
            $pressure['downgrade'] ? '  DEGRADING' : ($pressure['refuse'] ? '  REFUSING' : ''),
        ));

        $this->newLine();
        $context = new RoutingContext(businessId: $businessId, inputRef: 'hq:ai:probe');

        try {
            $choice = $router->for($task, $context);
        } catch (Throwable $e) {
            $this->error('Routing refused: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line('<comment>Routing</comment>');
        $this->line("  task {$task->value} -> {$choice->describe()}");
        $this->line("  reason: {$choice->reason}");

        if (! $this->option('live')) {
            $this->newLine();
            $this->info('Dry run: nothing was sent and no money was spent. Add --live to make one real call.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('<comment>Live call</comment>');

        try {
            $outcome = $runtime->run(
                task: $task,
                system: 'You are a connectivity probe for an internal operations system. Answer in one short sentence.',
                messages: [['role' => 'user', 'content' => 'Reply with exactly: HQ model router is working.']],
                context: $context,
                maxOutputTokens: (int) config('hq.ai.probe.max_output_tokens', 64),
                promptVersion: 'probe/1',
            );
        } catch (Throwable $e) {
            $this->error(get_class($e));
            $this->error($e->getMessage());
            $this->newLine();
            $this->line('The failed attempt was still recorded in ai_runs, so the failure is on the record.');

            return self::FAILURE;
        }

        $this->info('  reply: '.trim($outcome->text));
        $this->line('  '.$outcome->provenance());
        $this->line("  ai_run #{$outcome->run->id}, status {$outcome->run->status}, correlation {$outcome->run->correlation_id}");

        return self::SUCCESS;
    }
}
