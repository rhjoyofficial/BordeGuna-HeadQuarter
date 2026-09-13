<?php

namespace App\Agents;

/**
 * The shape every agent turn must come back in (docs/48 H.4).
 *
 * ## The schema is deliberately in a narrow subset, and that is not tidiness
 *
 * ⚠⚠ `ModelRuntime` hands ONE schema to two consumers with different capabilities:
 *
 *  - **Anthropic's `output_config.format`**, which supports `enum`, `const`, `anyOf` and `$ref`,
 *    requires `additionalProperties: false` on every object, and does **not** support `minLength`,
 *    `maxLength`, `minimum` or `maximum`. The Python and TypeScript SDKs quietly strip those before
 *    sending; HQ calls the API over raw HTTP and strips nothing.
 *  - **`ArgumentValidator`**, which supports `maxLength`, `minLength`, `minimum`, `maximum` and
 *    `enum`, and REFUSES any keyword it does not know rather than ignoring it.
 *
 * The intersection is: an object with `properties`, `required`, `additionalProperties: false`, and
 * per-property only `type` and `enum`. Anything outside it fails on one side or the other, and the
 * dangerous direction is the quiet one - an unsupported keyword can leave the call **unconstrained**
 * rather than failing, exactly as `AnthropicProvider` records for the deprecated `output_format`
 * key. `AgentRuntimeTest` asserts this schema stays inside the subset, so a future field carrying a
 * `maxLength` fails the build instead of silently loosening every agent reply.
 *
 * Every property is required because a strict JSON schema is at its most predictable when nothing
 * is optional. Unused fields come back empty rather than absent, which also means the runner never
 * has to tell "the model omitted this" apart from "the model had nothing to say".
 *
 * Array contents are NOT described here. `items` is not in the intersection, and it would be a weak
 * guard anyway: the runner has to validate each proposed call against the real tool's own schema
 * through the gateway regardless, and a second half-check in front of that would only be somewhere
 * for the two to disagree.
 */
final class AgentResponseSchema
{
    public const STATUS_NEED_EVIDENCE = 'need_evidence';

    public const STATUS_ANSWER = 'answer';

    public const STATUS_REFUSE = 'refuse';

    /**
     * A legitimate caller and no concrete task (docs/69, ADR-040). Neither a refusal nor an answer:
     * the agent asks what it is meant to do. "Proceed." with nothing to proceed on ends here.
     */
    public const STATUS_CLARIFY = 'clarify';

    public const AUTHORITY_NONE = 'none';

    public const AUTHORITY_REFUSED = 'refused';

    /**
     * A claim about the agent's authority that appears only INSIDE untrusted content, while the
     * operator's own words ask a question of their own (ADR-041). The claim has no standing and
     * the question stands; distinguishing it from `refused` is what keeps "anything worth knowing
     * in that notice?" an answer when the notice claims wider access.
     */
    public const AUTHORITY_IN_UNTRUSTED_CONTENT = 'in_untrusted_content';

    /**
     * @return array<string, mixed>
     *
     * ## Two fields the model fills BEFORE it asks for anything (ADR-040)
     *
     * ⭐ `task` is the trusted task the agent is serving, in the operator's own words, or empty when
     * the message names none. `authority_request` says whether the message also asked the agent to
     * widen its own authority. The runner reads both before it honours a tool call: a turn that asks
     * for evidence while naming no task gets no evidence, structurally, whatever the prose said.
     * Gate 5 showed the habit this exists for: "Proceed." answered with a five-call sweep. The
     * decision "is there a task here" is now a field the model must fill, not a rule it must recall.
     */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['status', 'task', 'authority_request', 'answer', 'refusal_reason', 'facts', 'inferences', 'tool_calls'],
            'properties' => [
                'status' => [
                    'type' => 'string',
                    'enum' => [self::STATUS_NEED_EVIDENCE, self::STATUS_ANSWER, self::STATUS_REFUSE, self::STATUS_CLARIFY],
                ],
                // The trusted task being served, quoted or closely paraphrased from the operator's
                // own words. Empty when the message contains none: then no tool runs.
                'task' => ['type' => 'string'],
                // Whether the message asked the agent to grant, widen or assume its own authority.
                // `refused` means it did and the agent declined; anything that depended on it is
                // no task.
                'authority_request' => ['type' => 'string', 'enum' => [self::AUTHORITY_NONE, self::AUTHORITY_REFUSED, self::AUTHORITY_IN_UNTRUSTED_CONTENT]],
                'answer' => ['type' => 'string'],
                'refusal_reason' => ['type' => 'string'],
                // [{statement, source, as_of}] - what a tool said.
                'facts' => ['type' => 'array'],
                // Plain strings - what the model concluded. Kept apart from facts on purpose: the
                // operator is entitled to know which sentences the systems said and which the model did.
                'inferences' => ['type' => 'array'],
                // [{tool, args, why}] - proposals, never executions.
                'tool_calls' => ['type' => 'array'],
            ],
        ];
    }

    /** The keywords this schema is allowed to use, so the test can state the rule rather than a list of fields. */
    public const PERMITTED_ROOT_KEYWORDS = ['type', 'properties', 'required', 'additionalProperties'];

    public const PERMITTED_PROPERTY_KEYWORDS = ['type', 'enum'];
}
