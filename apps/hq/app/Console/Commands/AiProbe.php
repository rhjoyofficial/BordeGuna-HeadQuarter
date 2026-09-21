<?php

namespace App\Console\Commands;

use App\Agents\AgentResponseSchema;
use App\Ai\Budget\AiBudget;
use App\Ai\CircuitBreaker;
use App\Ai\ModelRouter;
use App\Ai\ModelRuntime;
use App\Ai\Providers\OpenAiProvider;
use App\Ai\Providers\ProviderRegistry;
use App\Ai\RoutingContext;
use App\Ai\ServingProvider;
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
 *
 * docs/81 C.7: it also says which provider SERVES (one, none, or more than one) and, for OpenAI, the
 * reasoning effort, because both are part of what a gate measures and the gate log carries this
 * output. `--live --schema` sends one agent-shaped request under `AgentResponseSchema` itself, so a
 * provider's handling of HQ's structured replies is proven before a gate depends on it.
 */
class AiProbe extends Command
{
    protected $signature = 'hq:ai:probe
        {--live : Actually call the provider. Costs a fraction of a cent. Without this, nothing is sent.}
        {--schema : With --live, ask for one reply under the agent response schema and check it}
        {--task=diagnostic : The TaskType to route}
        {--business= : Route as this business id, so per-business budget applies}';

    protected $description = 'Show what the model router would choose, and optionally make one real call end to end.';

    public function handle(
        ProviderRegistry $providers,
        ModelRouter $router,
        ModelRuntime $runtime,
        CircuitBreaker $breaker,
        AiBudget $budget,
        ServingProvider $serving,
    ): int {
        if ($this->option('schema') && ! $this->option('live')) {
            $this->error('--schema needs --live: a structured check is only worth anything as a real call.');

            return self::FAILURE;
        }

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

        $current = $serving->current();
        $this->line('  serving provider: '.match ($serving->state()) {
            ServingProvider::SINGLE => (string) $current,
            ServingProvider::NONE => 'none (no active row at a chat tier)',
            default => 'MORE THAN ONE provider serves ('.implode(', ', $serving->active()).'); a gate measures exactly one, choose it with hq:ai:provider',
        });
        if ($current === OpenAiProvider::PROVIDER) {
            $this->line('  reasoning effort: '.OpenAiProvider::reasoningEffort().' (hq.ai.openai.reasoning_effort)');
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

        $withSchema = (bool) $this->option('schema');

        try {
            $outcome = $withSchema
                ? $runtime->run(
                    task: $task,
                    system: 'You are a connectivity probe for an internal operations system. Reply with one JSON object only.',
                    messages: [['role' => 'user', 'content' => 'Reply with a JSON object: status "answer", task "probe", authority_request "none", '
                        .'answer "HQ model router is working.", refusal_reason "", and facts, inferences and tool_calls as empty arrays.']],
                    context: $context,
                    jsonSchema: AgentResponseSchema::schema(),
                    maxOutputTokens: (int) config('hq.ai.probe.schema_max_output_tokens', 512),
                    promptVersion: 'probe/schema-1',
                )
                : $runtime->run(
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

        // ⚠⚠ A gate that passes on an empty reply proves nothing. This is reachable: on the
        // tiers served by a model that reasons before answering, a small `max_output_tokens` can be
        // spent entirely before the first visible token, and the call then returns successfully with
        // no text at all. Reporting SUCCESS there would certify a path that never delivered a word.
        if (trim($outcome->text) === '') {
            $this->error('The call succeeded but returned an EMPTY reply, so the path is NOT proven.');
            $this->line('  '.$outcome->provenance());
            $this->line("  ai_run #{$outcome->run->id} holds the token counts. If output_tokens is nonzero, the");
            $this->line('  budget was spent before any visible text: raise --task tier headroom via');
            $this->line('  hq.ai.probe.max_output_tokens and run it again.');

            return self::FAILURE;
        }

        if ($withSchema) {
            // The runtime has already validated the object against the schema (one repair at most);
            // reaching here means the provider handled HQ's agent schema end to end.
            $this->info('  structured reply valid against the agent response schema: status '.($outcome->data['status'] ?? '?'));
        }
        $this->info('  reply: '.trim($outcome->text));
        $this->line('  '.$outcome->provenance());
        $this->line("  ai_run #{$outcome->run->id}, status {$outcome->run->status}, correlation {$outcome->run->correlation_id}");

        return self::SUCCESS;
    }
}
