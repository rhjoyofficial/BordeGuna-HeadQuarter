<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Production-safe: roles/permissions (additive) and the domain model (idempotent).
     * Users are never seeded; create the first owner with `php artisan hq:user:create`.
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            DomainSeeder::class,
        ]);
    }
}
