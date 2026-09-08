<?php

namespace App\Ai;

/**
 * Everything the router is allowed to consider when choosing a model.
 *
 * ⚠ Note what is NOT here: a model id, a provider, or a temperature. A caller that wants a specific
 * model is a caller that has taken the choice away from the one component able to account for it,
 * and cost accounting is only as good as the router's monopoly on selection (ADR-019).
 */
final readonly class RoutingContext
{
    /**
     * @param  list<string>  $requiredCapabilities  e.g. ['json_schema'], ['tool_use'], ['vision']
     * @param  int  $minContextTokens  the prompt will not fit in a model smaller than this
     * @param  string|null  $sensitivity  reserved for Phase 3: routing away from providers by data class
     */
    public function __construct(
        public ?int $businessId = null,
        public ?int $brandId = null,
        public ?string $agentKey = null,
        public ?string $correlationId = null,
        public array $requiredCapabilities = [],
        public int $minContextTokens = 0,
        public ?int $latencyBudgetMs = null,
        public ?string $sensitivity = null,
        /** Overrides the task's own answer when the caller knows better; null means ask the task. */
        public ?bool $degradable = null,
        /** What caused this call: a job run, an approval, a conversation turn. Free-form by design. */
        public ?string $inputRef = null,
    ) {}

    public function degradableFor(TaskType $task): bool
    {
        // A caller may only make a task LESS degradable, never more. Turning a non-degradable task
        // into a degradable one from a call site would quietly undo the reasoning in TaskType.
        return $this->degradable === false ? false : $task->degradable();
    }
}
