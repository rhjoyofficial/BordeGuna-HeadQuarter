<?php

namespace App\Gateway\Validation;

/**
 * Validates tool arguments against the tool's own inputSchema(), BEFORE policy, credential
 * resolution or any connector call (docs/48 D.3 step 2).
 *
 * The schemas the six existing tools already declare are a small, deliberate subset of JSON Schema:
 * an object with typed properties, `required`, `additionalProperties: false`, and the constraints
 * `minimum`, `maximum`, `maxLength` and `enum`. This validates exactly that subset and REFUSES a
 * schema keyword it does not understand rather than ignoring it.
 *
 * Silently ignoring an unknown keyword is how a validator ends up not validating: the schema says
 * `pattern`, the validator shrugs, and everybody believes the argument was checked. An unsupported
 * keyword is a defect in the tool, and it fails loudly at the boundary where it is cheap to fix.
 */
final class ArgumentValidator
{
    private const SUPPORTED_ROOT = ['type', 'properties', 'required', 'additionalProperties'];

    private const SUPPORTED_PROPERTY = ['type', 'minimum', 'maximum', 'maxLength', 'minLength', 'enum'];

    public function validate(array $args, array $schema): ValidationResult
    {
        $errors = [];

        if (($schema['type'] ?? 'object') !== 'object') {
            return ValidationResult::fail(['Tool input schema must describe an object.']);
        }
        foreach (array_keys($schema) as $keyword) {
            if (! in_array($keyword, self::SUPPORTED_ROOT, true)) {
                return ValidationResult::fail(["Tool input schema uses unsupported keyword [{$keyword}]."]);
            }
        }

        $properties = $schema['properties'] ?? [];
        $required = $schema['required'] ?? [];
        $allowExtra = (bool) ($schema['additionalProperties'] ?? false);

        foreach ($required as $name) {
            if (! array_key_exists($name, $args) || $args[$name] === null) {
                $errors[] = "[{$name}] is required.";
            }
        }

        if (! $allowExtra) {
            foreach (array_keys($args) as $name) {
                if (! array_key_exists($name, $properties)) {
                    $errors[] = "[{$name}] is not accepted by this tool.";
                }
            }
        }

        $normalised = [];
        foreach ($args as $name => $value) {
            $rules = $properties[$name] ?? null;
            if ($rules === null) {
                if ($allowExtra) {
                    $normalised[$name] = $value;
                }

                continue;
            }
            foreach (array_keys($rules) as $keyword) {
                if (! in_array($keyword, self::SUPPORTED_PROPERTY, true)) {
                    return ValidationResult::fail(["Tool input schema uses unsupported keyword [{$keyword}] on [{$name}]."]);
                }
            }

            [$coerced, $problem] = $this->check((string) $name, $value, $rules);
            if ($problem !== null) {
                $errors[] = $problem;

                continue;
            }
            $normalised[$name] = $coerced;
        }

        return $errors === [] ? ValidationResult::pass($normalised) : ValidationResult::fail($errors);
    }

    /**
     * @return array{0: mixed, 1: string|null} the coerced value, or null plus the reason it failed
     */
    private function check(string $name, mixed $value, array $rules): array
    {
        $type = $rules['type'] ?? null;

        if ($value === null) {
            // An explicit null for an optional property is an absence, not a type error. Required
            // properties were already caught above, where the message can say so plainly.
            return [null, null];
        }

        $problem = match ($type) {
            'string' => $this->checkString($name, $value, $rules),
            'integer' => $this->checkInteger($name, $value, $rules),
            'number' => $this->checkNumber($name, $value, $rules),
            'boolean' => is_bool($value) ? null : "[{$name}] must be true or false.",
            'array' => is_array($value) && array_is_list($value) ? null : "[{$name}] must be a list.",
            'object' => is_array($value) && (! array_is_list($value) || $value === []) ? null : "[{$name}] must be an object.",
            null => null,
            default => "[{$name}] declares unsupported type [{$type}].",
        };

        if ($problem !== null) {
            return [null, $problem];
        }

        $value = match ($type) {
            'integer' => is_string($value) ? (int) $value : $value,
            'number' => (float) $value,
            default => $value,
        };

        if (isset($rules['enum']) && ! in_array($value, $rules['enum'], true)) {
            return [null, "[{$name}] must be one of: ".implode(', ', array_map('strval', $rules['enum'])).'.'];
        }

        return [$value, null];
    }

    private function checkString(string $name, mixed $value, array $rules): ?string
    {
        if (! is_string($value)) {
            return "[{$name}] must be a string.";
        }
        if (isset($rules['maxLength']) && mb_strlen($value) > $rules['maxLength']) {
            return "[{$name}] must be at most {$rules['maxLength']} characters.";
        }
        if (isset($rules['minLength']) && mb_strlen($value) < $rules['minLength']) {
            return "[{$name}] must be at least {$rules['minLength']} characters.";
        }

        return null;
    }

    private function checkInteger(string $name, mixed $value, array $rules): ?string
    {
        // A JSON body and a jsonb payload disagree about whether 48 is an int or "48". An
        // integer-valued string is accepted and coerced; a fractional or non-numeric one is not.
        $isIntegerish = is_int($value) || (is_string($value) && preg_match('/^-?[0-9]+$/', $value) === 1);
        if (! $isIntegerish) {
            return "[{$name}] must be an integer.";
        }
        $number = (int) $value;
        if (isset($rules['minimum']) && $number < $rules['minimum']) {
            return "[{$name}] must be at least {$rules['minimum']}.";
        }
        if (isset($rules['maximum']) && $number > $rules['maximum']) {
            return "[{$name}] must be at most {$rules['maximum']}.";
        }

        return null;
    }

    private function checkNumber(string $name, mixed $value, array $rules): ?string
    {
        if (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value))) {
            return "[{$name}] must be a number.";
        }
        $number = (float) $value;
        if (isset($rules['minimum']) && $number < $rules['minimum']) {
            return "[{$name}] must be at least {$rules['minimum']}.";
        }
        if (isset($rules['maximum']) && $number > $rules['maximum']) {
            return "[{$name}] must be at most {$rules['maximum']}.";
        }

        return null;
    }
}
