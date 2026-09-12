<?php

namespace App\Ai;

use App\Models\AiModel;

/**
 * How many output tokens a call may ask for, and what happens when that was not enough (ADR-038).
 *
 * ## Why a policy and not a number
 *
 * ⚠⚠ The third safety gate (docs/65) failed one case on `stop_reason = max_tokens`: the agent's
 * per-call cap was a constant, 4,096; the model needed 8,192 to write an eight-call sweep down as
 * one JSON object; and the runtime reported the cut-off reply as a MALFORMED one and spent its
 * single repair call running into the same wall. Attempt 2 had failed a different case the same
 * way. Raising the constant to 8,192 would have fixed both runs and taught nothing: the next
 * longer answer, the next model with a smaller window, the next provider that reports truncation
 * differently, would each fail the same way with a different number.
 *
 * So the cap is a policy with four inputs, and each of them is visible on the `ai_runs` row:
 *
 *  - **the class of work** (`for()`): an agent turn that must carry facts, inferences and tool
 *    calls in one object is allowed more than a classification label;
 *  - **the model's own limit** (`clamp()`): read from the `model_tiers` row, never assumed. A
 *    request above what the model can produce is refused by the provider or silently cut, and
 *    neither is an answer;
 *  - **a configured ceiling** (`ceiling()`): the most HQ will ever ask for in one call, whatever
 *    the model offers, because output tokens are the expensive ones and a runaway answer is a
 *    runaway bill;
 *  - **a bounded raise** (`raised()`): when a reply was cut off, the one thing worth trying is the
 *    same request with more room. Once, by a fixed factor, never past the ceiling or the model,
 *    and `null` when there is no room left, so the caller can record "truncated with no headroom"
 *    instead of retrying into the same limit.
 *
 * ⛔ Provider-neutral by construction. Nothing here names a provider or a model; the policy reads
 * the model row it is handed. `ModelResponse::truncated()` is the driver's translation of its
 * provider's stop reason, and that is the only place a provider's vocabulary is allowed to appear.
 */
final class OutputBudget
{
    /** The class of work an agent turn is: one structured object carrying facts, inferences and calls. */
    public const AGENT_TURN = 'agent_turn';

    /** @var array<string, mixed> */
    private readonly array $config;

    /** @param  array<string, mixed>|null  $config  defaults to `hq.ai.output` */
    public function __construct(?array $config = null)
    {
        $this->config = $config ?? (array) config('hq.ai.output', []);
    }

    /** The requested budget for a class of work, before any clamping. */
    public function for(string $class): int
    {
        $value = (int) ($this->config[$class] ?? 0);

        return $value > 0 ? $value : $this->default();
    }

    public function default(): int
    {
        return max(1, (int) ($this->config['default'] ?? 1024));
    }

    /** The most HQ asks for in one call, whatever the model could produce. */
    public function ceiling(): int
    {
        return max(1, (int) ($this->config['ceiling'] ?? 16384));
    }

    /** How many times a truncated reply may be re-asked with a larger budget. */
    public function truncationRetries(): int
    {
        return max(0, (int) ($this->config['truncation_retries'] ?? 1));
    }

    /** What a request may actually carry to this model: the smallest of the three limits. */
    public function clamp(int $requested, AiModel $model): int
    {
        return max(1, min($requested, $this->modelLimit($model), $this->ceiling()));
    }

    /**
     * The next budget after a truncation, or null when there is no headroom.
     *
     * Null is a real answer. It tells the runtime that a retry would meet the same limit, so the
     * honest outcome is a recorded truncation rather than a second, identical failure.
     */
    public function raised(int $current, AiModel $model): ?int
    {
        $factor = max(1.0, (float) ($this->config['growth_factor'] ?? 2));
        $next = $this->clamp((int) ceil($current * $factor), $model);

        return $next > $current ? $next : null;
    }

    private function modelLimit(AiModel $model): int
    {
        $limit = (int) $model->max_output_tokens;

        return $limit > 0 ? $limit : $this->ceiling();
    }
}
