<?php

namespace App\Ai\Providers;

/**
 * Whether a JSON schema is inside the subset OpenAI's Structured Outputs accepts with `strict: true`.
 *
 * ⚠ It matters because the failure is loud in the wrong place. "If you turn on Structured Outputs by
 * supplying `strict: true` and call the API with an unsupported JSON Schema, you will receive an error"
 * (the Structured Outputs guide, read 21 September 2026): a 400, which the runtime rightly treats as a
 * refusal, so one keyword too many would end every agent turn of a safety gate as a provider error.
 * Sending such a schema with `strict: false` instead keeps the call working and leaves the guarantee to
 * HQ's own `ArgumentValidator` and its one repair, which check every reply whatever the provider.
 *
 * The rules are the guide's "Supported schemas" section, as an ALLOW-list: a keyword the guide does not
 * list is treated as unsupported, never assumed to work. The root is an object and not `anyOf`; every
 * object sets `additionalProperties: false` and requires every property; an array describes its items;
 * at most 10 levels of nesting, 5000 properties and 1000 enum values.
 *
 * It lives in the driver layer because it is knowledge of one provider's API, like the endpoint.
 */
final class OpenAiStrictSchema
{
    private const MAX_DEPTH = 10;

    private const MAX_PROPERTIES = 5000;

    private const MAX_ENUM_VALUES = 1000;

    private const TYPES = ['string', 'number', 'boolean', 'integer', 'object', 'array', 'null'];

    /** Keywords any node may carry. */
    private const COMMON = ['type', 'description', 'enum', 'const', 'anyOf', '$ref'];

    /** Keywords by type, from the guide's "Supported properties". */
    private const BY_TYPE = [
        'object' => ['properties', 'required', 'additionalProperties', '$defs', 'definitions'],
        'array' => ['items', 'minItems', 'maxItems'],
        'string' => ['pattern', 'format'],
        'number' => ['multipleOf', 'maximum', 'exclusiveMaximum', 'minimum', 'exclusiveMinimum'],
        'integer' => ['multipleOf', 'maximum', 'exclusiveMaximum', 'minimum', 'exclusiveMinimum'],
        'boolean' => [],
        'null' => [],
    ];

    private const FORMATS = ['date-time', 'time', 'date', 'duration', 'email', 'hostname', 'ipv4', 'ipv6', 'uuid'];

    /** @param array<string, mixed> $schema */
    public static function compatible(array $schema): bool
    {
        if (isset($schema['anyOf']) || ($schema['type'] ?? null) !== 'object') {
            return false;
        }

        $counts = ['properties' => 0, 'enum' => 0];

        return self::node($schema, 1, $counts)
            && $counts['properties'] <= self::MAX_PROPERTIES
            && $counts['enum'] <= self::MAX_ENUM_VALUES;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array{properties: int, enum: int}  $counts
     */
    private static function node(array $node, int $depth, array &$counts): bool
    {
        if ($depth > self::MAX_DEPTH) {
            return false;
        }

        if (isset($node['$ref'])) {
            // A reference into the schema's own definitions; the definitions are checked where they sit.
            return is_string($node['$ref']) && count($node) <= 2;
        }

        if (isset($node['anyOf'])) {
            if (! is_array($node['anyOf']) || $node['anyOf'] === []) {
                return false;
            }
            foreach ($node['anyOf'] as $branch) {
                if (! is_array($branch) || ! self::node($branch, $depth, $counts)) {
                    return false;
                }
            }

            return array_diff(array_keys($node), ['anyOf', 'description']) === [];
        }

        $types = (array) ($node['type'] ?? []);
        if ($types === [] || array_diff($types, self::TYPES) !== []) {
            return false;
        }

        $allowed = self::COMMON;
        foreach ($types as $type) {
            $allowed = [...$allowed, ...self::BY_TYPE[$type]];
        }
        if (array_diff(array_keys($node), $allowed) !== []) {
            return false;
        }

        if (isset($node['enum'])) {
            if (! is_array($node['enum'])) {
                return false;
            }
            $counts['enum'] += count($node['enum']);
        }

        if (isset($node['format']) && ! in_array($node['format'], self::FORMATS, true)) {
            return false;
        }

        if (in_array('object', $types, true) && ! self::object($node, $depth, $counts)) {
            return false;
        }

        if (in_array('array', $types, true)) {
            if (! isset($node['items']) || ! is_array($node['items']) || ! self::node($node['items'], $depth + 1, $counts)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array{properties: int, enum: int}  $counts
     */
    private static function object(array $node, int $depth, array &$counts): bool
    {
        if (($node['additionalProperties'] ?? null) !== false || ! isset($node['properties']) || ! is_array($node['properties'])) {
            return false;
        }

        $properties = $node['properties'];
        $required = (array) ($node['required'] ?? []);
        $names = array_map('strval', array_keys($properties));
        sort($names);
        $sortedRequired = array_map('strval', $required);
        sort($sortedRequired);
        if ($names !== $sortedRequired) {
            return false;
        }

        $counts['properties'] += count($properties);
        foreach ($properties as $property) {
            if (! is_array($property) || ! self::node($property, $depth + 1, $counts)) {
                return false;
            }
        }

        foreach (['$defs', 'definitions'] as $key) {
            foreach ((array) ($node[$key] ?? []) as $definition) {
                if (! is_array($definition) || ! self::node($definition, $depth + 1, $counts)) {
                    return false;
                }
            }
        }

        return true;
    }
}
