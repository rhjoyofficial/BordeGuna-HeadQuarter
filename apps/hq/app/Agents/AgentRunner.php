<?php

namespace App\Agents;

use App\Ai\ModelRuntime;
use App\Ai\RoutingContext;
use App\Autonomy\AutonomyDecision;
use App\Autonomy\AutonomyRequest;
use App\Autonomy\AutonomyResolver;
use App\Context\ContextBlock;
use App\Context\ContextBundle;
use App\Gateway\CallerKind;
use App\Gateway\Descriptor\ReadWrite;
use App\Gateway\Descriptor\RiskTier;
use App\Gateway\Descriptor\ToolDescriptor;
use App\Gateway\Identity\ActingIdentity;
use App\Gateway\OutcomeStatus;
use App\Gateway\ToolGateway;
use App\Gateway\ToolRequest;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Business;
use App\Models\User;
use App\Tools\ToolRegistry;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * The agent loop (docs/48 H.4).
 *
 * ```
 *  question
 *    -> context assembled, every block labelled with its provenance
 *    -> autonomy resolved (A0 in Phase 2: shadow)
 *    -> model turn, reply forced to AgentResponseSchema
 *    -> each proposed call -> ToolGateway::execute() under the agent's identity
 *         allow             -> result folded back, loop may continue
 *         deny              -> reason folded back ONCE; a second denial of the same tool ends the run
 *         awaiting approval -> recorded as a proposal, never waited on
 *    -> bounded: 3 model turns, 8 tool calls, hard-stopped here
 *    -> agent_runs row: answer, evidence, proposals, provenance, and whether it was shadow
 * ```
 *
 * ## Reading is not acting, and that is what makes A0 workable
 *
 * ⭐⭐ **The one interpretation this class had to make, stated plainly.** `AutonomyLevel::A0` reads
 * "runs on real inputs, writes what it WOULD do, executes nothing". Taken to mean "calls no tool at
 * all", a read-only agent at A0 could gather no evidence, produce nothing, and the seven-day shadow
 * period required by V.3 would end with nothing to review - which would defeat the decision it was
 * meant to serve.
 *
 * So: **autonomy governs proposed ACTIONS, not evidence gathering.** An R1/R2 read has no effect on
 * the world; it is the agent perceiving, not the agent acting, and the architecture already draws
 * that line - ADR-024 says reading a customer's message may inform a proposal and may never be the
 * reason an action became automatic. "Executes nothing" therefore means it executes no proposed
 * action, and what A0 withholds is the ANSWER: a shadow run is recorded and reviewed, never
 * delivered as HQ's operational answer.
 *
 * That interpretation is enforced structurally rather than left as prose: `hasEffect()` refuses any
 * tool that writes or reaches R3 unless the resolved level `allowsExecution()`. In Phase 2 the two
 * agents hold no such tool, so the branch is unreachable - which is the point of writing it now,
 * while it costs nothing, rather than when the first write tool arrives and it is load-bearing.
 *
 * ## The loop bound is containment, not performance
 *
 * ⚠ The failure it exists for is a model retrying a denied action in slightly different words until
 * something gets through. After the SECOND denial of the same tool key the run stops and says so,
 * because that pattern is either a broken prompt or an injection attempt and both need a person.
 *
 * ## What this class cannot do
 *
 * ⛔ It holds no credential, executes no tool itself, and has no path to a shop, the ERP or a
 * provider except through `ToolGateway` and `ModelRuntime`. Every refusal reason it shows a model is
 * a decision some other component made. There is deliberately no branch here that runs a tool when
 * the gateway said no.
 */
final class AgentRunner
{
    /** Model turns per run. Three is enough to ask, read, and answer. */
    public const MAX_TURNS = 3;

    /** Tool calls per run, across all turns. */
    public const MAX_TOOL_CALLS = 8;

    /** Denials of the SAME tool key before the run is stopped. */
    private const DENIAL_LIMIT = 2;

    private const MAX_OUTPUT_TOKENS = 4096;

    public function __construct(
        private readonly ModelRuntime $models,
        private readonly ToolGateway $gateway,
        private readonly ToolRegistry $registry,
        private readonly AgentToolset $toolset,
        private readonly ContextAssembler $assembler,
        private readonly AutonomyResolver $autonomy,
        private readonly PromptLibrary $prompts,
    ) {}

    public function run(AgentRunRequest $request): AgentRun
    {
        $agent = Agent::where('key', $request->agentKey)->first();

        if ($agent === null) {
            throw new RuntimeException("There is no agent with the key [{$request->agentKey}].");
        }

        // Fail closed, and say which of the two it is. A paused agent is a deliberate state (it is
        // the documented rollback); an agent with no enabling human has no authority at all, because
        // an agent never has authority of its own.
        if (! $agent->isActive()) {
            throw new RuntimeException("Agent [{$agent->key}] is [{$agent->status}], so it will not run.");
        }

        $enabling = $agent->enablingUser;
        if ($enabling === null) {
            throw new RuntimeException(
                "Agent [{$agent->key}] names no enabling user. An agent borrows a human's authority and has none of its own, "
                .'so one must be set before it can run.'
            );
        }

        $business = $request->businessId === null ? null : Business::find($request->businessId);
        $correlationId = (string) Str::uuid();
        $catalogue = $this->toolset->catalogueFor($agent, $request->businessId);

        $bundle = $this->assembler->open($agent, $request->question, $business, $catalogue);
        foreach ($request->extraContext as $block) {
            if ($block instanceof ContextBlock) {
                $bundle = $bundle->with($block);
            }
        }

        $decision = $this->resolveAutonomy($agent, $request, $bundle);

        $run = AgentRun::create([
            'uuid' => (string) Str::uuid(),
            'agent_id' => $agent->id,
            'agent_key' => $agent->key,
            'correlation_id' => $correlationId,
            'trigger' => $request->trigger,
            'triggered_by_user_id' => $request->operator?->getKey(),
            'business_id' => $request->businessId,
            'brand_id' => $request->brandId,
            'question' => $request->question,
            'autonomy_level' => $decision->level->value,
            'autonomy_reason' => $decision->boundBy,
            'autonomy_constraints' => $decision->constraints,
            // Derived, never passed in: shadow IS "the resolved level does not permit acting".
            'shadow' => ! $decision->level->allowsExecution(),
            'status' => 'running',
            'prompt_version' => $this->promptVersion($agent),
            'started_at' => now(),
        ]);

        $startedAt = microtime(true);

        try {
            $this->loop($run, $agent, $enabling, $request, $bundle, $decision, $correlationId);
        } catch (Throwable $e) {
            Log::error('agent run failed', ['agent' => $agent->key, 'run' => $run->id, 'exception' => $e]);

            $run->forceFill([
                'status' => 'failed',
                'stop_reason' => 'model_error',
                'error' => get_class($e).': '.mb_substr($e->getMessage(), 0, 900),
            ])->save();
        }

        $run->forceFill([
            'finished_at' => now(),
            'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            'context_provenance' => $bundle->toAuditArray(),
        ])->save();

        return $run->refresh();
    }

    private function loop(
        AgentRun $run,
        Agent $agent,
        User $enabling,
        AgentRunRequest $request,
        ContextBundle $bundle,
        AutonomyDecision $decision,
        string $correlationId,
    ): void {
        $messages = [['role' => 'user', 'content' => $bundle->render()]];
        $evidence = [];
        $proposals = [];
        $denials = [];
        $toolCalls = 0;

        for ($turn = 1; $turn <= self::MAX_TURNS; $turn++) {
            $outcome = $this->models->run(
                task: $request->task,
                system: $this->assembler->system($agent),
                messages: $messages,
                context: new RoutingContext(
                    businessId: $request->businessId,
                    brandId: $request->brandId,
                    agentKey: $agent->key,
                    correlationId: $correlationId,
                    inputRef: "agent_run:{$run->id}",
                ),
                jsonSchema: AgentResponseSchema::schema(),
                maxOutputTokens: self::MAX_OUTPUT_TOKENS,
                promptVersion: $run->prompt_version,
            );

            $reply = $outcome->data ?? [];

            $run->forceFill([
                'turns' => $turn,
                'model' => $outcome->model->model_id,
                'model_tier' => $outcome->model->tier,
            ])->save();

            $status = (string) ($reply['status'] ?? AgentResponseSchema::STATUS_ANSWER);

            if ($status === AgentResponseSchema::STATUS_REFUSE) {
                $this->finish($run, 'refused', null, $evidence, $proposals, (string) ($reply['refusal_reason'] ?? ''), $toolCalls);

                return;
            }

            if ($status === AgentResponseSchema::STATUS_ANSWER) {
                $evidence = [...$evidence, ...$this->citedFacts($reply)];
                $this->finish($run, 'answered', $this->compose($reply), $evidence, $proposals, null, $toolCalls);

                return;
            }

            $requested = $this->proposedCalls($reply);

            // A turn that asks for evidence and names nothing cannot make progress, and repeating it
            // would burn the remaining turns to arrive at the same place.
            if ($requested === []) {
                $this->finish($run, 'stopped', $this->compose($reply), $evidence, $proposals, null, $toolCalls, 'no_evidence');

                return;
            }

            $results = [];

            foreach ($requested as $call) {
                if ($toolCalls >= self::MAX_TOOL_CALLS) {
                    $this->finish($run, 'stopped', $this->compose($reply), $evidence, $proposals, null, $toolCalls, 'loop_bound');

                    return;
                }

                $toolCalls++;
                [$block, $record, $denied] = $this->invoke($agent, $enabling, $request, $call, $decision, $correlationId, $run);

                $bundle = $bundle->with($block);
                $results[] = $block->render();

                if ($record !== null) {
                    if (($record['kind'] ?? '') === 'evidence') {
                        $evidence[] = $record;
                    } else {
                        $proposals[] = $record;
                    }
                }

                if ($denied !== null) {
                    $denials[$denied] = ($denials[$denied] ?? 0) + 1;

                    if ($denials[$denied] >= self::DENIAL_LIMIT) {
                        // Either a broken prompt or an injection attempt. Both need a person.
                        $this->finish(
                            $run, 'stopped', null, $evidence, $proposals,
                            "The run was stopped: [{$denied}] was refused twice in one run and asked for again. "
                            .'A repeated attempt at a refused action is not something to keep answering.',
                            $toolCalls, 'repeated_denial',
                        );

                        return;
                    }
                }
            }

            $messages[] = ['role' => 'assistant', 'content' => (string) json_encode($reply, JSON_UNESCAPED_UNICODE)];
            $messages[] = ['role' => 'user', 'content' => "Results:\n\n".implode("\n\n", $results)];

            $run->forceFill(['tool_calls_count' => $toolCalls])->save();
        }

        $this->finish($run, 'stopped', null, $evidence, $proposals,
            'The run reached its limit of '.self::MAX_TURNS.' model turns without producing an answer.',
            $toolCalls, 'loop_bound');
    }

    /**
     * One proposed call, put to the gateway.
     *
     * @return array{0: ContextBlock, 1: array<string, mixed>|null, 2: string|null}
     */
    private function invoke(
        Agent $agent,
        User $enabling,
        AgentRunRequest $request,
        array $call,
        AutonomyDecision $decision,
        string $correlationId,
        AgentRun $run,
    ): array {
        $key = (string) ($call['tool'] ?? '');
        $args = is_array($call['args'] ?? null) ? $call['args'] : [];

        // A tool the agent may not name at all: answered here, because there is nothing for the
        // gateway to decide about a key that does not exist.
        if (($refusal = $this->toolset->refusalFor($agent, $key, $request->businessId)) !== null) {
            return [
                $this->assembler->toolRefusal($key === '' ? 'unnamed' : $key, $refusal),
                ['kind' => 'refused', 'tool' => $key, 'reason' => $refusal, 'why' => $call['why'] ?? null],
                $key,
            ];
        }

        $descriptor = $this->registry->get($key)->descriptor();

        // The autonomy rule, made structural. Unreachable in Phase 2 because neither agent holds a
        // tool with an effect; written now so the first one that does meets a rule, not an absence.
        if ($this->hasEffect($descriptor) && ! $decision->level->allowsExecution()) {
            $reason = "[{$key}] would change something outside this conversation, and this run is at autonomy "
                .strtoupper($decision->level->value).' ('.$decision->boundBy.'). '
                .'It is recorded as a proposal for a human to carry out; it has not run.';

            return [
                $this->assembler->toolRefusal($key, $reason),
                ['kind' => 'proposal', 'tool' => $key, 'args' => $args, 'why' => $call['why'] ?? null, 'reason' => $reason],
                null, // not a denial: the agent did the right thing by proposing it
            ];
        }

        $outcome = $this->gateway->execute(new ToolRequest(
            toolKey: $key,
            args: $args,
            caller: CallerKind::Agent,
            identity: ActingIdentity::agent($agent, $enabling),
            correlationId: $correlationId,
            businessId: $request->businessId,
            brandId: $request->brandId,
        ));

        if ($outcome->status === OutcomeStatus::AwaitingApproval) {
            $reason = 'It is waiting for a human decision in the Approval Center. It has not run.';

            return [
                $this->assembler->toolRefusal($key, $reason),
                ['kind' => 'proposal', 'tool' => $key, 'args' => $args, 'approval_id' => $outcome->approvalId, 'reason' => $reason],
                null,
            ];
        }

        if (! $outcome->ok()) {
            $reason = $outcome->error ?? $outcome->policyReason ?? 'the call did not succeed';

            return [
                $this->assembler->toolRefusal($key, $reason),
                ['kind' => 'refused', 'tool' => $key, 'reason' => $reason, 'tool_call_id' => $outcome->toolCallId],
                // Only a POLICY refusal counts toward the repeated-denial stop. A tool that failed
                // because a shop was slow is not the model being told no, and treating it as one
                // would end runs for a reason the model cannot do anything about.
                $outcome->status === OutcomeStatus::Denied ? $key : null,
            ];
        }

        return [
            $this->assembler->toolResult($key, $outcome->toolCallId, $outcome->output),
            ['kind' => 'evidence', 'tool' => $key, 'tool_call_id' => $outcome->toolCallId, 'args' => $args],
            null,
        ];
    }

    /**
     * Whether running this tool changes anything beyond the conversation.
     *
     * Both halves matter: `readWrite` says what the tool does, and the tier says how far the effect
     * reaches. A tool that is R3 but declares itself a read, or a write that somebody tiered as R1,
     * is caught by the other half rather than slipping through on the strength of one field.
     */
    private function hasEffect(ToolDescriptor $descriptor): bool
    {
        return $descriptor->readWrite === ReadWrite::Write || $descriptor->riskTier->atLeast(RiskTier::R3);
    }

    private function resolveAutonomy(Agent $agent, AgentRunRequest $request, ContextBundle $bundle): AutonomyDecision
    {
        return $this->autonomy->resolve(new AutonomyRequest(
            agentKey: $agent->key,
            businessId: $request->businessId,
            brandId: $request->brandId,
            environment: (string) app()->environment(),
            context: $bundle,
            agentCeiling: $agent->max_autonomy_level,
        ));
    }

    /** @return list<array<string, mixed>> */
    private function proposedCalls(array $reply): array
    {
        $calls = $reply['tool_calls'] ?? [];

        if (! is_array($calls)) {
            return [];
        }

        $out = [];
        foreach ($calls as $call) {
            if (is_array($call) && is_string($call['tool'] ?? null) && $call['tool'] !== '') {
                $out[] = $call;
            }
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function citedFacts(array $reply): array
    {
        $facts = $reply['facts'] ?? [];

        if (! is_array($facts)) {
            return [];
        }

        $out = [];
        foreach ($facts as $fact) {
            if (! is_array($fact) || ! is_string($fact['statement'] ?? null)) {
                continue;
            }
            $out[] = [
                'kind' => 'claim',
                'statement' => $fact['statement'],
                'source' => is_string($fact['source'] ?? null) ? $fact['source'] : null,
                'as_of' => is_string($fact['as_of'] ?? null) ? $fact['as_of'] : null,
            ];
        }

        return $out;
    }

    /**
     * The answer as it is stored: the model's prose, then its inferences marked as inferences.
     *
     * Keeping them apart in storage as well as in the prompt is what lets a reviewer see at a glance
     * which sentences the systems said and which the model did.
     */
    private function compose(array $reply): ?string
    {
        $answer = trim((string) ($reply['answer'] ?? ''));
        $inferences = array_values(array_filter((array) ($reply['inferences'] ?? []), 'is_string'));

        if ($answer === '' && $inferences === []) {
            return null;
        }

        if ($inferences === []) {
            return $answer;
        }

        return trim($answer."\n\nMy reading of it, which is inference rather than fact:\n- ".implode("\n- ", $inferences));
    }

    private function finish(
        AgentRun $run,
        string $status,
        ?string $answer,
        array $evidence,
        array $proposals,
        ?string $note,
        int $toolCalls,
        ?string $stopReason = null,
    ): void {
        $run->forceFill([
            'status' => $status,
            'answer' => $answer,
            'error' => $status === 'refused' || $stopReason !== null ? $note : null,
            'evidence' => $evidence,
            'proposals' => $proposals,
            'tool_calls_count' => $toolCalls,
            'stop_reason' => $stopReason,
        ])->save();
    }

    private function promptVersion(Agent $agent): ?string
    {
        try {
            return $this->prompts->version($agent->role_key ?? $agent->key);
        } catch (Throwable) {
            return null;
        }
    }
}
