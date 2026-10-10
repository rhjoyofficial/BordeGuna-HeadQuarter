<?php

namespace App\Agents;

use App\Ai\TaskType;
use App\Execution\Requester;
use App\Models\User;

/**
 * One question put to one agent.
 *
 * ⚠ There is no field here for a tool key, a model id, an autonomy level or a prompt. A caller
 * asking a question does not get to choose what the agent may do, what it costs, or how much of it
 * happens without a human - those come from the agent row, the router and the autonomy plane. A
 * request object that carried them would be a way to hand the browser a control-plane decision.
 */
final readonly class AgentRunRequest
{
    public function __construct(
        public string $agentKey,
        public string $question,
        /**
         * The entry point, as a label on the run row. The values HQ writes today: `operator` (Ask HQ),
         * `console` (`hq:agent:ask`), `evaluation` (a gate), `schedule` (a scheduled job), `shadow_probe`
         * (`ShadowProbes::TRIGGER`) and `conversation` (the live wake-up, `SupportWakeUp::TRIGGER`).
         */
        public string $trigger = 'operator',
        public ?User $operator = null,
        public ?int $businessId = null,
        public ?int $brandId = null,
        public TaskType $task = TaskType::OperatorQuestion,
        /** Set by the evaluation subject so a corpus case can supply its own labelled context. */
        public array $extraContext = [],
        /**
         * Who is asking (ADR-047). `operator` above is a label on the row; THIS is a term of the
         * run's authority, and a request that names none is refused: there is no default requester.
         */
        public ?Requester $requester = null,
        /**
         * docs/90 A.1 step 3: the conversation this run was woken on, set by the code that woke it (the
         * P3-G2 subject, a future inbox wake-up) and never by the model. Every Support tool that names
         * a conversation is bound to it by the runtime: the model cannot switch threads, read another
         * customer's thread, reply elsewhere, or leave the thread out and let a tool guess.
         */
        public ?string $conversationUuid = null,
        /**
         * docs/90 A.1 step 5: the workflow the thread's contract declares (`SupportWorkflow::WORKFLOWS`),
         * from HQ code. The runtime's own reading still wins for a silent hand-over.
         */
        public ?string $supportWorkflow = null,
        /**
         * docs/95 stream W2a: the inbound customer message that woke this run, set by the live wake-up
         * (`SupportWakeUp`) and never by the model. One run per message is the live path's idempotency
         * rule, and this is the key it is kept on.
         */
        public ?string $conversationMessageUuid = null,
        /**
         * docs/116 (ruling 110 §1c): the `correlation_id` this run and every one of its `ai_runs` rows will
         * carry, when the CALLER owns the run's accounting and must keep the key even if `run()` throws (the
         * evaluation subject: a case's model calls are billed under it whether or not an `AgentRun` comes
         * back). Null means the runner mints one, as every other entry point lets it. An evidence key, not a
         * control-plane decision: it chooses nothing about tools, model, autonomy or prompt, and it is never
         * taken from the model or a browser.
         */
        public ?string $correlationId = null,
    ) {}
}
