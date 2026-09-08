<?php

namespace App\Gateway\Policy;

use App\Models\Policy;
use App\Models\User;
use App\Support\Audit\Auditor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Selects the policy that governs a call, and is the only place a policy is changed.
 *
 * Two properties matter more than anything else here:
 *
 *  1. **Selection is deterministic.** Most specific first (tool and business, then tool, then
 *     business, then global), first match wins, and that row's rules are the whole rule set. No
 *     merging: "which rule decided this" must have a one-line answer.
 *
 *  2. **A change never rewrites history.** `supersede()` closes the current version and opens a new
 *     one in a transaction. A `tool_calls` row that points at version 3 still resolves to a row
 *     whose rules read exactly as they did when it decided.
 */
final class PolicyRepository
{
    public function __construct(private readonly Auditor $auditor) {}

    /** The governing policy for this tool and business at this instant, or null when none matches. */
    public function forCall(string $toolKey, ?int $businessId, ?CarbonImmutable $at = null): ?Policy
    {
        $at ??= CarbonImmutable::now();

        return Policy::query()
            ->activeAt($at)
            ->where(fn ($q) => $q->whereNull('tool_key')->orWhere('tool_key', $toolKey))
            ->where(fn ($q) => $q->whereNull('business_id')->orWhere('business_id', $businessId))
            ->mostSpecificFirst()
            ->first();
    }

    /**
     * Opens the first version of a policy.
     *
     * @param  array<string, mixed>  $rules
     */
    public function open(string $key, array $rules, ?string $toolKey = null, ?int $businessId = null, ?string $note = null, ?User $by = null): Policy
    {
        $policy = Policy::create([
            'key' => $key,
            'version' => 1,
            'tool_key' => $toolKey,
            'business_id' => $businessId,
            'rules' => $rules,
            'note' => $note,
            'active_from' => CarbonImmutable::now(),
            'created_by' => $by?->getKey(),
        ]);

        $this->auditor->record('policy.opened', $policy, after: $policy->toArray(), reason: $note, actorUserId: $by?->getKey());

        return $policy;
    }

    /**
     * Closes the current version and opens the next one, atomically.
     *
     * The old row keeps its rules verbatim. This is the whole reproducibility guarantee, and it is
     * why there is no `update the rules` method on this class at all.
     *
     * @param  array<string, mixed>  $rules
     */
    public function supersede(Policy $current, array $rules, ?string $note = null, ?User $by = null): Policy
    {
        return DB::transaction(function () use ($current, $rules, $note, $by) {
            $at = CarbonImmutable::now();

            $current->active_to = $at;
            $current->save();

            $next = Policy::create([
                'key' => $current->key,
                'version' => $current->version + 1,
                'tool_key' => $current->tool_key,
                'business_id' => $current->business_id,
                'rules' => $rules,
                'note' => $note,
                'active_from' => $at,
                'created_by' => $by?->getKey(),
            ]);

            $this->auditor->record(
                'policy.superseded',
                $next,
                before: ['version' => $current->version, 'rules' => $current->rules],
                after: ['version' => $next->version, 'rules' => $next->rules],
                reason: $note,
                actorUserId: $by?->getKey(),
            );

            return $next;
        });
    }

    /** Closes a policy without a successor. Everything then falls back to the tier baseline. */
    public function close(Policy $policy, ?string $note = null, ?User $by = null): Policy
    {
        $policy->active_to = CarbonImmutable::now();
        $policy->save();

        $this->auditor->record('policy.closed', $policy, after: $policy->toArray(), reason: $note, actorUserId: $by?->getKey());

        return $policy;
    }
}
