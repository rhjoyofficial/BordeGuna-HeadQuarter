<?php

namespace App\Evaluation;

/**
 * What a case is actually measuring (ADR-030).
 *
 * ## Why one agreement number is not enough
 *
 * `autonomy_grants.min_agreement_rate` reads a single figure, and a single figure conflates six
 * different things that fail for different reasons and are fixed by different people. An agent can
 * pick exactly the right tools and reason badly about the results; it can reason perfectly and
 * propose something policy correctly refuses; policy can be right while the tool it permitted did
 * not actually do the thing; and a tool can succeed while the postcondition it was supposed to
 * establish never held.
 *
 * Averaged together, all of those read as "94%". Widening autonomy on that number is widening it on
 * an average of unrelated quantities - which is precisely the moment the distinction becomes
 * expensive, because the dimension that matters for the action being unlocked may be the one
 * dragging the average up rather than the one being measured.
 *
 * ⚠ The dimensions are deliberately about DIFFERENT COMPONENTS, not different topics. Each names
 * who is wrong when it fails.
 */
enum EvaluationDimension: string
{
    /** The model's own thinking: is the answer right, honest, and within what the evidence supports? */
    case Reasoning = 'reasoning';

    /** Did it ask for the right tools, and not for the wrong ones? Failure blames the prompt or the model. */
    case ToolSelection = 'tool_selection';

    /** Did the Policy Engine decide correctly? Failure blames a policy rule, never the model. */
    case Policy = 'policy';

    /** Were permission, scope and consent actually ENFORCED? Failure blames the control plane. */
    case Enforcement = 'enforcement';

    /** Did the tool that ran actually do the thing? Failure blames the adapter or the far side. */
    case Execution = 'execution';

    /**
     * Did the postcondition hold afterwards?
     *
     * ⭐ Separate from execution on purpose: a provider returning 200 is not evidence that the
     * message arrived, the order exists, or the post is visible. This is the dimension that catches
     * "it said it worked", and it is the one an autonomous system needs most.
     */
    case Outcome = 'outcome';

    public function label(): string
    {
        return match ($this) {
            self::Reasoning => 'model reasoning',
            self::ToolSelection => 'tool selection',
            self::Policy => 'policy decision',
            self::Enforcement => 'permission and consent enforcement',
            self::Execution => 'execution',
            self::Outcome => 'postcondition',
        };
    }

    /** Who is at fault when this dimension fails, written for whoever reads the report. */
    public function blames(): string
    {
        return match ($this) {
            self::Reasoning => 'the prompt or the model',
            self::ToolSelection => 'the prompt, the tool catalogue, or the model',
            self::Policy => 'a policy rule',
            self::Enforcement => 'the control plane',
            self::Execution => 'the adapter or the provider',
            self::Outcome => 'the assumption that a successful call means a completed effect',
        };
    }
}
