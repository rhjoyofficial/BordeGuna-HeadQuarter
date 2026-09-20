<?php

namespace App\Execution;

use App\Models\Agent;
use App\Models\User;

/**
 * The authority one agent run has, resolved once (docs/80 point 3, ADR-047).
 *
 * ```
 * effective =  requester
 *              AND enabling identity
 *              AND agent (its tool list, its business and brand scope)
 *              AND business / brand (a store is in scope exactly when its business is)
 *              AND tool permission, held by BOTH people
 *              -- and, per call, under this same object, by the gateway --
 *              AND runtime switch AND policy AND approval AND consent AND capability readiness
 * ```
 *
 * It is an intersection at every step, never a union and never a fallback. The enabling user can
 * never give the asker more than the asker has; the asker can never leave the enabling user's
 * authority; and a scheduled or system invocation is resolved by the same resolver as a person's.
 *
 * ⭐ **What the agent is shown and what the gateway checks are the same object.** The catalogue
 * (`AgentToolset`), the business directory (`ContextAssembler`), the group expansion
 * (`AgentRunner`) and the gateway's permission, scope and cross-business filter all read THIS.
 * Two lists computed in two places eventually disagree, and the one that is wrong is the one shown
 * to the model.
 *
 * ⛔ Built only by `ExecutionContextResolver`. A test reads the sources to hold that: a context
 * somebody assembled by hand is authority nobody resolved.
 *
 * ⚠ Nothing secret is in it, by construction: user ids, the agent key, business and brand ids,
 * tool keys, permission NAMES and sentences. It holds no credential, no token and no address.
 */
final readonly class EffectiveExecutionContext
{
    public const VERSION = 'eec.v1';

    /** The terms that need a call's arguments, so the gateway decides them per call under this context. */
    public const DECIDED_PER_CALL = ['runtime_switch', 'policy', 'approval', 'consent', 'credentials', 'capability_readiness'];

    /**
     * @param  list<int>|null  $businessIds  null is group-wide: no term narrowed it. A list is exactly those.
     * @param  list<int>|null  $brandIds  null is every brand of the businesses in scope. A list is exactly those.
     * @param  list<string>  $toolKeys  the agent's tools whose permission BOTH people hold
     * @param  list<string>  $permissions  the permission names behind `$toolKeys`
     * @param  list<array{term: string, by: string, why: string}>  $narrowed
     */
    public function __construct(
        public Requester $requester,
        public User $requesterUser,
        public User $enabler,
        public Agent $agent,
        public string $entryPoint,
        public string $environment,
        public ?int $businessId,
        public ?int $brandId,
        public ?array $businessIds,
        public ?array $brandIds,
        public array $toolKeys,
        public array $permissions,
        public array $narrowed,
    ) {}

    /** The label every gateway row and audit line already uses for an agent call. */
    public function actingIdentity(): string
    {
        return "agent:{$this->agent->key}";
    }

    public function requesterIsEnabler(): bool
    {
        return (int) $this->requesterUser->getKey() === (int) $this->enabler->getKey();
    }

    /** A null business is a call that is not about one business; whether the tool needs one is the gateway's question. */
    public function allowsBusiness(?int $businessId): bool
    {
        return $businessId === null || $this->businessIds === null || in_array($businessId, $this->businessIds, true);
    }

    public function allowsBrand(?int $brandId): bool
    {
        return $brandId === null || $this->brandIds === null || in_array($brandId, $this->brandIds, true);
    }

    public function permitsTool(string $toolKey): bool
    {
        return in_array($toolKey, $this->toolKeys, true);
    }

    /**
     * The permission term, asked live of BOTH people and of the resolved tool set.
     *
     * Live as well as resolved, deliberately: the resolved set is what the agent was shown, and
     * the live check is what stops a permission revoked a second ago. Either one failing refuses.
     */
    public function holds(string $permission, string $toolKey): bool
    {
        return $this->permitsTool($toolKey)
            && (bool) $this->enabler->can($permission)
            && (bool) $this->requesterUser->can($permission);
    }

    /** sha256 over the authority-bearing parts, so two runs can be compared without reading both. */
    public function digest(): string
    {
        return hash('sha256', (string) json_encode($this->authority(), JSON_UNESCAPED_SLASHES));
    }

    /**
     * What is stored on the run and written to the audit log.
     *
     * @return array<string, mixed>
     */
    public function toAuditArray(): array
    {
        return ['resolver_version' => self::VERSION] + $this->authority() + [
            'requester_is_enabler' => $this->requesterIsEnabler(),
            'decided_per_call' => self::DECIDED_PER_CALL,
            'narrowed' => $this->narrowed,
            'digest' => $this->digest(),
        ];
    }

    /** @return array<string, mixed> */
    private function authority(): array
    {
        return [
            'requester' => [
                'kind' => $this->requester->kind->value,
                'user_id' => (int) $this->requesterUser->getKey(),
                'as_enabled' => $this->requester->asEnabled,
                'basis' => $this->requester->basis,
            ],
            'enabler' => ['user_id' => (int) $this->enabler->getKey()],
            'acting_identity' => $this->actingIdentity(),
            'agent' => ['id' => (int) $this->agent->getKey(), 'key' => (string) $this->agent->key, 'role' => $this->agent->role_key],
            'entry_point' => $this->entryPoint,
            'environment' => $this->environment,
            'scope' => [
                'requested_business_id' => $this->businessId,
                'requested_brand_id' => $this->brandId,
                'business_ids' => $this->businessIds,
                'brand_ids' => $this->brandIds,
                'stores' => 'a store is in scope exactly when its business is; HQ holds no per-store grant',
            ],
            'tools' => $this->toolKeys,
            'permissions' => $this->permissions,
        ];
    }
}
