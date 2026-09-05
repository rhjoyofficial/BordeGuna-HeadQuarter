<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Phase 0 RBAC. Additive: permissions are granted with givePermissionTo(), never
 * syncPermissions(), so re-running on production cannot strip a customised role
 * (the exact defect the LaLaDia RoleSeeder once had).
 *
 * Role names mirror the ERP where the concept overlaps (owner, operator/admin, viewer);
 * HQ does not depend on ERP authentication.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public const PERMISSIONS = [
        'hq.dashboard.view',
        'hq.jobs.view', 'hq.jobs.manage',
        'hq.audit.view',
        'hq.flags.view', 'hq.flags.manage',
        'hq.credentials.view', 'hq.credentials.manage',
        'hq.businesses.view', 'hq.businesses.manage',
        'hq.users.manage',
        'hq.horizon.view',
    ];

    public const ROLES = [
        'owner' => '*',
        'operator' => '*',
        'brand_manager' => ['hq.dashboard.view', 'hq.jobs.view', 'hq.jobs.manage', 'hq.businesses.view', 'hq.credentials.view', 'hq.flags.view'],
        'support_agent' => ['hq.dashboard.view', 'hq.jobs.view', 'hq.businesses.view'],
        'finance_viewer' => ['hq.dashboard.view', 'hq.audit.view', 'hq.businesses.view'],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $name) {
            Permission::findOrCreate($name, 'web');
        }

        foreach (self::ROLES as $role => $permissions) {
            $r = Role::findOrCreate($role, 'web');
            $r->givePermissionTo($permissions === '*' ? self::PERMISSIONS : $permissions);
        }
    }
}
