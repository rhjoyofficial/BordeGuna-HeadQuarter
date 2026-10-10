<?php

namespace App\Conversation\WakeUp;

use App\Agents\AgentRunner;
use App\Agents\AgentRunRequest;
use App\Ai\TaskType;
use App\Conversation\ConversationService;
use App\Conversation\ConversationState;
use App\Conversation\HandoffStateMachine;
use App\Conversation\HandoverReason;
use App\Conversation\InvalidTransition;
use App\Conversation\SupportContext;
use App\Conversation\SupportWorkflow;
use App\Execution\Requester;
use App\Execution\RequesterKind;
use App\Gateway\OutcomeStatus;
use App\Http\Controllers\Webhooks\MetaWebhookController;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ToolCall;
use App\Support\Audit\Auditor;
use App\Support\Events\EventRecorder;
use App\Support\Features\Features;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The live wake-up of the Support agent (docs/95 §5 "Customer AI", §12, §16 item 4; stream W2a).
 *
 * `ConversationService::ingest()` records a customer message and emits `conversation.message_received`.
 * The drain hands that event to `SupportWakeUpHandler`, which asks this class whether the agent may be
 * woken for the thread and, when it may, queues `WakeSupportAgent` on the `ai` queue. The job runs the
 * Support agent BOUND to the thread, exactly as gate P3-G2 runs it (`AgentEvaluationSubject`): the same
 * `SupportContext` blocks, the same `SupportWorkflow::decide()` before the model, the requester as the
 * agent's enabling user (`Requester::asEnabled`), trigger `conversation`, at the agent's resolved
 * autonomy. At A0, which is every agent today, the reply is a proposal in the inbox and nothing executes.
 *
 * ⛔ Inert by posture, and every precondition is read fresh from the database at the moment of asking,
 * twice: when the event is handled and again when the job runs, because a flag or an agent row can
 * change between the two. ALL of these must hold, or the wake-up does nothing and audits why
 * (`conversation.wake_up_skipped`, with the reason and no customer content):
 *
 *   - `channels.meta.inbound` is on (production: OFF);
 *   - `ai.runtime` is on (production: OFF);
 *   - the `support` agent row is `active` and names an enabling user (production: paused, nobody);
 *   - the thread is the agent's: `ai_active` or `ai_resumed`, and not `ai_disabled`;
 *   - the message is an inbound customer message on that thread.
 *
 * ⛔ One run per inbound message. `agent_runs.conversation_message_uuid` is the durable key (a run that
 * exists for the message is the answer, whatever redelivered the event or the job), and a cache lock on
 * the message keeps two workers from racing to be the first.
 *
 * What happens to the thread afterwards is the state machine's, decided here and audited there:
 *   - the run proposed a reply: `conversation.reply_proposed` is emitted with the run, and the thread
 *     moves `ai_active` -> `ai_waiting`, or `ai_resumed` -> `ai_active` -> `ai_waiting` (docs/14 table;
 *     confirmed lifecycle, docs/101 §11 ruling 9.3) (docs/72 §B: the agent answered and waits; a second
 *     customer message while it waits reaches a person, by the core's existing rule);
 *   - the reply EXECUTED (A2 and above, verified against the gateway's `tool_calls` row; unreachable while
 *     ADR-024 caps customer-reading runs at A1): `conversation.reply_sent`, and the thread waits on the
 *     customer as after a proposal (docs/105, ruling docs/103 §3);
 *   - the run proposed nothing (a silent hand-over the runtime decided before the model, a refusal, a
 *     failure, an answer with no reply, a reply whose delivery is uncertain): the thread moves to
 *     `human_required` with reason `agent_cannot_serve`, so a customer is never left in silence with nobody told.
 *
 * ⚠ A silent hand-over is declared here, by HQ code, from the runtime's own classifier
 * (`SupportWorkflow::recommend`): the thread goes to a person with zero model turns, the run records the
 * verdict as the gate's would. `greeting_first` is NOT declared from the classifier (docs/91 A.2: the
 * classifier only recommends); a live greeting runs `context_first` with the recommendation on the record.
 */
final class SupportWakeUp
{
    /** The value on `agent_runs.trigger` for a run woken by a customer message. */
    public const TRIGGER = 'conversation';

    /** The question the gate puts to the Support agent on a thread (`SupportBehaviourSuiteSeeder`), word for word. */
    public const QUESTION = 'Answer the customer on this thread.';

    public const SKIP_META_INBOUND_OFF = 'channels.meta.inbound is off';

    public const SKIP_RUNTIME_OFF = 'ai.runtime is off';

    public const SKIP_AGENT_NOT_ACTIVE = 'the support agent is not active';

    public const SKIP_NO_ENABLING_USER = 'the support agent names no enabling user';

    public const SKIP_THREAD_NOT_AGENTS = 'the thread is not the agent\'s';

    public const SKIP_AI_DISABLED = 'the agent is switched off for this thread';

    public const SKIP_NOT_INBOUND = 'the message is not an inbound customer message on this thread';

    public const SKIP_ALREADY_RUN = 'a run already exists for this message';

    public const SKIP_IN_FLIGHT = 'a run for this message is in flight';

    public const SKIP_EXECUTION_REFUSED = 'the execution context was refused';

    private const LOCK_SECONDS = 600;

    public function __construct(
        private readonly Features $features,
        private readonly AgentRunner $runner,
        private readonly SupportContext $support,
        private readonly SupportWorkflow $workflow,
        private readonly HandoffStateMachine $states,
        private readonly EventRecorder $events,
        private readonly Auditor $auditor,
    ) {}

    /**
     * Why the agent may NOT be woken for this message right now, or null when every precondition holds.
     * Read fresh, never cached, in the order a reviewer would ask: the switches, the agent, the thread,
     * the message.
     */
    public function refusal(Conversation $conversation, ?ConversationMessage $message): ?string
    {
        // A message that already has its run is answered, whatever the thread's state is now: a redelivery
        // after the run moved the thread must not be reported as anything but what it is.
        if ($message !== null && $this->existingRun((string) $message->uuid) !== null) {
            return self::SKIP_ALREADY_RUN;
        }

        if (! $this->features->enabled(MetaWebhookController::FLAG, $conversation->business_id)) {
            return self::SKIP_META_INBOUND_OFF;
        }
        if (! $this->features->enabled((string) config('hq.ai.enabled_flag', 'ai.runtime'), $conversation->business_id)) {
            return self::SKIP_RUNTIME_OFF;
        }

        $agent = Agent::query()->where('key', ConversationService::SUPPORT_AGENT)->first(['id', 'key', 'status', 'enabling_user_id']);
        if ($agent === null || ! $agent->isActive()) {
            return self::SKIP_AGENT_NOT_ACTIVE;
        }
        if ($agent->enabling_user_id === null) {
            return self::SKIP_NO_ENABLING_USER;
        }

        if ($conversation->ai_disabled) {
            return self::SKIP_AI_DISABLED;
        }
        if (! in_array($conversation->state, [ConversationState::AiActive, ConversationState::AiResumed], true)) {
            return self::SKIP_THREAD_NOT_AGENTS;
        }

        if ($message === null || $message->direction !== 'inbound' || (int) $message->conversation_id !== (int) $conversation->id) {
            return self::SKIP_NOT_INBOUND;
        }

        return null;
    }

    /** Record that the wake-up did nothing, and why. The customer's words are never on the row. */
    public function skipped(Conversation $conversation, ?string $messageUuid, string $reason, string $stage): void
    {
        $this->auditor->recordSystem('conversation.wake_up_skipped', $conversation, after: [
            'reason' => $reason,
            'stage' => $stage,
            'state' => $conversation->state->value,
            'message_uuid' => $messageUuid,
            'business_id' => $conversation->business_id,
        ], reason: "the support agent was not woken: {$reason}");
    }

    /**
     * Run the agent for one inbound message, once. Null when the wake-up did nothing (and audited why).
     */
    public function run(string $conversationUuid, string $messageUuid): ?AgentRun
    {
        $conversation = Conversation::query()->where('uuid', $conversationUuid)->first();
        if ($conversation === null) {
            Log::warning('wake-up: the thread no longer exists', ['conversation' => $conversationUuid]);

            return null;
        }
        $message = ConversationMessage::query()->where('uuid', $messageUuid)->first();

        if (($refusal = $this->refusal($conversation, $message)) !== null) {
            $this->skipped($conversation, $messageUuid, $refusal, 'job');

            return $refusal === self::SKIP_ALREADY_RUN ? $this->existingRun($messageUuid) : null;
        }

        $lock = Cache::lock("hq:wake-up:{$messageUuid}", self::LOCK_SECONDS);
        if (! $lock->get()) {
            $this->skipped($conversation, $messageUuid, self::SKIP_IN_FLIGHT, 'job');

            return null;
        }

        try {
            if (($existing = $this->existingRun($messageUuid)) !== null) {
                $this->skipped($conversation, $messageUuid, self::SKIP_ALREADY_RUN, 'job');

                return $existing;
            }

            return $this->wake($conversation, $message);
        } finally {
            $lock->release();
        }
    }

    private function existingRun(string $messageUuid): ?AgentRun
    {
        return AgentRun::query()
            ->where('trigger', self::TRIGGER)
            ->where('conversation_message_uuid', $messageUuid)
            ->orderBy('id')
            ->first();
    }

    private function wake(Conversation $conversation, ConversationMessage $message): ?AgentRun
    {
        $blocks = $this->support->blocksFor($conversation);

        // The runtime's own reading of the latest customer message. A silent hand-over is declared from it
        // (a hard safety route, docs/90 A.1 step 5); anything else is left undeclared and recorded.
        $recommended = $this->workflow->recommend($this->latestCustomerText($message));
        $declared = ($recommended['workflow'] ?? null) === SupportWorkflow::SILENT_HANDOVER ? SupportWorkflow::SILENT_HANDOVER : null;

        try {
            $run = $this->runner->run(new AgentRunRequest(
                agentKey: ConversationService::SUPPORT_AGENT,
                question: self::QUESTION,
                trigger: self::TRIGGER,
                businessId: (int) $conversation->business_id,
                brandId: $conversation->brand_id === null ? null : (int) $conversation->brand_id,
                task: TaskType::OperatorQuestion,
                extraContext: $blocks,
                requester: Requester::asEnabled(RequesterKind::OnBehalfOf, 'conversation wake-up: a customer wrote; the agent answers as its enabling user enabled it'),
                conversationUuid: (string) $conversation->uuid,
                supportWorkflow: $declared,
                conversationMessageUuid: (string) $message->uuid,
            ));
        } catch (Throwable $e) {
            // The resolver refused the run (the agent paused or lost its enabler between the event and
            // the job, a person narrowed): nothing ran, and the customer still needs somebody.
            Log::warning('wake-up: the run could not start', ['conversation' => $conversation->uuid, 'exception' => get_class($e), 'error' => $e->getMessage()]);
            $this->skipped($conversation, (string) $message->uuid, self::SKIP_EXECUTION_REFUSED.': '.get_class($e), 'job');
            $this->handToPerson($conversation->fresh(), 'the run could not start ('.class_basename($e).')');

            return null;
        }

        $this->settle($conversation->fresh(), $message, $run);

        return $run;
    }

    /**
     * The thread's next state follows from what the run did, and is the state machine's to record.
     *
     * Three outcomes, in this order (docs/101 §11 ruling 9.3; §12 Q4 and docs/103 §3, stream S1):
     *
     *   1. The reply was EXECUTED (A2 and above): the runner wrote an `evidence` record carrying the gateway's
     *      `tool_call_id`, never a proposal. Verified against the gateway's own `tool_calls` row (the reply tool,
     *      `succeeded` or `replayed`, its validated input naming THIS thread); the record alone is never trusted.
     *      The thread was answered: `conversation.reply_sent`, then `ai_waiting`, as after a proposal.
     *   2. A valid PROPOSAL: `conversation.reply_proposed`, then `ai_waiting` (unchanged).
     *   3. Neither: to a person as `agent_cannot_serve`. A reply the gateway reports `uncertain` (it may have
     *      reached the customer) is said so on the hand-over, naming the tool call.
     *
     * ⛔ Branch 1 is unreachable through `run()` today and the correction does not change that: ADR-024 caps
     * every run whose context holds a customer's words at A1, and only A2 and above execute. It is the
     * precondition the ruling set for ever lifting that cap, and is proven by calling this method directly.
     */
    private function settle(Conversation $conversation, ConversationMessage $message, AgentRun $run): void
    {
        $records = collect((array) $run->proposals)->filter(fn ($p) => is_array($p) && ($p['tool'] ?? null) === SupportWorkflow::REPLY_TOOL);

        $executed = $this->replyCall($conversation, $records->where('kind', 'evidence'), [OutcomeStatus::Succeeded, OutcomeStatus::Replayed]);
        $proposal = $records->first(fn (array $p) => ($p['kind'] ?? null) === 'proposal');

        if ($executed !== null || $proposal !== null) {
            $this->events->record(
                type: $executed !== null ? 'conversation.reply_sent' : 'conversation.reply_proposed',
                payload: [
                    'conversation_uuid' => $conversation->uuid,
                    'message_uuid' => $message->uuid,
                    'run_uuid' => $run->uuid,
                    'run_id' => $run->id,
                    'autonomy_level' => $run->autonomy_level->value,
                    'shadow' => (bool) $run->shadow,
                ] + ($executed !== null
                    ? ['tool_call_id' => $executed->id, 'tool_call_status' => $executed->status]
                    : ['approval_id' => $proposal['approval_id'] ?? null]),
                source: 'hq:conversation',
                direction: 'outbound',
                aggregateType: 'conversation',
                aggregateId: (string) $conversation->uuid,
                businessId: (int) $conversation->business_id,
                dedupeKey: ($executed !== null ? 'conversation:reply_sent:' : 'conversation:reply_proposed:').$message->uuid,
            );

            // docs/14: AI_RESUMED -> AI_ACTIVE. A thread a person handed back is the agent's again once
            // it has answered, and only then waits on the customer (ruling 9.3). Without this step the
            // table refused ai_resumed -> ai_waiting and a valid proposal left the thread in ai_resumed.
            if ($conversation->state === ConversationState::AiResumed) {
                $conversation = $this->states->transition($conversation, ConversationState::AiActive, null,
                    'the agent took the handed-back thread (run '.$run->uuid.')', ConversationService::SUPPORT_AGENT);
            }

            if ($conversation->state->canMoveTo(ConversationState::AiWaiting)) {
                $this->states->transition($conversation, ConversationState::AiWaiting, null,
                    $executed !== null
                        ? 'the agent sent its reply and waits (run '.$run->uuid.', tool call #'.$executed->id.')'
                        : 'the agent proposed a reply and waits (run '.$run->uuid.')',
                    ConversationService::SUPPORT_AGENT);
            }

            return;
        }

        $workflow = (array) ($run->support_workflow ?? []);
        $uncertain = $this->replyCall($conversation, $records->whereIn('kind', ['evidence', 'failed']), [OutcomeStatus::Uncertain]);
        $what = match (true) {
            $uncertain !== null => 'a reply was attempted and its delivery is uncertain (tool call #'.$uncertain->id.'), so a person must check what the customer received',
            ($workflow['workflow'] ?? null) === SupportWorkflow::SILENT_HANDOVER => 'a silent hand-over the runtime decided before the model ('.($workflow['kind'] ?? 'unknown').')',
            default => 'the run ended '.$run->status.($run->stop_reason ? ' ('.$run->stop_reason.')' : '').' with no reply proposed',
        };

        $this->handToPerson($conversation, $what.'; run '.$run->uuid);
    }

    /**
     * The gateway's own row for a reply call one of these records names, when it is the reply tool, has one of
     * the given statuses and was made for THIS thread; otherwise null. This is the verification: a record in
     * `agent_runs.proposals` says what the runner saw, the `tool_calls` row is what the gateway did.
     *
     * @param  Collection<int, array<string, mixed>>  $records
     * @param  list<OutcomeStatus>  $statuses
     */
    private function replyCall(Conversation $conversation, Collection $records, array $statuses): ?ToolCall
    {
        $ids = $records->pluck('tool_call_id')->filter(fn ($id) => is_int($id) || (is_string($id) && ctype_digit($id)))->map(fn ($id) => (int) $id)->values()->all();
        if ($ids === []) {
            return null;
        }

        return ToolCall::query()
            ->whereIn('id', $ids)
            ->where('tool_key', SupportWorkflow::REPLY_TOOL)
            ->whereIn('status', array_map(fn (OutcomeStatus $s) => $s->value, $statuses))
            ->orderBy('id')
            ->get()
            ->first(fn (ToolCall $call) => (string) data_get($call->input, 'conversation_uuid') === (string) $conversation->uuid);
    }

    private function handToPerson(Conversation $conversation, string $what): void
    {
        if (! $conversation->state->canMoveTo(ConversationState::HumanRequired)) {
            return;
        }

        try {
            $this->states->transition($conversation, ConversationState::HumanRequired, null, HandoverReason::CANNOT_SERVE_PREFIX.': '.$what);
        } catch (InvalidTransition $e) {
            Log::warning('wake-up: hand-over refused', ['conversation' => $conversation->uuid, 'error' => $e->getMessage()]);
        }
    }

    /** The text the classifier reads: the message that woke the run, as the core recorded it. */
    private function latestCustomerText(ConversationMessage $message): string
    {
        $body = trim((string) $message->body);
        if ($body !== '') {
            return $body;
        }
        $attachments = count((array) data_get($message->metadata, 'attachments', []));

        return "[the customer sent {$attachments} attachment(s) and no text]";
    }
}
