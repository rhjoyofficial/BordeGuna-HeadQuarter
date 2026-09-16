<?php

namespace App\Observe\Alerts;

use InvalidArgumentException;

/**
 * One thing a rule found wrong right now (docs/78 B.2).
 *
 * `subjectKey` says what the condition is about within its rule (`global`, `agent:executive`,
 * `credential:14`), so the rule key and the subject key together are the fingerprint that keeps one
 * open alert per condition. The subject and body are what a person reads: counts, names and what to
 * do, never a secret and never a customer's personal data.
 */
final readonly class AlertCondition
{
    public const SEVERITIES = ['info' => 0, 'warning' => 1, 'critical' => 2];

    /** @param array<string, mixed> $context */
    public function __construct(
        public string $subjectKey,
        public string $severity,
        public string $subject,
        public string $body,
        public array $context = [],
    ) {
        if (! array_key_exists($severity, self::SEVERITIES)) {
            throw new InvalidArgumentException("An alert severity is info, warning or critical, not [{$severity}].");
        }
    }

    public static function rank(string $severity): int
    {
        return self::SEVERITIES[$severity] ?? 0;
    }
}
