<?php

namespace App\Gateway\Redaction;

/**
 * Schema-driven redaction for tool arguments and outputs (docs/48 N.2).
 *
 * Two layers, and they do different jobs:
 *
 *  1. The tool's declared `redact` paths. The tool knows which of ITS arguments are sensitive, and
 *     it says so in its descriptor. Dot paths, with `*` for every element of a list.
 *  2. A leaf key deny list, as a backstop for anything a tool forgot to declare.
 *
 * What this must NOT do is blank whole structures. The whole point of recording tool arguments is
 * being able to reconstruct what happened, and a `tool_calls` row reading `{"input":"[redacted]"}`
 * records that something occurred while destroying every fact about it. Ordinary business
 * arguments - a store slug, an hours window, a business id - stay visible.
 */
final class Redactor
{
    public const MASK = '[redacted]';

    /**
     * Leaf keys that hold a secret whatever the tool thinks. Matched case-insensitively on the key
     * name only, so a value that merely contains the word "token" is untouched.
     */
    private const SECRET_KEYS = [
        'password', 'password_confirmation', 'secret', 'token', 'api_key', 'apikey',
        'access_token', 'refresh_token', 'client_secret', 'authorization', 'auth',
        'two_factor_secret', 'two_factor_recovery_codes', 'remember_token', 'private_key',
        'signature', 'hmac', 'bearer',
    ];

    /**
     * @param  list<string>  $paths  declared paths such as `credentials.token` or `items.*.secret`
     */
    public function redact(array $data, array $paths = []): array
    {
        foreach ($paths as $path) {
            $data = $this->maskPath($data, explode('.', $path));
        }

        return $this->maskSecretKeys($data);
    }

    /** @param list<string> $segments */
    private function maskPath(array $data, array $segments): array
    {
        $head = array_shift($segments);
        if ($head === null) {
            return $data;
        }

        if ($head === '*') {
            foreach ($data as $key => $value) {
                if ($segments === []) {
                    $data[$key] = self::MASK;
                } elseif (is_array($value)) {
                    $data[$key] = $this->maskPath($value, $segments);
                }
            }

            return $data;
        }

        if (! array_key_exists($head, $data)) {
            return $data;
        }

        if ($segments === []) {
            $data[$head] = self::MASK;

            return $data;
        }

        if (is_array($data[$head])) {
            $data[$head] = $this->maskPath($data[$head], $segments);
        }

        return $data;
    }

    private function maskSecretKeys(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::SECRET_KEYS, true)) {
                $data[$key] = self::MASK;
            } elseif (is_array($value)) {
                $data[$key] = $this->maskSecretKeys($value);
            }
        }

        return $data;
    }

    /**
     * The values this redactor would mask, so free text can be scrubbed of them.
     *
     * The gateway holds the caller's arguments; it never holds a credential. So the secrets it can
     * scrub from an error message are exactly the sensitive ARGUMENTS somebody passed in, which is
     * the case that matters here: a connector that interpolates the key it was given into its own
     * exception message would otherwise write it straight into `tool_calls.error`.
     *
     * ⚠ It cannot scrub a credential the connector fetched for itself, because the gateway has
     * never seen that value. Closing that gap is the connector-side refactor in M2; until then it
     * is a documented limitation rather than a silent one.
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    public function secretsIn(array $data, array $paths = []): array
    {
        $masked = $this->redact($data, $paths);

        return array_values(array_unique($this->collectMasked($data, $masked)));
    }

    /** @return list<string> */
    private function collectMasked(array $original, array $masked): array
    {
        $found = [];
        foreach ($original as $key => $value) {
            $after = $masked[$key] ?? null;
            if (is_array($value) && is_array($after)) {
                $found = array_merge($found, $this->collectMasked($value, $after));
            } elseif ($after === self::MASK && is_string($value) && $value !== '') {
                $found[] = $value;
            }
        }

        return $found;
    }

    /**
     * A last line of defence for free text that is about to be persisted: an error message, a
     * connector response summary. If a known secret value appears in it, the value is replaced.
     *
     * Used for exception messages, because a connector that interpolates a token into its own error
     * string would otherwise write it straight into `tool_calls.error`.
     *
     * @param  list<string>  $secrets
     */
    public function scrub(?string $text, array $secrets): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }
        foreach ($secrets as $secret) {
            // Short values are skipped: replacing every occurrence of a four-character argument
            // would shred the message without protecting anything worth protecting.
            if (is_string($secret) && strlen($secret) >= 8) {
                $text = str_replace($secret, self::MASK, $text);
            }
        }

        return $text;
    }
}
