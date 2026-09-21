<?php

namespace App\Autonomy\Evidence;

use App\Evaluation\DeterministicGrader;
use App\Evaluation\EvaluationDimension;
use App\Models\AutonomyEvidenceHardCheck;
use App\Models\AutonomyEvidencePolicy;
use App\Models\AutonomyEvidencePolicyEvent;
use App\Models\AutonomyEvidenceRequirement;
use App\Models\User;
use App\Support\Audit\Auditor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * The one writer of autonomy evidence policy versions (ADR-048).
 *
 * Three acts, each recorded twice (an append-only event row and an audit row under the named human):
 *
 * - `propose()`: a new version from a definition. Numbers MAY be null here: a proposal is how the
 *   undecided is written down and shown before anybody decides it.
 * - `activate()`: the explicit, audited act of a named, enabled person holding `hq.policies.manage`.
 *   It refuses a version with any undecided field, with no requirement, or with a gap in the ladder,
 *   and the database refuses the same things again underneath it. The previously active version is
 *   retired in the same transaction.
 * - `retire()`: the active version withdrawn, by a named person with a reason. No version is active
 *   afterwards, so evidence can widen nothing until another is activated.
 *
 * ⛔ Nothing here creates, edits or promotes an autonomy grant. The policy is a necessary condition
 * the resolver adds; it is never sufficient by itself.
 */
final class EvidencePolicies
{
    public const PERMISSION = 'hq.policies.manage';

    /** The levels a requirement may name. A0 needs no evidence; A5 is unreachable (ADR-020). */
    public const LEVELS = ['a1', 'a2', 'a3', 'a4'];

    public function __construct(private readonly Auditor $auditor) {}

    public function active(): ?AutonomyEvidencePolicy
    {
        return AutonomyEvidencePolicy::query()->where('status', AutonomyEvidencePolicy::ACTIVE)->first();
    }

    /**
     * A new proposed version. Shape:
     *
     *   {"name": "...", "summary": "...",
     *    "requirements": [{"level": "a1", "dimension": "tool_selection", "min_agreement_rate": null,
     *                      "min_sample_count": null, "max_evidence_age_days": null, "note": "..."}],
     *    "hard_checks":  [{"level": "a1", "check": "must_not_call", "min_sample_count": null,
     *                      "max_evidence_age_days": null, "note": "..."}]}
     *
     * Structure is validated now (levels, dimensions, check keys, duplicates, ranges when a value is
     * given). Numbers may be null: that is the state "not decided", and it is shown as such.
     *
     * @param  array<string, mixed>  $definition
     */
    public function propose(array $definition, ?User $by = null, ?string $reason = null): AutonomyEvidencePolicy
    {
        $problems = $this->definitionProblems($definition);
        if ($problems !== []) {
            throw new InvalidArgumentException('The policy definition is not valid: '.implode('; ', $problems));
        }

        return DB::transaction(function () use ($definition, $by, $reason) {
            $policy = AutonomyEvidencePolicy::create([
                'uuid' => (string) Str::uuid(),
                'version' => ((int) AutonomyEvidencePolicy::query()->max('version')) + 1,
                'name' => (string) $definition['name'],
                'summary' => isset($definition['summary']) ? (string) $definition['summary'] : null,
                'status' => AutonomyEvidencePolicy::PROPOSED,
                'supersedes_id' => $this->active()?->id,
                'proposed_by' => $by?->getKey(),
                'proposed_at' => now(),
            ]);

            foreach ($definition['requirements'] as $row) {
                AutonomyEvidenceRequirement::create([
                    'policy_id' => $policy->id,
                    'autonomy_level' => $row['level'],
                    'dimension' => $row['dimension'],
                    'min_agreement_rate' => $this->numberOrNull($row['min_agreement_rate'] ?? null),
                    'min_sample_count' => $this->intOrNull($row['min_sample_count'] ?? null),
                    'max_evidence_age_days' => $this->intOrNull($row['max_evidence_age_days'] ?? null),
                    'required_measured_state' => AutonomyEvidenceRequirement::FULLY_MEASURED,
                    'note' => isset($row['note']) ? (string) $row['note'] : null,
                ]);
            }

            foreach ($definition['hard_checks'] ?? [] as $row) {
                AutonomyEvidenceHardCheck::create([
                    'policy_id' => $policy->id,
                    'autonomy_level' => $row['level'],
                    'check_key' => $row['check'],
                    'min_sample_count' => $this->intOrNull($row['min_sample_count'] ?? null),
                    'max_evidence_age_days' => $this->intOrNull($row['max_evidence_age_days'] ?? null),
                    'note' => isset($row['note']) ? (string) $row['note'] : null,
                ]);
            }

            $policy->load(['requirements', 'hardChecks']);
            $this->event($policy, 'proposed', $by, $reason);
            $this->auditor->record('autonomy.evidence_policy.proposed', $policy, after: $this->snapshot($policy), reason: $reason, actorUserId: $by?->getKey());

            return $policy;
        });
    }

    /**
     * Why this version cannot be activated by this person now. Empty means it can.
     *
     * @return list<string>
     */
    public function activationRefusals(AutonomyEvidencePolicy $policy, ?User $by, ?string $reason): array
    {
        $policy->loadMissing(['requirements', 'hardChecks']);
        $refusals = [];

        if ($policy->status !== AutonomyEvidencePolicy::PROPOSED) {
            $refusals[] = "version {$policy->version} is {$policy->status}, and only a proposed version can be activated";
        }

        if ($by === null) {
            $refusals[] = 'no person named: activation is the act of a named human (--by)';
        } elseif (! $by->is_active) {
            $refusals[] = "user {$by->email} is disabled";
        } elseif (! $by->hasPermissionTo(self::PERMISSION)) {
            $refusals[] = "user {$by->email} does not hold ".self::PERMISSION;
        }

        if (trim((string) $reason) === '') {
            $refusals[] = 'no reason given (--reason)';
        }

        return [...$refusals, ...$this->structuralRefusals($policy)];
    }

    /**
     * What in the version itself stops it becoming active, whoever asks: no requirement, an
     * undecided (NULL) field, an unknown measured state, a gap in the ladder. Empty means the
     * version is complete, which is necessary for activation and never sufficient.
     *
     * @return list<string>
     */
    public function structuralRefusals(AutonomyEvidencePolicy $policy): array
    {
        $policy->loadMissing(['requirements', 'hardChecks']);
        $refusals = [];

        if ($policy->requirements->isEmpty()) {
            $refusals[] = 'the version has no requirement row, so it would make nothing eligible and nothing explicit';
        }

        foreach ($policy->requirements as $requirement) {
            foreach ($requirement->undecidedFields() as $field) {
                $refusals[] = "{$requirement->autonomy_level} {$requirement->dimension}: {$field} is not decided (NULL)";
            }
            if ($requirement->required_measured_state !== AutonomyEvidenceRequirement::FULLY_MEASURED) {
                $refusals[] = "{$requirement->autonomy_level} {$requirement->dimension}: required_measured_state {$requirement->required_measured_state} is not a known state";
            }
        }

        foreach ($policy->hardChecks as $check) {
            foreach ($check->undecidedFields() as $field) {
                $refusals[] = "{$check->autonomy_level} hard check {$check->check_key}: {$field} is not decided (NULL)";
            }
        }

        // The ladder is cumulative (ADR-048 rule 3): a2 with no a1 rows is a level nothing can reach.
        $named = $policy->requirements->pluck('autonomy_level')->unique()->values()->all();
        foreach (self::LEVELS as $i => $level) {
            if (in_array($level, $named, true) && $i > 0 && ! in_array(self::LEVELS[$i - 1], $named, true)) {
                $refusals[] = "{$level} has requirements but ".self::LEVELS[$i - 1].' has none, so nothing could ever reach it';
            }
        }

        return $refusals;
    }

    public function activate(AutonomyEvidencePolicy $policy, ?User $by, ?string $reason): AutonomyEvidencePolicy
    {
        $refusals = $this->activationRefusals($policy, $by, $reason);
        if ($refusals !== []) {
            throw new RuntimeException('Not activated: '.implode('; ', $refusals));
        }

        return DB::transaction(function () use ($policy, $by, $reason) {
            $previous = $this->active();
            if ($previous !== null) {
                $this->retireRow($previous, $by, "superseded by version {$policy->version}: {$reason}");
            }

            $policy->forceFill([
                'status' => AutonomyEvidencePolicy::ACTIVE,
                'supersedes_id' => $previous?->id ?? $policy->supersedes_id,
                'activated_by' => $by->getKey(),
                'activated_at' => now(),
                'activation_reason' => $reason,
                'content_digest' => $this->digest($policy),
            ])->save();

            $policy->refresh()->load(['requirements', 'hardChecks']);
            $this->event($policy, 'activated', $by, $reason);
            $this->auditor->record('autonomy.evidence_policy.activated', $policy,
                before: ['active_version' => $previous?->version], after: $this->snapshot($policy), reason: $reason, actorUserId: $by->getKey());

            return $policy;
        });
    }

    /**
     * ⚠ Acts on the version AS THE DATABASE HAS IT (docs/81 C.9): the row is read again by id and
     * locked inside the transaction, its status is judged there, and that row is the one retired and
     * returned. Until then this judged the caller's in-memory status and saved the caller's instance,
     * so an unsaved edit the caller held was written with the retirement (the trigger refused it) and a
     * copy loaded before somebody else retired the version was still "active". The caller's instance
     * is left as it was.
     */
    public function retire(AutonomyEvidencePolicy $policy, ?User $by, ?string $reason): AutonomyEvidencePolicy
    {
        return DB::transaction(function () use ($policy, $by, $reason) {
            $fresh = AutonomyEvidencePolicy::query()->lockForUpdate()->find($policy->getKey());

            $refusals = [];
            if ($fresh === null) {
                $refusals[] = "version {$policy->version} does not exist";
            } elseif ($fresh->status !== AutonomyEvidencePolicy::ACTIVE) {
                $refusals[] = "version {$fresh->version} is {$fresh->status}, and only the active version can be retired";
            }
            if ($by === null) {
                $refusals[] = 'no person named (--by)';
            } elseif (! $by->is_active) {
                $refusals[] = "user {$by->email} is disabled";
            } elseif (! $by->hasPermissionTo(self::PERMISSION)) {
                $refusals[] = "user {$by->email} does not hold ".self::PERMISSION;
            }
            if (trim((string) $reason) === '') {
                $refusals[] = 'no reason given (--reason)';
            }
            if ($refusals !== []) {
                throw new RuntimeException('Not retired: '.implode('; ', $refusals));
            }

            $this->retireRow($fresh, $by, $reason);
            $this->auditor->record('autonomy.evidence_policy.retired', $fresh, before: ['status' => 'active'], after: ['status' => 'retired'], reason: $reason, actorUserId: $by->getKey());

            return $fresh;
        });
    }

    /**
     * The whole version as data, in a canonical order: what the digest is taken over, what an event
     * row snapshots, what a run records the version of.
     *
     * @return array<string, mixed>
     */
    public function snapshot(AutonomyEvidencePolicy $policy): array
    {
        $policy->loadMissing(['requirements', 'hardChecks']);

        $requirements = $policy->requirements->map(fn (AutonomyEvidenceRequirement $r) => [
            'level' => $r->autonomy_level,
            'dimension' => $r->dimension,
            'min_agreement_rate' => $r->min_agreement_rate === null ? null : $this->thousandths($r->min_agreement_rate),
            'min_sample_count' => $r->min_sample_count,
            'max_evidence_age_days' => $r->max_evidence_age_days,
            'required_measured_state' => $r->required_measured_state,
        ])->sortBy(fn (array $r) => $r['level'].'|'.$r['dimension'])->values()->all();

        $checks = $policy->hardChecks->map(fn (AutonomyEvidenceHardCheck $c) => [
            'level' => $c->autonomy_level,
            'check' => $c->check_key,
            'min_sample_count' => $c->min_sample_count,
            'max_evidence_age_days' => $c->max_evidence_age_days,
        ])->sortBy(fn (array $c) => $c['level'].'|'.$c['check'])->values()->all();

        return [
            'policy_id' => (int) $policy->id,
            'uuid' => $policy->uuid,
            'version' => (int) $policy->version,
            'name' => $policy->name,
            'status' => $policy->status,
            'requirements' => $requirements,
            'hard_checks' => $checks,
        ];
    }

    /** sha256 over the requirement set alone: the same rules give the same digest whatever the row ids. */
    public function digest(AutonomyEvidencePolicy $policy): string
    {
        $snapshot = $this->snapshot($policy);

        return hash('sha256', (string) json_encode([
            'requirements' => $snapshot['requirements'], 'hard_checks' => $snapshot['hard_checks'],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Structural problems with a definition. Values may be null; a given value must be in range.
     *
     * @param  array<string, mixed>  $definition
     * @return list<string>
     */
    public function definitionProblems(array $definition): array
    {
        $problems = [];
        if (trim((string) ($definition['name'] ?? '')) === '') {
            $problems[] = 'name is required';
        }

        $requirements = $definition['requirements'] ?? null;
        if (! is_array($requirements)) {
            $problems[] = 'requirements must be a list';
            $requirements = [];
        }

        $seen = [];
        foreach ($requirements as $i => $row) {
            $where = "requirements[{$i}]";
            if (! is_array($row)) {
                $problems[] = "{$where} must be an object";

                continue;
            }
            $level = $row['level'] ?? null;
            $dimension = $row['dimension'] ?? null;
            if (! in_array($level, self::LEVELS, true)) {
                $problems[] = "{$where}: level must be one of ".implode(', ', self::LEVELS).' (a0 needs no evidence, a5 is unreachable)';
            }
            if (! is_string($dimension) || EvaluationDimension::tryFrom($dimension) === null) {
                $problems[] = "{$where}: dimension must be one of ".implode(', ', array_map(fn (EvaluationDimension $d) => $d->value, EvaluationDimension::cases())).' (there is no overall row, ADR-030)';
            }
            if (isset($seen["{$level}|{$dimension}"])) {
                $problems[] = "{$where}: {$level} {$dimension} appears twice";
            }
            $seen["{$level}|{$dimension}"] = true;

            $rate = $row['min_agreement_rate'] ?? null;
            if ($rate !== null && (! is_numeric($rate) || (float) $rate <= 0 || (float) $rate > 1)) {
                $problems[] = "{$where}: min_agreement_rate must be null (undecided) or a number above 0 and at most 1";
            }
            foreach (['min_sample_count', 'max_evidence_age_days'] as $field) {
                $value = $row[$field] ?? null;
                if ($value !== null && (! is_int($value) || $value < 1)) {
                    $problems[] = "{$where}: {$field} must be null (undecided) or a whole number of at least 1";
                }
            }
            if (isset($row['required_measured_state']) && $row['required_measured_state'] !== AutonomyEvidenceRequirement::FULLY_MEASURED) {
                $problems[] = "{$where}: required_measured_state can only be ".AutonomyEvidenceRequirement::FULLY_MEASURED.' (an unmeasured case blocks; a laxer state needs its own ADR)';
            }
        }

        $checks = $definition['hard_checks'] ?? [];
        if (! is_array($checks)) {
            $problems[] = 'hard_checks must be a list when given';
            $checks = [];
        }
        $seen = [];
        foreach ($checks as $i => $row) {
            $where = "hard_checks[{$i}]";
            if (! is_array($row)) {
                $problems[] = "{$where} must be an object";

                continue;
            }
            $level = $row['level'] ?? null;
            $check = $row['check'] ?? null;
            if (! in_array($level, self::LEVELS, true)) {
                $problems[] = "{$where}: level must be one of ".implode(', ', self::LEVELS);
            }
            if (! is_string($check) || ! in_array($check, DeterministicGrader::CHECKS, true)) {
                $problems[] = "{$where}: check must be a grader check key: ".implode(', ', DeterministicGrader::CHECKS);
            }
            if (isset($seen["{$level}|{$check}"])) {
                $problems[] = "{$where}: {$level} {$check} appears twice";
            }
            $seen["{$level}|{$check}"] = true;
            foreach (['min_sample_count', 'max_evidence_age_days'] as $field) {
                $value = $row[$field] ?? null;
                if ($value !== null && (! is_int($value) || $value < 1)) {
                    $problems[] = "{$where}: {$field} must be null (undecided) or a whole number of at least 1";
                }
            }
        }

        return $problems;
    }

    private function retireRow(AutonomyEvidencePolicy $policy, User $by, string $reason): void
    {
        $policy->forceFill([
            'status' => AutonomyEvidencePolicy::RETIRED,
            'retired_by' => $by->getKey(),
            'retired_at' => now(),
            'retirement_reason' => $reason,
        ])->save();

        $policy->refresh()->load(['requirements', 'hardChecks']);
        $this->event($policy, 'retired', $by, $reason);
    }

    private function event(AutonomyEvidencePolicy $policy, string $event, ?User $by, ?string $reason): void
    {
        AutonomyEvidencePolicyEvent::create([
            'policy_id' => $policy->id,
            'event' => $event,
            'actor_user_id' => $by?->getKey(),
            'actor_label' => $by?->email ?? 'system',
            'reason' => $reason,
            'snapshot' => $this->snapshot($policy),
            'content_digest' => $this->digest($policy),
            'occurred_at' => now(),
        ]);
    }

    private function numberOrNull(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    private function intOrNull(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    /** A rate as a whole number of thousandths, so a snapshot never carries float noise. */
    private function thousandths(float $rate): int
    {
        return (int) round($rate * 1000);
    }
}
