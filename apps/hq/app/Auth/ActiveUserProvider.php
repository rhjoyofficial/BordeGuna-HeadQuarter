<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The one place the framework turns an id, a remember-me cookie or an email into a person, and it
 * answers "nobody" for a disabled account (docs/81 P0-1).
 *
 * The session guard comes here on every request (`retrieveById`), when it reads a remember-me
 * cookie (`retrieveByToken`) and at the password step (`retrieveByCredentials`); the password
 * broker resolves the account for a reset link and for a reset through `retrieveByCredentials`
 * too. So a disabled user cannot sign in, stay signed in, come back from a cookie or reset a
 * password, and no route, controller or future guard call can forget to ask, because there is no
 * other way in.
 *
 * ⛔ No exemption of any kind: no role, no environment, no switch. To let somebody in, enable them.
 *
 * What a caller sees is what it sees for an account that does not exist, so no screen learns that
 * an account is disabled. `EnsureAccountIsActive` is the second layer: it ends the live session.
 */
final class ActiveUserProvider extends EloquentUserProvider
{
    public const DISABLED_REASON = 'the account is disabled';

    public function retrieveById($identifier): ?Authenticatable
    {
        return $this->onlyActive(parent::retrieveById($identifier));
    }

    public function retrieveByToken($identifier, #[\SensitiveParameter] $token): ?Authenticatable
    {
        return $this->onlyActive(parent::retrieveByToken($identifier, $token));
    }

    public function retrieveByCredentials(#[\SensitiveParameter] array $credentials): ?Authenticatable
    {
        return $this->onlyActive(parent::retrieveByCredentials($credentials));
    }

    /** For the audit record only: whether this address belongs to a disabled account. Never shown on a screen. */
    public static function isDisabledAccount(mixed $email): bool
    {
        return is_string($email) && $email !== ''
            && User::query()->where('email', $email)->where('is_active', '=', false)->exists();
    }

    private function onlyActive(?Authenticatable $user): ?Authenticatable
    {
        // The column is NOT NULL DEFAULT true. Anything but a stored `true` is not an enabled account.
        return $user !== null && $user->getAttribute('is_active') === true ? $user : null;
    }
}
