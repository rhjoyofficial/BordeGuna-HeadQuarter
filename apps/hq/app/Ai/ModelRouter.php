<?php

namespace App\Ai;

use App\Ai\Budget\AiBudget;
use App\Ai\Exceptions\NoModelAvailable;
use App\Ai\Providers\ProviderRegistry;
use App\Models\AiModel;

/**
 * The single entry point for choosing a model (ADR-019).
 *
 * `ModelRouter::for(TaskType, RoutingContext): ModelChoice`. Business code names a task and a
 * context; it never names a model, a provider or a price. That monopoly is not tidiness: cost
 * accounting is only as accurate as the router's exclusive knowledge of what actually ran.
 *
 * The order of consideration matters and is deliberate:
 *
 *   1. The task's tier, unless a budget says to degrade.
 *   2. Candidates at that tier, best priority first, that meet the required capabilities and
 *      context size, whose provider is configured and whose breaker is not open.
 *   3. Failing that, the tier below, but ONLY if the task is degradable.
 *   4. Failing that, refuse. Loudly.
 *
 * ⚠ Step 4 is the one worth defending. Quietly running on whatever happens to be available is how a
 * briefing silently becomes worse without anybody knowing, and it is precisely the outcome a router
 * exists to prevent. A refusal is visible; a silent substitution is not.
 */
final class ModelRouter
{
    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly CircuitBreaker $breaker,
        private readonly AiBudget $budget,
    ) {}

    public function for(TaskType $task, ?RoutingContext $context = null): ModelChoice
    {
        $context ??= new RoutingContext;
        $asked = $task->defaultTier();
        $degradable = $context->degradableFor($task);

        // A soft budget breach degrades before anything else is considered, so a business that is
        // over its cap does not simply keep spending at the top tier until something else fails.
        $pressure = $this->budget->pressure($context->businessId);
        $start = $asked;
        $degradedFrom = null;
        $reason = "task {$task->value} routes to the {$asked->value} tier";

        if ($pressure['downgrade'] && $degradable && $asked->degradesTo() !== null) {
            $start = $asked->degradesTo();
            $degradedFrom = $asked;
            $reason = "budget at {$pressure['share_percent']}% of cap, degraded from {$asked->value}";
        }

        $tier = $start;
        $tried = [];

        while ($tier !== null) {
            $candidate = $this->firstUsable($tier, $context, $tried);

            if ($candidate !== null) {
                return new ModelChoice(
                    model: $candidate,
                    tier: $tier,
                    reason: $tier === $start ? $reason : "{$reason}; no model usable at {$start->value}, fell to {$tier->value}",
                    degradedFrom: $tier === $asked ? null : ($degradedFrom ?? $asked),
                );
            }

            $tier = $degradable ? $tier->degradesTo() : null;
        }

        throw new NoModelAvailable(
            "No usable model for task [{$task->value}] at the {$asked->value} tier"
            .($degradable ? ' or below' : ' (this task may not be degraded)')
            .'. '.$this->diagnose($tried)
        );
    }

    /**
     * The best candidate at one tier, or null.
     *
     * @param  list<string>  $tried  filled with human-readable rejections, for the error message
     */
    private function firstUsable(ModelTier $tier, RoutingContext $context, array &$tried): ?AiModel
    {
        foreach (AiModel::query()->forTier($tier)->get() as $model) {
            if (! $model->satisfies($context->requiredCapabilities, $context->minContextTokens)) {
                $tried[] = "{$model->display_name}: does not meet the required capabilities or context size";

                continue;
            }

            if (! $this->providers->has($model->provider)) {
                $tried[] = "{$model->display_name}: provider [{$model->provider}] has no registered driver";

                continue;
            }

            if (! $this->providers->get($model->provider)->available()) {
                $tried[] = "{$model->display_name}: provider [{$model->provider}] has no credential configured";

                continue;
            }

            if (! $this->breaker->allows($model->provider)) {
                $tried[] = "{$model->display_name}: provider [{$model->provider}] circuit breaker is open";

                continue;
            }

            return $model;
        }

        return null;
    }

    /**
     * Everything at a tier that could serve a call, best first, so the runtime can fall through them
     * on failure without asking the router again.
     *
     * ⚠ Fallback prefers a DIFFERENT provider (docs/48 J.2): retrying the same outage on the same
     * provider is how a slow failure becomes a long one.
     *
     * @return list<AiModel>
     */
    public function candidates(ModelChoice $choice, RoutingContext $context): array
    {
        $all = [];

        foreach (AiModel::query()->forTier($choice->tier)->get() as $model) {
            if ($model->getKey() === $choice->model->getKey()) {
                continue;
            }
            if (! $model->satisfies($context->requiredCapabilities, $context->minContextTokens)) {
                continue;
            }
            if (! $this->providers->has($model->provider) || ! $this->providers->get($model->provider)->available()) {
                continue;
            }
            if (! $this->breaker->allows($model->provider)) {
                continue;
            }

            $all[] = $model;
        }

        // A different provider first, then same-provider alternatives.
        usort($all, fn (AiModel $a, AiModel $b) => ($a->provider === $choice->provider() ? 1 : 0) <=> ($b->provider === $choice->provider() ? 1 : 0));

        return array_values($all);
    }

    /** @param list<string> $tried */
    private function diagnose(array $tried): string
    {
        if ($tried === []) {
            return 'No active rows in `model_tiers` for that tier. Run the ModelTierSeeder.';
        }

        return 'Rejected: '.implode('; ', array_slice($tried, 0, 5)).'.';
    }
}
