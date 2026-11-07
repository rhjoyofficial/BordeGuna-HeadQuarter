<?php

namespace App\Support\Authorization;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Throwable;

/**
 * Who may throw a switch that acts on every business (docs/138 ruling 13, docs/136).
 *
 * A feature flag, the tool registry's on/off, the model provider, an agent's shadow period and the
 * autonomy and language policies each change what HQ does for the whole group at once. Ruling 13:
 * such a switch needs explicit group-wide authority, which here means all four of
 *
 *   - a named person (nobody named is not authority: ambiguous scope means refusal);
 *   - an enabled account;
 *   - the switch's own permission;
 *   - no business restriction, by the one notion HQ has of it: `BusinessScope::isRestricted()` is
 *     false, i.e. the person has no `user_business_scopes` rows.
 *
 * If the scope cannot be read the answer is a refusal, never a pass (fail closed).
 *
 * The refusal is an `AuthorizationException`, so a web request answers 403 with the reason and a
 * console command or service caller can catch it like any other refusal.
 */
final class GroupWideAuthority
{
    /** What the audit row records as the scope of every switch this class guards. */
    public const SCOPE = 'group';

    public function __construct(private readonly BusinessScope $businessScope) {}

    /** Why this person may not throw this switch, or null when they may. */
    public function refusal(?User $user, string $permission): ?string
    {
        return $this->refusalDetail($user, $permission)['message'] ?? null;
    }

    /** The stable reason code of the refusal (ruling 1 Nov 2026), or null when the person may throw the switch. */
    public function refusalCode(?User $user, string $permission): ?string
    {
        return $this->refusalDetail($user, $permission)['code'] ?? null;
    }

    /**
     * The refusal as a stable code and HQ's own English sentence. A screen shows the approved sentence for the
     * code in the reader's language; the English sentence stays what a 403 and an audit row say.
     *
     * @return array{code: string, message: string}|null
     */
    public function refusalDetail(?User $user, string $permission): ?array
    {
        if ($user === null) {
            return ['code' => 'group_switch_no_person', 'message' => 'This switch acts on every business, and no person was named, so nobody holds group-wide authority for it.'];
        }
        if ($user->is_active === false) {
            return ['code' => 'group_switch_account_disabled', 'message' => "This switch acts on every business, and the account {$user->email} is disabled."];
        }

        try {
            if (! $user->hasPermissionTo($permission)) {
                return ['code' => 'group_switch_permission_missing', 'message' => "This switch acts on every business and needs {$permission}, which {$user->email} does not hold."];
            }
            $restricted = $this->businessScope->isRestricted($user);
        } catch (Throwable) {
            return ['code' => 'group_switch_scope_unreadable', 'message' => 'This switch acts on every business, and your business scope could not be read, so it is refused.'];
        }

        if ($restricted) {
            return ['code' => 'group_switch_business_restricted', 'message' => 'This switch acts on every business, and your access is limited to some businesses. Only a person with group-wide access may change it.'];
        }

        return null;
    }

    public function allows(?User $user, string $permission): bool
    {
        return $this->refusal($user, $permission) === null;
    }

    /** @throws AuthorizationException with the reason, when the person may not throw the switch */
    public function assert(?User $user, string $permission): void
    {
        $refusal = $this->refusal($user, $permission);
        if ($refusal !== null) {
            throw new AuthorizationException($refusal);
        }
    }

    /**
     * What every global switch audit row carries beside actor, before, after and reason.
     *
     * @return array{scope: string, permission: string, authority: string}
     */
    public function auditContext(string $permission, ?User $user): array
    {
        return [
            'scope' => self::SCOPE,
            'permission' => $permission,
            // A person checked by this class, or the terminal with nobody named (the system).
            'authority' => $user === null ? 'system' : 'group-wide person',
        ];
    }

    /**
     * The page props a switch screen needs: may this person throw it, and if not, why.
     *
     * @return array{allowed: bool, refusal: string|null, refusal_code: string|null}
     */
    public function screenProps(?User $user, string $permission): array
    {
        $refusal = $this->refusalDetail($user, $permission);

        return ['allowed' => $refusal === null, 'refusal' => $refusal['message'] ?? null, 'refusal_code' => $refusal['code'] ?? null];
    }
}
