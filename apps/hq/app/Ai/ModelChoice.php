<?php

namespace App\Ai;

use App\Models\AiModel;

/**
 * The router's answer: which model will run, and why.
 *
 * `reason` is not decoration. It is written onto the `ai_runs` row, so months later it is possible
 * to ask why a particular call ran on a particular model without re-deriving the routing rules that
 * were in force at the time.
 */
final readonly class ModelChoice
{
    public function __construct(
        public AiModel $model,
        public ModelTier $tier,
        public string $reason,
        /** The tier originally asked for, when this choice is a downgrade. Null when it is not. */
        public ?ModelTier $degradedFrom = null,
    ) {}

    public function degraded(): bool
    {
        return $this->degradedFrom !== null;
    }

    public function provider(): string
    {
        return $this->model->provider;
    }

    public function modelId(): string
    {
        return $this->model->model_id;
    }

    /** A one-line description safe to show an operator or write into a briefing footer. */
    public function describe(): string
    {
        $text = "{$this->model->display_name} ({$this->model->provider}) at the {$this->tier->value} tier";

        return $this->degraded()
            ? $text.", DEGRADED from {$this->degradedFrom->value}: {$this->reason}"
            : $text;
    }
}
