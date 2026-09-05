<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Audit\Auditor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Creates a user with a role. The password is asked for interactively (never on the command
 * line, never printed) or generated and shown ONCE when --generate-password is given.
 * Registration is closed in HQ; this is the only way in.
 */
final class CreateUser extends Command
{
    protected $signature = 'hq:user:create {email} {--name=} {--role=owner} {--generate-password}';

    protected $description = 'Create an HQ user with a role (registration is closed).';

    public function handle(Auditor $auditor): int
    {
        $email = strtolower(trim($this->argument('email')));
        if (User::where('email', $email)->exists()) {
            $this->error("A user with {$email} already exists.");

            return self::FAILURE;
        }

        $password = $this->option('generate-password') ? Str::password(24) : $this->secret('Password (min 12 chars)');
        if (strlen((string) $password) < 12) {
            $this->error('Password must be at least 12 characters.');

            return self::FAILURE;
        }

        $user = User::create([
            'name' => $this->option('name') ?: Str::before($email, '@'),
            'email' => $email,
            'password' => Hash::make($password),
        ]);
        $user->assignRole($this->option('role'));

        $auditor->recordSystem('user.created', $user, after: ['email' => $email, 'role' => $this->option('role')], reason: 'hq:user:create');

        $this->info("Created {$email} with role {$this->option('role')}. 2FA setup is required at first login.");
        if ($this->option('generate-password')) {
            $this->warn("Generated password (shown once): {$password}");
        }

        return self::SUCCESS;
    }
}
