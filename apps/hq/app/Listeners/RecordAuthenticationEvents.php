<?php

namespace App\Listeners;

use App\Support\Audit\Auditor;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Request;

/** Every login, logout and failed login is an audit record; logins also stamp the user. */
final class RecordAuthenticationEvents
{
    public function __construct(private readonly Auditor $auditor) {}

    public function login(Login $event): void
    {
        $event->user->forceFill(['last_login_at' => now(), 'last_login_ip' => Request::ip()])->saveQuietly();
        // The Login event fires before the guard has set its user, so the auditor cannot look the
        // actor up and would label the row `user:1`; the label is passed in so the audit log reads
        // "who" the same way for a login as for everything that follows it.
        $this->auditor->record('auth.login', $event->user, actorUserId: $event->user->getAuthIdentifier(), actorLabel: $event->user->email);
    }

    public function logout(Logout $event): void
    {
        if ($event->user) {
            $this->auditor->record('auth.logout', $event->user, actorUserId: $event->user->getAuthIdentifier(), actorLabel: $event->user->email);
        }
    }

    public function failed(Failed $event): void
    {
        $this->auditor->recordSystem('auth.login_failed', 'user', after: ['email' => $event->credentials['email'] ?? null]);
    }
}
