<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\User;
use App\Models\UserBusinessScope;
use App\Support\Audit\Auditor;
use App\Support\Authorization\ApproverRole;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Creates a user with a role. The password is asked for interactively (never on the command
 * line, never printed) or generated and shown ONCE when --generate-password is given.
 * Registration is closed in HQ; this is the only way in.
 *
 * `--business=<slug>` (repeatable) limits the person to those businesses (user_business_scopes rows). The `approver`
 * role (docs/149) REQUIRES at least one: an approver with no rows would be group-wide, which the role must never be.
 */
final class CreateUser extends Command
{
    protected $signature = 'hq:user:create {email} {--name=} {--role=owner}
        {--business=* : Business slug to limit the user to (repeatable; required for --role=approver)}
        {--generate-password}';

    protected $description = 'Create an HQ user with a role (registration is closed).';

    public function handle(Auditor $auditor): int
    {
        $email = strtolower(trim($this->argument('email')));
        if (User::where('email', $email)->exists()) {
            $this->error("A user with {$email} already exists.");

            return self::FAILURE;
        }

        $role = (string) $this->option('role');
        if (! Role::query()->where('name', $role)->where('guard_name', 'web')->exists()) {
            $this->error("There is no role {$role}. Run the RolesAndPermissionsSeeder (--class) first.");

            return self::FAILURE;
        }
        $slugs = array_values(array_unique(array_filter(array_map(fn ($s) => strtolower(trim((string) $s)), (array) $this->option('business')))));
        if ($role === ApproverRole::NAME && $slugs === []) {
            $this->error('An approver must be limited to at least one business: pass --business=<slug>.');

            return self::FAILURE;
        }
        $businesses = Business::query()->whereIn('slug', $slugs)->pluck('id', 'slug');
        if (($unknown = array_values(array_diff($slugs, $businesses->keys()->all()))) !== []) {
            $this->error('Unknown business: '.implode(', ', $unknown).'.');

            return self::FAILURE;
        }

        $password = $this->option('generate-password') ? Str::password(24) : $this->secret('Password (min 12 chars)');
        if (strlen((string) $password) < 12) {
            $this->error('Password must be at least 12 characters.');

            return self::FAILURE;
        }

        $user = DB::transaction(function () use ($email, $password, $role, $businesses): User {
            $user = User::create([
                'name' => $this->option('name') ?: Str::before($email, '@'),
                'email' => $email,
                'password' => Hash::make($password),
            ]);
            foreach ($businesses as $businessId) {
                UserBusinessScope::create(['user_id' => $user->getKey(), 'business_id' => $businessId]);
            }
            $user->assignRole($role);

            return $user;
        });

        $auditor->recordSystem('user.created', $user, after: ['email' => $email, 'role' => $role, 'businesses' => $businesses->keys()->all()], reason: 'hq:user:create');

        $this->info("Created {$email} with role {$role}".($businesses->isEmpty() ? '' : ' for '.$businesses->keys()->implode(', ')).'. 2FA setup is required at first login.');
        if ($this->option('generate-password')) {
            $this->warn("Generated password (shown once): {$password}");
        }

        return self::SUCCESS;
    }
}
