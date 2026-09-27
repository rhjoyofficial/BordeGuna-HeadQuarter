<?php

namespace App\Ai;

use App\Ai\Budget\AiBudget;
use App\Ai\Exceptions\BudgetExceeded;
use App\Ai\Exceptions\NoModelAvailable;
use App\Ai\Exceptions\OutputTruncated;
use App\Ai\Exceptions\ProviderRefused;
use App\Ai\Exceptions\ProviderUnavailable;
use App\Ai\Exceptions\StructuredOutputFailed;
use App\Ai\Providers\ModelProvider;
use App\Ai\Providers\ModelRequest;
use App\Ai\Providers\ModelResponse;
use App\Ai\Providers\ProviderRegistry;
use App\Gateway\Credentials\CredentialVault;
use App\Models\AiModel;
use App\Models\AiRun;
use App\Models\AiRunReply;
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
 * The sequence: budget check, route, open the provider credential scope, call, and then sort the
 * reply into one of five outcomes that are kept apart on purpose (ADR-038):
 *
 *  1. a valid reply (or, with a schema, a valid object);
 *  2. a provider error: `ProviderUnavailable` (retryable, the breaker counts it, the next candidate
 *     is tried) or `ProviderRefused` (the request is broken; nobody else is asked);
 *  3. **a truncated reply**: the provider stopped at the output limit. Re-asked ONCE with a larger
 *     budget from `OutputBudget`, and if that is cut off too, or there is no headroom, the run ends
 *     as `OutputTruncated`;
 *  4. a malformed structured reply: re-asked ONCE with the validation error; a second failure is
 *     `StructuredOutputFailed`;
 *  5. a repair that was itself truncated, which is outcome 3 and is reported as such.
 *
 * ⚠⚠ Outcome 3 used to be reported as outcome 4. A reply cut off mid-object is not a JSON object,
 * so the schema check called it malformed, the repair ran into the same limit, and two safety gates
 * in a row recorded a `model_error` that was really a budget. The `ai_runs` row now carries the
 * provider's stop reason and the budget the call was sent with, so that reading never has to be
 * inferred from token counts again (docs/65 §F, ADR-038).
 *
 * Recovery is bounded: at most one raise and one repair per provider attempt, so three provider
 * calls at the very most, every one billed and counted on the same row. Every attempt writes its
 * own `ai_runs` row, including the failed ones, because a fallback that leaves no trace is
 * indistinguishable from a first-choice success.
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
        private readonly OutputBudget $output,
        // docs/90 A.1 step 1: the raw structured reply, kept beside the run before anything reads it.
        private readonly ModelReplyRecorder $replies,
    ) {}

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @param  array<string, mixed>|null  $jsonSchema  when given, the reply must validate against it
     * @param  int|null  $maxOutputTokens  the budget asked for; null means the policy's default.
     *                                     Clamped to the model and the ceiling before it is sent.
     *
     * @throws NoModelAvailable|BudgetExceeded|ProviderUnavailable|ProviderRefused|StructuredOutputFailed|OutputTruncated
     */
    public function run(
        TaskType $task,
        string $system,
        array $messages,
        ?RoutingContext $context = null,
        ?array $jsonSchema = null,
        ?int $maxOutputTokens = null,
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
            maxOutputTokens: $this->output->clamp($maxOutputTokens ?? $this->output->default(), $choice->model),
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
            } catch (OutputTruncated $e) {
                // Not retryable across providers either: the answer was too long for the room it
                // was given, and a different model would write the same answer into the same room.
                // Not the provider's fault, so the breaker is not touched.
                $this->failRun($run, 'output_truncated: '.$e->getMessage());

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

    /**
     * One provider attempt: the call, at most one raise for a truncated reply, at most one repair
     * for a malformed one.
     */
    private function attempt(ModelRequest $request, AiRun $run, ?array $jsonSchema): AiOutcome
    {
        $provider = $this->providers->get($request->model->provider);

        $run->forceFill(['max_output_tokens' => $request->maxOutputTokens])->save();

        $response = $this->call($provider, $request);

        // ── Truncation, BEFORE the schema check (ADR-038) ─────────────────────────────────────
        // A reply the provider stopped at the output limit is not a malformed reply; it is an
        // unfinished one. Handing it to the schema check would report it as malformed and spend
        // the repair call running into the same limit, which is exactly what failed gate 3.
        if ($response->truncated()) {
            $raised = $this->output->truncationRetries() > 0
                ? $this->output->raised($request->maxOutputTokens, $request->model)
                : null;

            if ($raised === null) {
                $this->recordUsage($run, $request->model, $response);
                $this->keep($run, $request, $response, $jsonSchema, AiRunReply::KIND_TRUNCATED);

                throw new OutputTruncated(
                    "The model stopped at the output limit ({$response->outputTokens} output tokens against a budget of "
                    ."{$request->maxOutputTokens}) and there was no headroom to retry: the budget is already at the "
                    .'model\'s limit or the configured ceiling.',
                    $response->outputTokens, $request->maxOutputTokens,
                );
            }

            $request = $request->withMaxOutputTokens($raised);
            $run->forceFill(['max_output_tokens' => $raised])->save();

            $response = $this->call($provider, $request)->plus($response, ['truncation_retry' => true, 'raised_to' => $raised]);

            if ($response->truncated()) {
                $this->recordUsage($run, $request->model, $response);
                $this->keep($run, $request, $response, $jsonSchema, AiRunReply::KIND_TRUNCATED);

                throw new OutputTruncated(
                    "The model stopped at the output limit twice: at the original budget and again at {$raised}. "
                    .'Recovery is bounded to one raise, so the run ends here rather than retrying into the same limit.',
                    $response->outputTokens, $raised,
                );
            }
        }

        $data = null;
        $kind = AiRunReply::KIND_FINAL;

        if ($jsonSchema !== null) {
            $check = $this->structured->check($response->json(), $jsonSchema);

            if (! $check['valid']) {
                // The malformed first reply is evidence too: what the model wrote before the repair.
                $this->keep($run, $request, $response, $jsonSchema, AiRunReply::KIND_INVALID);
                $kind = AiRunReply::KIND_REPAIRED;
                // One repair, with the actual validation error handed back. Both calls are billed,
                // so both are counted on this run.
                $repairRequest = $request->withMessages(
                    $this->structured->repairTurns($request->messages, $response->text, (string) $check['message'], $jsonSchema)
                );

                $response = $this->call($provider, $repairRequest)->plus($response, ['repaired' => true]);

                // A repair that was itself cut off is a truncation and is reported as one. Calling
                // it a malformed object would send the next reader to the schema for a fault that
                // is in the budget.
                if ($response->truncated()) {
                    $this->recordUsage($run, $request->model, $response);
                    $this->keep($run, $request, $response, $jsonSchema, AiRunReply::KIND_TRUNCATED);

                    throw new OutputTruncated(
                        "The repair reply was stopped at the output limit (budget {$request->maxOutputTokens}).",
                        $response->outputTokens, $request->maxOutputTokens,
                    );
                }

                $check = $this->structured->check($response->json(), $jsonSchema);

                if (! $check['valid']) {
                    $this->recordUsage($run, $request->model, $response);
                    $this->keep($run, $request, $response, $jsonSchema, AiRunReply::KIND_INVALID);

                    throw new StructuredOutputFailed('The model did not produce a valid object after one repair: '.$check['message']);
                }
            }

            $data = $check['data'];
        }

        $this->recordUsage($run, $request->model, $response, 'succeeded');
        $this->keep($run, $request, $response, $jsonSchema, $kind);

        return new AiOutcome(
            text: $response->text,
            data: $data,
            run: $run->refresh(),
            model: $request->model,
            degraded: $run->degraded_from_tier !== null,
        );
    }

    /**
     * docs/90 A.1 step 1: a STRUCTURED reply is kept raw beside its run, whatever became of it
     * (accepted, repaired, rejected, cut off), before the runtime reads a single field. Free-text
     * calls (a narration) keep nothing here: the evidence this exists for is the tool call a model
     * wrote and the runtime read.
     */
    private function keep(AiRun $run, ModelRequest $request, ModelResponse $response, ?array $jsonSchema, string $kind): void
    {
        if ($jsonSchema === null) {
            return;
        }
        try {
            $this->replies->record($run, $request, $response, $kind);
        } catch (Throwable $e) {
            // Evidence lost is reported; the call it is evidence about is not failed for it.
            report($e);
        }
    }

    /**
     * The credential scope is opened around the call and closed in the vault's `finally`, so the
     * key exists in memory only for the duration of one request.
     */
    private function call(ModelProvider $provider, ModelRequest $request): ModelResponse
    {
        return $this->vault->openForAccounts(
            [$provider->credentialPair()],
            fn (): ModelResponse => $provider->complete($request),
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
            // Why the LAST reply on this row ended, in the driver's neutral vocabulary. A truncation
            // is then a recorded fact, not a subtraction somebody does while reading a failed gate.
            'stop_reason' => substr($response->stopReason, 0, 32),
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
