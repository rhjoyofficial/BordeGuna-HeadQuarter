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

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['status', 'answer', 'refusal_reason', 'facts', 'inferences', 'tool_calls'],
            'properties' => [
                'status' => [
                    'type' => 'string',
                    'enum' => [self::STATUS_NEED_EVIDENCE, self::STATUS_ANSWER, self::STATUS_REFUSE],
                ],
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
