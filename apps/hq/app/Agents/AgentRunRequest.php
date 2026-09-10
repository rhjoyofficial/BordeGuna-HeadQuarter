<?php

namespace App\Agents;

use App\Ai\TaskType;
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
        public string $trigger = 'operator',
        public ?User $operator = null,
        public ?int $businessId = null,
        public ?int $brandId = null,
        public TaskType $task = TaskType::OperatorQuestion,
        /** Set by the evaluation subject so a corpus case can supply its own labelled context. */
        public array $extraContext = [],
    ) {}
}
