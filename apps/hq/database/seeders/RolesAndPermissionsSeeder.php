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
        'hq.customers.view', 'hq.customers.manage',
        'hq.users.manage',
        'hq.horizon.view',

        // Phase 2 M1: the control plane (docs/48 E.5).
        //
        // Grouped by tool family rather than one permission per tool. Sixty permission strings for
        // sixty tools would be a list nobody reads, and a list nobody reads is not a control.
        'hq.tools.view', 'hq.tools.manage',
        'hq.tools.diagnostics',
        'hq.tools.erp.read',
        'hq.tools.commerce.read',
        // Reserved: writing AT a shop. No tool requiring it is enabled in Phase 2, and the
        // permission exists so that enabling one later is a grant somebody makes deliberately.
        'hq.tools.commerce.write',
        'hq.tools.facts.read',
        'hq.tools.schedule',
        'hq.tools.notify',

        // Named now so the roles that will need them are already shaped. Nothing in M1 checks the
        // agent, approval, policy, ask or cost permissions: their subsystems are M3 to M6.
        'hq.agents.view', 'hq.agents.manage',
        'hq.approvals.view', 'hq.approvals.decide', 'hq.approvals.delegate',
        'hq.policies.view', 'hq.policies.manage',
        'hq.ask.use',
        'hq.ai.cost.view',

        // Phase 3 (docs/72 C): the operator inbox. `view` reads threads; `manage` takes them over,
        // resolves them, and is the permission the reply tool requires of a human.
        'hq.inbox.view', 'hq.inbox.manage',
    ];

    public const ROLES = [
        'owner' => '*',
        'operator' => '*',
        'brand_manager' => [
            'hq.dashboard.view', 'hq.jobs.view', 'hq.jobs.manage', 'hq.businesses.view',
            'hq.credentials.view', 'hq.flags.view', 'hq.customers.view',
            // Sees what the control plane holds; changes none of it.
            'hq.tools.view', 'hq.approvals.view', 'hq.agents.view', 'hq.policies.view',
        ],
        'support_agent' => ['hq.dashboard.view', 'hq.jobs.view', 'hq.businesses.view', 'hq.customers.view', 'hq.inbox.view', 'hq.inbox.manage'],
        'finance_viewer' => ['hq.dashboard.view', 'hq.audit.view', 'hq.businesses.view', 'hq.ai.cost.view'],
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
