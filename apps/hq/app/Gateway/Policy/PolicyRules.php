<?php

namespace App\Gateway\Policy;

use App\Gateway\Descriptor\RiskTier;
use Carbon\CarbonImmutable;

/**
 * A typed reader over a policy row's `rules` JSON (docs/48 F.3).
 *
 * Deliberately NOT a rule language. There is no expression evaluator, no external policy service
 * and no configuration interpreter: the logic is PHP that a reviewer can read, and the JSON supplies
 * only values. ADR-004 made that choice and M2 keeps it.
 *
 * Recognised keys, all optional:
 *
 *   deny:                bool + deny_reason
 *   require_approval:    bool + reason
 *   dry_run_only:        bool + reason
 *   relax_to:            "allow"   (see relaxation rules below)
 *   relax_reason:        string, REQUIRED when relax_to is present
 *   max_calls_per_hour:  int
 *   max_calls_per_day:   int
 *   quiet_hours:         {from: "21:00", to: "08:00"}   in the business timezone
 *   arg_max:             {"hours": 200}                 numeric ceilings on named arguments
 *   arg_min:             {"hours": 1}
 *   arg_allowed:         {"store_slug": ["laladia-com"]}
 *
 * ⚠ An unrecognised key is a configuration error and is REFUSED, not ignored. A rule nobody
 * implemented that silently does nothing is worse than no rule at all, because somebody believes it
 * is protecting them.
 */
final readonly class PolicyRules
{
    private const KNOWN = [
        'deny', 'deny_reason',
        'require_approval', 'reason',
        'dry_run_only',
        'relax_to', 'relax_reason',
        'max_calls_per_hour', 'max_calls_per_day',
        'quiet_hours',
        'arg_max', 'arg_min', 'arg_allowed',
    ];

    public function __construct(public array $rules = []) {}

    /** @return list<string> the unrecognised keys, empty when the rule set is understood */
    public function unknownKeys(): array
    {
        return array_values(array_diff(array_keys($this->rules), self::KNOWN));
    }

    public function denies(): bool
    {
        return (bool) ($this->rules['deny'] ?? false);
    }

    public function denyReason(): string
    {
        return (string) ($this->rules['deny_reason'] ?? 'refused by policy');
    }

    public function requiresApproval(): bool
    {
        return (bool) ($this->rules['require_approval'] ?? false);
    }

    public function dryRunOnly(): bool
    {
        return (bool) ($this->rules['dry_run_only'] ?? false);
    }

    public function reason(): string
    {
        return (string) ($this->rules['reason'] ?? 'required by policy');
    }

    /**
     * A guardrail may LOOSEN the tier baseline only when it says so explicitly, gives a reason, and
     * the tier is not R5.
     *
     * R5 is material or irreversible: money, a customer-visible message, a publication, a deletion.
     * No amount of configuration turns that into an automatic action; that needs a code change and
     * a review, which is a different kind of decision made by a different mechanism (ADR-017).
     */
    public function relaxesTo(RiskTier $tier): ?string
    {
        $to = $this->rules['relax_to'] ?? null;
        if ($to === null) {
            return null;
        }
        if ($tier === RiskTier::R5) {
            return null;
        }
        if (trim((string) ($this->rules['relax_reason'] ?? '')) === '') {
            return null; // an unexplained relaxation is not a relaxation
        }

        return is_string($to) ? $to : null;
    }

    public function relaxReason(): string
    {
        return (string) ($this->rules['relax_reason'] ?? '');
    }

    public function maxCallsPerHour(): ?int
    {
        return isset($this->rules['max_calls_per_hour']) ? (int) $this->rules['max_calls_per_hour'] : null;
    }

    public function maxCallsPerDay(): ?int
    {
        return isset($this->rules['max_calls_per_day']) ? (int) $this->rules['max_calls_per_day'] : null;
    }

    /** True when `now` falls inside the configured quiet window, which may wrap past midnight. */
    public function inQuietHours(CarbonImmutable $now): bool
    {
        $window = $this->rules['quiet_hours'] ?? null;
        if (! is_array($window) || ! isset($window['from'], $window['to'])) {
            return false;
        }
        $minutes = fn (string $hhmm): int => ((int) substr($hhmm, 0, 2)) * 60 + (int) substr($hhmm, 3, 2);

        $from = $minutes((string) $window['from']);
        $to = $minutes((string) $window['to']);
        $at = $now->hour * 60 + $now->minute;

        return $from <= $to
            ? ($at >= $from && $at < $to)
            : ($at >= $from || $at < $to); // wraps midnight
    }

    /**
     * Numeric ceilings and floors and allowed-value lists on named arguments.
     *
     * @return string|null the reason the arguments are refused, or null when they pass
     */
    public function argumentProblem(array $args): ?string
    {
        foreach ((array) ($this->rules['arg_max'] ?? []) as $name => $max) {
            if (isset($args[$name]) && is_numeric($args[$name]) && $args[$name] > $max) {
                return "[{$name}] may be at most {$max} under this policy.";
            }
        }
        foreach ((array) ($this->rules['arg_min'] ?? []) as $name => $min) {
            if (isset($args[$name]) && is_numeric($args[$name]) && $args[$name] < $min) {
                return "[{$name}] must be at least {$min} under this policy.";
            }
        }
        foreach ((array) ($this->rules['arg_allowed'] ?? []) as $name => $allowed) {
            if (isset($args[$name]) && is_array($allowed) && ! in_array($args[$name], $allowed, true)) {
                return "[{$name}] is not permitted by this policy.";
            }
        }

        return null;
    }
}
