<?php

namespace App\Gateway\Identity;

use App\Models\Agent;
use App\Models\User;

/**
 * Who a gateway call runs as.
 *
 * An agent never has authority of its own: it borrows a human's, and the effective set is the
 * INTERSECTION of the two, never a union (docs/48 E.3). There is deliberately no wildcard identity,
 * no service account with every permission and no break-glass path, because a break-glass path is
 * the thing an attacker aims at. If Joy needs something an agent cannot do, he does it as himself.
 *
 * The `system` identity is what unattended internal work runs as. It is narrow by construction: its
 * permissions are a fixed list in config('hq.gateway.system_permissions'), and it can hold nothing
 * that is not written there.
 */
final class ActingIdentity
{
    private function __construct(
        public readonly IdentityKind $kind,
        public readonly string $label,
        public readonly ?User $user = null,
        public readonly ?Agent $agent = null,
        public readonly ?string $serviceKey = null,
    ) {}

    public static function human(User $user): self
    {
        return new self(IdentityKind::Human, $user->email ?? "user:{$user->getKey()}", $user);
    }

    /** An agent always names the human whose authority it borrows. There is no agent without one. */
    public static function agent(Agent $agent, User $enabling): self
    {
        return new self(IdentityKind::Agent, "agent:{$agent->key}", $enabling, $agent);
    }

    public static function service(string $serviceKey, ?User $enabling = null): self
    {
        return new self(IdentityKind::Service, "service:{$serviceKey}", $enabling, null, $serviceKey);
    }

    /** Unattended internal work with no owning human: the scheduler tick, the event drain. */
    public static function system(): self
    {
        return new self(IdentityKind::Service, 'system', null, null, 'system');
    }

    /**
     * A scheduled job runs as its owning human. A job with no owner falls back to `system`, which
     * holds only the narrow configured list - deliberately narrow enough that the five Phase 1 poll
     * and reconcile jobs fit inside it and anything wider must name an owner.
     */
    public static function forJob(?User $owner): self
    {
        return $owner ? self::human($owner) : self::system();
    }

    public function isAgent(): bool
    {
        return $this->kind === IdentityKind::Agent;
    }

    /** The human whose authority is being used, if any. An agent's enabling human counts. */
    public function enablingUser(): ?User
    {
        return $this->user;
    }

    public function actorId(): ?int
    {
        return $this->kind === IdentityKind::Human ? $this->user?->getKey() : null;
    }

    /**
     * The intersection, evaluated in one place.
     *
     * Every term narrows. Nothing here can widen, there is no fallback branch, and an unknown
     * identity kind returns false rather than defaulting to permitted.
     */
    public function holds(string $permission, string $toolKey): bool
    {
        return match ($this->kind) {
            IdentityKind::Human => (bool) $this->user?->can($permission),

            // Agent: the enabling human must hold it AND the agent must be allowed this tool AND
            // the agent must be active. Any one of those failing is a refusal.
            IdentityKind::Agent => (bool) $this->user?->can($permission)
                && $this->agent !== null
                && $this->agent->isActive()
                && $this->agent->mayUseTool($toolKey),

            // Service: an enabling human's permissions when one is named, otherwise the fixed list.
            IdentityKind::Service => $this->user !== null
                ? (bool) $this->user->can($permission)
                : in_array($permission, (array) config('hq.gateway.system_permissions', []), true),

            // A credential is not an authorization. It can never satisfy a permission check.
            IdentityKind::Credential => false,
        };
    }
}
