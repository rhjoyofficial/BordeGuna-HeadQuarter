<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Audit\Auditor;
use Illuminate\Console\Command;

/**
 * Changes the email a user signs in with.
 *
 * An email is the login identifier, so this is the one place it changes: it refuses when the old
 * address matches nobody or the new one is already taken, touches nothing else on the row (password,
 * two-factor secret and role all survive), and records the change in the audit log with both
 * addresses. Registration is closed in HQ; this and hq:user:create are the whole account surface.
 */
final class UpdateUserEmail extends Command
{
    protected $signature = 'hq:user:email {from : the current email} {to : the new email}';

    protected $description = 'Change the email an HQ user signs in with, audited.';

    public function handle(Auditor $auditor): int
    {
        $from = strtolower(trim($this->argument('from')));
        $to = strtolower(trim($this->argument('to')));

        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->error("{$to} is not a valid email address.");

            return self::FAILURE;
        }

        if ($from === $to) {
            $this->info('The new address is the same as the current one; nothing to do.');

            return self::SUCCESS;
        }

        $user = User::where('email', $from)->first();
        if ($user === null) {
            $this->error("No user signs in as {$from}.");

            return self::FAILURE;
        }

        if (User::where('email', $to)->exists()) {
            $this->error("Another user already signs in as {$to}.");

            return self::FAILURE;
        }

        $user->forceFill(['email' => $to])->save();

        $auditor->recordSystem('user.email_changed', $user, before: ['email' => $from], after: ['email' => $to], reason: 'hq:user:email');

        $this->info("{$from} now signs in as {$to}. Password, two-factor and roles are unchanged.");

        return self::SUCCESS;
    }
}
