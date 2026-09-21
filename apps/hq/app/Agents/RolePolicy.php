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
 * ⚠ A role the policy does not name forbids nothing new. That is the behaviour before this class
 * existed, so an unforeseen role is not broken by it; every role HQ runs is named, and a test holds it.
 */
final class RolePolicy
{
    public const VERSION = 'v1';

    public function version(): string
    {
        return (string) config('hq.roles.version', self::VERSION);
    }

    /**
     * The outbound kinds (`RoleBoundary::KINDS`) the role forbids.
     *
     * @return list<string>
     */
    public function forbiddenFor(?string $role): array
    {
        if ($role === null || $role === '') {
            return [];
        }

        $forbidden = config("hq.roles.forbidden_actions.{$role}", []);

        return array_values(array_filter(is_array($forbidden) ? $forbidden : [], static fn ($k) => in_array($k, RoleBoundary::KINDS, true)));
    }

    /** @return list<string> the roles the policy names */
    public function roles(): array
    {
        return array_keys((array) config('hq.roles.forbidden_actions', []));
    }
}
