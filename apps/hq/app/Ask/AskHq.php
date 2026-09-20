<?php

namespace App\Ask;

use App\Agents\AgentRunner;
use App\Agents\AgentRunRequest;
use App\Execution\ExecutionContextRefused;
use App\Execution\ExecutionContextResolver;
use App\Execution\Requester;
use App\Models\Agent;
use App\Models\AskConversation;
use App\Models\AskMessage;
use App\Models\User;
use App\Support\Audit\Auditor;
use App\Support\Authorization\BusinessScope;
use App\Support\Features\Features;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Ask HQ (docs/48 K, docs/78 B.3): an operator's question put to an agent through the unchanged
 * `AgentRunner`, the same path `hq:agent:ask` takes.
 *
 * ⛔ It holds no authority of its own. It takes words, an agent from a fixed list and a business the
 * person may see; it never takes a tool key, arguments, a model or an autonomy level, so nothing a
 * browser sends can reach a tool except through the runner, the gateway and policy, under the run's
 * Effective Execution Context (ADR-047): what the person asking AND the agent's enabling user may
 * both do, inside the agent's own ceiling. While `ai.runtime` is off it asks nothing and says so.
 */
final class AskHq
{
    public const FLAG = 'ask_hq';

    public const MAX_QUESTION = 4000;

    public function __construct(
        private readonly Features $features,
        private readonly BusinessScope $scope,
        private readonly Auditor $auditor,
        private readonly ExecutionContextResolver $contexts,
    ) {}

    public function enabled(): bool
    {
        return $this->features->enabled(self::FLAG);
    }

    public function runtimeOn(): bool
    {
        return $this->features->enabled((string) config('hq.ai.enabled_flag', 'ai.runtime'));
    }

    /** @return list<string> the agents an operator may put a question to; the support agent answers customers, not operators */
    public function askableAgents(): array
    {
        return array_values((array) config('hq.ask.agents', ['executive', 'analytics']));
    }

    /**
     * Why this person may not put questions to this agent, or null when they may.
     *
     * ⭐ ADR-047 replaced the rule this method used to hold. Until the run itself narrowed to the
     * person asking, Ask HQ was open only to group-wide operators holding every permission the
     * agent's tools need (docs/78 C.3). The run now IS narrowed, by the one resolver every entry
     * point shares, so a restricted operator may ask and is answered from the intersection only.
     * What is left here is the resolver's own answer, asked before the question is accepted: a
     * refusal when the person and the agent's enabling user have nothing in common for the agent
     * to work with (no shared business, none of its tools' permissions), in the resolver's words.
     * This surface decides nothing about authority itself.
     */
    public function refusalFor(User $user, string $agentKey, ?int $businessId = null): ?string
    {
        $agent = Agent::query()->where('key', $agentKey)->first();
        if ($agent === null) {
            return "There is no agent with the key [{$agentKey}].";
        }

        try {
            $this->contexts->preview($agent, Requester::human($user, 'ask: the signed-in person'), 'operator', $businessId);
        } catch (ExecutionContextRefused $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * Records the question and either queues the answer or records why nothing was asked.
     *
     * @return array{conversation: AskConversation, message: AskMessage, reply: AskMessage}
     */
    public function submit(User $user, ?AskConversation $conversation, string $question, string $agentKey, ?int $businessId): array
    {
        $question = trim($question);

        if (! $this->enabled()) {
            throw new InvalidArgumentException('Ask HQ is switched off (flag ask_hq).');
        }
        if ($question === '' || mb_strlen($question) > self::MAX_QUESTION) {
            throw new InvalidArgumentException('A question is between 1 and '.self::MAX_QUESTION.' characters.');
        }
        if (! in_array($agentKey, $this->askableAgents(), true)) {
            throw new InvalidArgumentException("[{$agentKey}] is not an agent Ask HQ puts questions to.");
        }
        if ($conversation !== null && (int) $conversation->user_id !== (int) $user->getKey()) {
            throw new InvalidArgumentException('That conversation is not yours.');
        }
        if (! $this->scope->allows($user, $businessId)) {
            throw new InvalidArgumentException('You cannot ask about that business.');
        }
        if (($refusal = $this->refusalFor($user, $agentKey, $businessId)) !== null) {
            throw new InvalidArgumentException($refusal);
        }

        $runtimeOn = $this->runtimeOn();

        [$conversation, $message, $reply] = DB::transaction(function () use ($user, $conversation, $question, $agentKey, $businessId, $runtimeOn) {
            $now = CarbonImmutable::now();
            $conversation ??= AskConversation::create([
                'uuid' => (string) Str::uuid(),
                'user_id' => $user->getKey(),
                'agent_key' => $agentKey,
                'business_id' => $businessId,
                'title' => Str::limit(preg_replace('/\s+/u', ' ', $question) ?? $question, 80, '...'),
                'status' => 'open',
                'last_activity_at' => $now,
            ]);
            $conversation->forceFill(['last_activity_at' => $now, 'agent_key' => $agentKey, 'business_id' => $businessId])->save();

            $message = $conversation->messages()->create([
                'role' => AskMessage::ROLE_USER,
                'content' => $question,
                'status' => AskMessage::DONE,
                'structured' => ['agent_key' => $agentKey, 'business_id' => $businessId],
            ]);

            $reply = $runtimeOn
                ? $conversation->messages()->create([
                    'role' => AskMessage::ROLE_ASSISTANT,
                    'content' => '',
                    'status' => AskMessage::PENDING,
                    'structured' => ['agent_key' => $agentKey, 'question_id' => $message->id],
                ])
                : $conversation->messages()->create([
                    'role' => AskMessage::ROLE_SYSTEM,
                    'content' => 'Nothing was asked: HQ\'s AI runtime is switched off, so no agent can answer yet.',
                    'status' => AskMessage::REFUSED,
                    'structured' => ['reason' => 'ai_runtime_off', 'question_id' => $message->id],
                ]);

            return [$conversation, $message, $reply];
        });

        $this->auditor->record($runtimeOn ? 'ask.submitted' : 'ask.refused', $message, after: [
            'conversation' => $conversation->uuid,
            'agent_key' => $agentKey,
            'business_id' => $businessId,
            'question_length' => mb_strlen($question),
            'reason' => $runtimeOn ? null : 'ai_runtime_off',
        ]);

        if ($runtimeOn) {
            AnswerAskTurn::dispatch($reply->id);
        }

        return ['conversation' => $conversation, 'message' => $message, 'reply' => $reply];
    }

    /** Runs the agent for a pending reply. Called by the queued job; safe to call once per reply. */
    public function answer(AskMessage $reply): AskMessage
    {
        if ($reply->status !== AskMessage::PENDING || $reply->role !== AskMessage::ROLE_ASSISTANT) {
            return $reply;
        }

        $conversation = $reply->conversation()->with('user')->firstOrFail();
        $question = AskMessage::query()->findOrFail((int) ($reply->structured['question_id'] ?? 0));
        $agentKey = (string) ($reply->structured['agent_key'] ?? $conversation->agent_key);

        // The switch is read again at the moment of asking: it may have been turned off since.
        if (! $this->runtimeOn()) {
            $reply->forceFill([
                'role' => AskMessage::ROLE_SYSTEM,
                'status' => AskMessage::REFUSED,
                'content' => 'Nothing was asked: HQ\'s AI runtime was switched off before the question reached the agent.',
                'structured' => ($reply->structured ?? []) + ['reason' => 'ai_runtime_off'],
            ])->save();

            return $reply;
        }

        try {
            $run = app(AgentRunner::class)->run(new AgentRunRequest(
                agentKey: $agentKey,
                question: $question->content,
                trigger: 'operator',
                operator: $conversation->user,
                businessId: $conversation->business_id,
                // The person who asked is a TERM of the run's authority, read again now: they may
                // have been disabled, or lost a business, since the question was accepted.
                requester: Requester::human($conversation->user, "ask_conversations#{$conversation->id}.user_id"),
            ));
        } catch (Throwable $e) {
            // A refused execution context (agent paused, no enabling user, a person disabled, nothing
            // in common) is one of HQ's own sentences and safe to show; anything else is named by
            // its class only.
            $safe = $e instanceof ExecutionContextRefused;
            $reply->forceFill([
                'status' => AskMessage::FAILED,
                'content' => $safe ? $e->getMessage() : 'The agent could not answer ('.class_basename($e).').',
                'structured' => ($reply->structured ?? []) + ['error_class' => get_class($e)],
            ])->save();

            return $reply;
        }

        $reply->forceFill([
            'status' => AskMessage::DONE,
            'content' => (string) ($run->answer ?? $run->error ?? ''),
            'agent_run_id' => $run->id,
            'correlation_id' => $run->correlation_id,
            'structured' => ($reply->structured ?? []) + [
                'run_uuid' => $run->uuid,
                'run_status' => $run->status,
                'shadow' => (bool) $run->shadow,
                'autonomy_level' => $run->autonomy_level?->value,
                'autonomy_reason' => $run->autonomy_reason,
                'stop_reason' => $run->stop_reason,
                'tool_calls' => (int) $run->tool_calls_count,
            ],
        ])->save();

        return $reply;
    }
}
