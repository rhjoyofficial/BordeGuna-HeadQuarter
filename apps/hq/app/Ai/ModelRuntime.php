<?php

namespace App\Ai;

use App\Ai\Budget\AiBudget;
use App\Ai\Exceptions\BudgetExceeded;
use App\Ai\Exceptions\NoModelAvailable;
use App\Ai\Exceptions\ProviderRefused;
use App\Ai\Exceptions\ProviderUnavailable;
use App\Ai\Exceptions\StructuredOutputFailed;
use App\Ai\Providers\ModelRequest;
use App\Ai\Providers\ModelResponse;
use App\Ai\Providers\ProviderRegistry;
use App\Gateway\Credentials\CredentialVault;
use App\Models\AiModel;
use App\Models\AiRun;
use App\Support\Features\Features;
use App\Support\Notifications\Notifier;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs one model call and leaves a complete, costed, traceable record of it.
 *
 * This is the ONLY place in HQ that calls a provider. Everything else asks for a task to be done.
 * That monopoly is what makes `ai_runs` trustworthy: if a second path existed, the cost table would
 * be a sample rather than a ledger.
 *
 * ⚠ **The model is a proposer, never an executor.** Nothing here can run a tool, touch a credential
 * beyond the provider key, reach a shop or the ERP, or write business data. It sends text and
 * returns text. Every action a model might suggest still goes through the Tool Gateway, under a
 * human's identity, subject to policy and approval. M4 changes none of that.
 *
 * The sequence: budget check, route, open the provider credential scope, call, enforce the schema
 * with at most one repair, and on a retryable failure fall through to the next candidate, preferring
 * a different provider. Every attempt writes its own `ai_runs` row, including the failed ones,
 * because a fallback that leaves no trace is indistinguishable from a first-choice success.
 */
final class ModelRuntime
{
    /** Total providers/models tried for one logical call, including the first. */
    private const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly ModelRouter $router,
        private readonly ProviderRegistry $providers,
        private readonly CredentialVault $vault,
        private readonly CircuitBreaker $breaker,
        private readonly CostCalculator $costs,
        private readonly StructuredOutput $structured,
        private readonly AiBudget $budget,
        private readonly Notifier $notifier,
    ) {}

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @param  array<string, mixed>|null  $jsonSchema  when given, the reply must validate against it
     *
     * @throws NoModelAvailable|BudgetExceeded|ProviderUnavailable|ProviderRefused|StructuredOutputFailed
     */
    public function run(
        TaskType $task,
        string $system,
        array $messages,
        ?RoutingContext $context = null,
        ?array $jsonSchema = null,
        int $maxOutputTokens = 1024,
        ?string $promptVersion = null,
    ): AiOutcome {
        $context ??= new RoutingContext;

        $this->assertRuntimeEnabled();
        $this->assertWithinHardCap($context);

        $choice = $this->router->for($task, $context);
        $queue = array_merge([$choice->model], $this->router->candidates($choice, $context));

        $request = new ModelRequest(
            model: $choice->model,
            system: $system,
            messages: $messages,
            maxOutputTokens: $maxOutputTokens,
            jsonSchema: $jsonSchema,
        );

        $lastError = null;

        foreach (array_slice($queue, 0, self::MAX_ATTEMPTS) as $attempt => $model) {
            $run = $this->startRun($task, $choice, $model, $context, $promptVersion, $attempt + 1);

            try {
                $outcome = $this->attempt($request->onModel($model), $run, $jsonSchema);

                $this->breaker->recordSuccess($model->provider);
                $this->announceBudgetPressure($context);

                return $outcome;
            } catch (ProviderUnavailable $e) {
                // Retryable: the request was fine, so count it against the provider and move on.
                $this->breaker->recordFailure($model->provider);
                $this->failRun($run, 'provider_unavailable: '.$e->getMessage());
                $lastError = $e;

                Log::warning('ai provider unavailable, falling back', [
                    'provider' => $model->provider, 'attempt' => $attempt + 1, 'task' => $task->value,
                ]);
            } catch (ProviderRefused $e) {
                // Not retryable and NOT the provider's fault. Trying a second provider would only
                // produce a second refusal, so stop here rather than burning another call.
                $this->failRun($run, 'provider_refused: '.$e->getMessage());

                throw $e;
            } catch (StructuredOutputFailed $e) {
                $this->failRun($run, 'structured_output_failed: '.$e->getMessage());

                throw $e;
            } catch (Throwable $e) {
                $this->failRun($run, get_class($e).': '.$e->getMessage());

                throw $e;
            }
        }

        throw $lastError ?? new NoModelAvailable('No model could serve the call and no error was recorded, which should be impossible.');
    }

    /** One provider attempt, including at most one schema repair. */
    private function attempt(ModelRequest $request, AiRun $run, ?array $jsonSchema): AiOutcome
    {
        $provider = $this->providers->get($request->model->provider);

        // The credential scope is opened around the call and closed in the vault's `finally`, so the
        // key exists in memory only for the duration of one request.
        $response = $this->vault->openForAccounts(
            [$provider->credentialPair()],
            fn (): ModelResponse => $provider->complete($request),
        );

        $data = null;

        if ($jsonSchema !== null) {
            $check = $this->structured->check($response->json(), $jsonSchema);

            if (! $check['valid']) {
                // One repair. The failed attempt is still charged for, so its tokens are recorded on
                // this run before the second call is made.
                $repairRequest = $request->withMessages(
                    $this->structured->repairTurns($request->messages, $response->text, (string) $check['message'], $jsonSchema)
                );

                $firstTokens = [$response->inputTokens, $response->outputTokens, $response->cachedTokens, $response->cacheWriteTokens];

                $response = $this->vault->openForAccounts(
                    [$provider->credentialPair()],
                    fn (): ModelResponse => $provider->complete($repairRequest),
                );

                // Both calls are billed, so both are counted. Recording only the successful one
                // would make repairs look free and remove the incentive to fix the prompt.
                $response = new ModelResponse(
                    text: $response->text,
                    inputTokens: $response->inputTokens + $firstTokens[0],
                    outputTokens: $response->outputTokens + $firstTokens[1],
                    cachedTokens: $response->cachedTokens + $firstTokens[2],
                    latencyMs: $response->latencyMs,
                    stopReason: $response->stopReason,
                    cacheWriteTokens: $response->cacheWriteTokens + $firstTokens[3],
                    rawUsage: $response->rawUsage + ['repaired' => true],
                );

                $check = $this->structured->check($response->json(), $jsonSchema);

                if (! $check['valid']) {
                    $this->recordUsage($run, $request->model, $response);

                    throw new StructuredOutputFailed('The model did not produce a valid object after one repair: '.$check['message']);
                }
            }

            $data = $check['data'];
        }

        $this->recordUsage($run, $request->model, $response, 'succeeded');

        return new AiOutcome(
            text: $response->text,
            data: $data,
            run: $run->refresh(),
            model: $request->model,
            degraded: $run->degraded_from_tier !== null,
        );
    }

    private function startRun(TaskType $task, ModelChoice $choice, AiModel $model, RoutingContext $context, ?string $promptVersion, int $attempt): AiRun
    {
        return AiRun::create([
            'correlation_id' => $context->correlationId,
            'agent_key' => $context->agentKey,
            'business_id' => $context->businessId,
            'brand_id' => $context->brandId,
            'task' => $task->value,
            'provider' => $model->provider,
            'model' => $model->model_id,
            'model_tier' => $model->tier,
            'degraded_from_tier' => $choice->degradedFrom?->value,
            'routing_reason' => substr($choice->reason, 0, 160),
            'prompt_version' => $promptVersion,
            'input_ref' => $context->inputRef,
            'status' => 'pending',
            'attempt' => $attempt,
            'started_at' => now(),
        ]);
    }

    private function recordUsage(AiRun $run, AiModel $model, ModelResponse $response, ?string $status = null): void
    {
        $estimate = $this->costs->estimate(
            $model, $response->inputTokens, $response->outputTokens, $response->cachedTokens, $response->cacheWriteTokens,
        );

        $run->forceFill(array_filter([
            'input_tokens' => $response->inputTokens,
            'output_tokens' => $response->outputTokens,
            'cached_tokens' => $response->cachedTokens,
            // Stored, not just priced: without it the row cannot reproduce its own cost, and a
            // cost you cannot re-derive is a number rather than a record.
            'cache_write_tokens' => $response->cacheWriteTokens,
            'latency_ms' => $response->latencyMs,
            'estimated_cost_usd' => $estimate,
            // ⚠ `actual_cost_usd` stays NULL. It is filled only by a future reconciliation against
            // the provider's own billing; writing our own estimate into it would make the two
            // columns agree by construction and destroy the only check they exist to provide.
            'status' => $status,
            'finished_at' => now(),
        ], fn ($v) => $v !== null))->save();
    }

    private function failRun(AiRun $run, string $error): void
    {
        $run->forceFill([
            'status' => 'failed',
            'error' => substr($error, 0, 1000),
            'finished_at' => now(),
        ])->save();
    }

    /**
     * The master switch.
     *
     * ⚠ It is a **feature flag, not config**, so it can be turned off without a deploy while an
     * incident is in progress, and it is OFF by default: `Features::enabled()` returns false when no
     * row exists. HQ ships M4 able to call a model and not doing so, which is the safety posture
     * Joy asked to keep unchanged. `ai.freeze` remains a separate, wider switch that stops every
     * tool as well.
     */
    private function assertRuntimeEnabled(): void
    {
        $flag = (string) config('hq.ai.enabled_flag', 'ai.runtime');

        if (! app(Features::class)->enabled($flag)) {
            throw new NoModelAvailable(
                "The AI runtime is switched off. No model call will be made until the [{$flag}] feature flag is enabled. "
                .'This is the default: HQ ships able to call a model without doing so.'
            );
        }
    }

    private function assertWithinHardCap(RoutingContext $context): void
    {
        $pressure = $this->budget->pressure($context->businessId);

        if ($pressure['refuse']) {
            // Refusing silently is forbidden: whoever set the cap is told what did not run.
            $this->notifier->budgetRefused($pressure);

            throw new BudgetExceeded(
                "The AI spend cap for {$pressure['scope']} is USD {$pressure['cap']} and "
                ."USD {$pressure['spent']} has been spent this period. This is a HARD cap, so the call was refused."
            );
        }
    }

    private function announceBudgetPressure(RoutingContext $context): void
    {
        $pressure = $this->budget->pressure($context->businessId);

        if (! $pressure['notify']) {
            return;
        }

        // Once per period per scope, not once per call: a cap warning that arrives every few seconds
        // is a cap warning nobody reads.
        $once = 'hq:ai:budget-notified:'.md5($pressure['scope'].date('Y-m'));

        if (cache()->add($once, true, now()->endOfMonth())) {
            $this->notifier->budgetPressure($pressure);
        }
    }
}
