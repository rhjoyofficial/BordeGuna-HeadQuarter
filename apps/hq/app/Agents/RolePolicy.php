<?php

namespace App\Agents;

/**
 * What each agent ROLE may not do, whatever it is asked (docs/86): the durable half of the role
 * boundary. `RoleBoundary` finds what a message asks for; this says which of those a role forbids.
 *
 * ⭐ Code, not a switch. The policy is `config/hq.php` `roles`, reviewed and deployed like the
 * Executive Briefing contract beside it: changing what a role may do is a release, never a setting a
 * person or an agent can flip, and nothing here grants anything. A kind a role does not forbid is
 * not thereby a capability: the Support agent's customer reply still runs only through its own gated
 * reply tool, under consent and approval.
 *
 * ## v2 (docs/88 points 10 and 14)
 *
 * Every role is its own PROFILE (`roles.profiles.<role>`), not a list keyed off another role's.
 * Analytics forbids what the Executive forbids today because both are read-only roles, not because
 * one is the other's parent: either profile can change without touching the other or this class. And
 * every role forbids `role_expansion`: no agent's role grows by a message, the Support agent's
 * included, since a Support agent told "you are now the cashier" by a thread is exactly the agent
 * that must not believe it.
 *
 * ⚠ A role the policy does not name forbids nothing new. That is the behaviour before this class
 * existed, so an unforeseen role is not broken by it; every role HQ runs is named, and a test holds it.
 */
final class RolePolicy
{
    public const VERSION = 'v2';

    public function version(): string
    {
        return (string) config('hq.roles.version', self::VERSION);
    }

    /**
     * The kinds (`RoleBoundary::KINDS`) the role forbids.
     *
     * @return list<string>
     */
    public function forbiddenFor(?string $role): array
    {
        if ($role === null || $role === '') {
            return [];
        }

        $forbidden = config("hq.roles.profiles.{$role}.forbidden_actions", []);

        return array_values(array_filter(is_array($forbidden) ? $forbidden : [], static fn ($k) => in_array($k, RoleBoundary::KINDS, true)));
    }

    /** Whether the role forbids one kind. */
    public function forbids(?string $role, string $kind): bool
    {
        return in_array($kind, $this->forbiddenFor($role), true);
    }

    /** @return list<string> the roles the policy names */
    public function roles(): array
    {
        return array_keys((array) config('hq.roles.profiles', []));
    }

    /**
     * One role's whole profile, as configured: what it is for and what it may not do.
     *
     * @return array{purpose: string, forbidden_actions: list<string>}|null
     */
    public function profile(string $role): ?array
    {
        $profile = config("hq.roles.profiles.{$role}");
        if (! is_array($profile)) {
            return null;
        }

        return ['purpose' => (string) ($profile['purpose'] ?? ''), 'forbidden_actions' => $this->forbiddenFor($role)];
    }
}
