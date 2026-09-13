<?php

namespace App\Gateway\Descriptor;

/**
 * How many targets one invocation of a tool covers (docs/69, ADR-040).
 *
 * ⭐ Gate 5 showed a habit the business-reference contract alone did not reach: asked a group-wide
 * question, the agent wanted the ERP snapshot for "all businesses", the tool takes exactly one, and
 * it sent none rather than one call per business. Nothing in the contract said which. This field
 * does. A tool declares whether one call covers HQ as a whole, one business, or one store, and the
 * catalogue turns that into a sentence the model reads beside the arguments: a group-wide question
 * is one invocation per relevant target, then the caller aggregates.
 *
 * Joy's instruction was explicit that this is a capability-contract concern and not a gateway one:
 * authorisation is untouched, the gateway still resolves and checks scope per call exactly as
 * before. Cardinality only tells the caller how many calls a question is.
 */
enum ToolCardinality: string
{
    /** One invocation covers HQ as a whole; no target argument. */
    case HqWide = 'hq_wide';

    /** One invocation covers exactly one business; a group-wide question is one call per business. */
    case OnePerBusiness = 'one_per_business';

    /** One invocation covers exactly one store; a group-wide question is one call per store. */
    case OnePerStore = 'one_per_store';

    /** The sentence the agent catalogue carries for a tool of this cardinality. */
    public function semantics(): string
    {
        return match ($this) {
            self::HqWide => 'One call covers every business HQ holds; there is no target argument.',
            self::OnePerBusiness => 'One call returns exactly one business, named by its slug. For every business you may read at once, '
                .'pass business_slug "all": HQ runs one call per relevant business itself and returns them separately, and you '
                .'aggregate the results, keeping each business apart. Never invent a slug; the directory lists them.',
            self::OnePerStore => 'One call returns exactly one store, named by its slug. For every store you may read at once, pass '
                .'store_slug "all": HQ runs one call per relevant store itself and returns them separately, and you aggregate '
                .'the results, keeping each store apart. Never invent a slug; the directory lists them.',
        };
    }
}
