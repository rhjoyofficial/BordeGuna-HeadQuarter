<?php

namespace App\Ai;

use App\Ai\ToolCalls\ProposedToolCall;
use App\Ai\ToolCalls\ToolCallNormaliser;
use App\Models\AiModel;
use App\Models\AiRun;

/**
 * What a model call produced, and the record of what it cost.
 *
 * ⚠ `degraded` is exposed rather than buried because output that ran on a weaker model must be able
 * to say so on its face (docs/48 J.2). A briefing that was quietly downgraded looks like a briefing
 * that got worse for no reason.
 */
final readonly class AiOutcome
{
    public function __construct(
        public string $text,
        /** @var array<string, mixed>|null the validated object, when a schema was required */
        public ?array $data,
        public AiRun $run,
        public AiModel $model,
        public bool $degraded = false,
    ) {}

    /**
     * The tool calls the model proposed, in HQ's canonical shape (docs/90 A.1 step 2). This is the
     * provider-to-HQ boundary for arguments: whatever key the model wrote its payload under, a reader
     * of this list sees one representation, and nothing downstream reads the raw reply for a call.
     *
     * @return list<ProposedToolCall>
     */
    public function toolCalls(): array
    {
        return ToolCallNormaliser::fromReply($this->data);
    }

    public function costUsd(): string
    {
        return (string) $this->run->estimated_cost_usd;
    }

    /** A line an operator can be shown, or a briefing can carry in its footer. */
    public function provenance(): string
    {
        $line = "{$this->model->display_name} ({$this->model->provider}), "
            ."{$this->run->input_tokens} in / {$this->run->output_tokens} out, "
            .'$'.number_format((float) $this->run->estimated_cost_usd, 4)
            .", {$this->run->latency_ms} ms";

        return $this->degraded
            ? $line.". Ran on a lower tier than requested ({$this->run->degraded_from_tier} was asked for), so treat the result accordingly."
            : $line;
    }
}
