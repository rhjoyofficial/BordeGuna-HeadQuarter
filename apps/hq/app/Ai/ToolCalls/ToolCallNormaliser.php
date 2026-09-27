<?php

namespace App\Ai\ToolCalls;

/**
 * The provider-to-HQ boundary for tool calls (docs/90 A.1 step 2): the model's `tool_calls` array,
 * whatever names it used, becomes a list of `ProposedToolCall`.
 *
 * Provider-specific naming is normalised HERE and nowhere else. The names accepted are the ones a
 * structured-output model produces for "the arguments of a call": the catalogue's own `arguments`,
 * the older `args`, and the `input` / `parameters` / `params` that other tool-calling conventions use.
 * A payload written as a JSON string (some models stringify nested objects) is decoded; a payload of
 * any other shape is treated as absent, and the gateway names the missing argument.
 *
 * ⛔ No key is discovered from prompt text, and no argument is invented: a call with no payload keeps
 *    `argumentsKey` null and an empty payload, and the runner refuses to expand it.
 */
final class ToolCallNormaliser
{
    /** In order of preference, when a call carries more than one. */
    public const ARGUMENT_KEYS = ['arguments', 'args', 'input', 'parameters', 'params'];

    public const TOOL_KEYS = ['tool', 'name', 'tool_key', 'key'];

    public const WHY_KEYS = ['why', 'reason', 'purpose'];

    /**
     * @param  array<string, mixed>|null  $reply  the validated structured reply
     * @return list<ProposedToolCall>
     */
    public static function fromReply(?array $reply): array
    {
        $calls = $reply['tool_calls'] ?? [];
        if (! is_array($calls)) {
            return [];
        }

        $out = [];
        foreach ($calls as $call) {
            $normalised = is_array($call) ? self::call($call) : null;
            if ($normalised !== null) {
                $out[] = $normalised;
            }
        }

        return $out;
    }

    /**
     * One call, or null when it names no tool (a call without a tool is not a call).
     *
     * @param  array<string, mixed>  $call
     */
    public static function call(array $call): ?ProposedToolCall
    {
        $tool = null;
        foreach (self::TOOL_KEYS as $key) {
            if (is_string($call[$key] ?? null) && trim($call[$key]) !== '') {
                $tool = trim($call[$key]);
                break;
            }
        }
        if ($tool === null) {
            return null;
        }

        $argumentsKey = null;
        $arguments = [];
        foreach (self::ARGUMENT_KEYS as $key) {
            if (! array_key_exists($key, $call)) {
                continue;
            }
            $decoded = self::payload($call[$key]);
            if ($decoded !== null) {
                $argumentsKey = $key;
                $arguments = $decoded;
                break;
            }
        }

        $why = null;
        foreach (self::WHY_KEYS as $key) {
            if (is_string($call[$key] ?? null) && $call[$key] !== '') {
                $why = $call[$key];
                break;
            }
        }

        return new ProposedToolCall($tool, $arguments, $why, $argumentsKey, $call);
    }

    /**
     * An object payload as an array; a JSON-encoded object decoded; anything else null. A JSON list
     * is not an argument object and is refused as absent.
     *
     * @return array<string, mixed>|null
     */
    private static function payload(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value === [] || ! array_is_list($value) ? $value : null;
        }

        if (is_string($value)) {
            $decoded = json_decode(trim($value), true);
            if (is_array($decoded) && ($decoded === [] || ! array_is_list($decoded))) {
                return $decoded;
            }
        }

        return null;
    }
}
