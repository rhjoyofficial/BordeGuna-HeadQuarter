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
        // Phase 5 read side (docs/88 A point 4). Reserved like commerce.write: no ads tool is registered,
        // so nothing checks it yet; it exists so registering the reads at P5-G1 is a grant somebody makes.
        'hq.tools.ads.read',
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

        // docs/108 (S7): consent operations. `restrict` records a withdrawal or a do-not-contact (it can
        // only ever stop contact); `grant` records an opt-in, operator-recorded consent or a lift (it can
        // start contact), so the two are never one permission.
        'hq.consent.view', 'hq.consent.restrict', 'hq.consent.grant',

        // docs/112 (S6): the playbook store. `draft` writes and submits guidance, `review` approves or sends
        // it back, `activate` is the one act that puts a version in front of an agent (and retires it). Three
        // permissions so the person who writes a rule is not, by that fact, the person who makes it live.
        'hq.playbooks.view', 'hq.playbooks.draft', 'hq.playbooks.review', 'hq.playbooks.activate',
        // docs/113 (S4 part 1): follow-ups and opportunities. `propose` records a proposal (a plan, never a
        // message); `decide` approves, declines, completes, cancels or closes, and is the permission under
        // which a WorkflowEngine run that only waits for a person is started. Deciding stays with
        // owner/operator until the roles are ruled (docs/113 Q4).
        'hq.followups.view', 'hq.followups.propose', 'hq.followups.decide',
        // docs/114 (S7 follow-up, ruling (k)): asking for, and approving, the lift of a do-not-contact. Two
        // different holders are needed for one lift; owner/operator hold it through `*`, support_agent never.
        'hq.consent.lift_restriction',
        // docs/110 §3 Q11, docs/115 §4: the operator-asserted outcome attribution, through the gateway tool
        // `outcomes.attribute` only. Held by owner and operator (`*`); given to no other role.
        'hq.outcomes.attribute',
        // docs/117 (ruling docs/110 §5 (m)): reviewing quarantine flags on cross-business relationships.
        // Records an audited decision only; it lifts no suppression and repairs nothing. Owner and operator
        // hold it through '*'; no other role is given it.
        'hq.customers.quarantine.review',
        // docs/126 (ruling docs/120 item 36): applying a reviewed cross-business repair batch through the human-only
        // gateway tool `customers.quarantine.repair`, and assigning a business to a memory the repair withheld.
        // Owner and operator hold it through '*'; no other role is given it.
        'hq.customers.quarantine.repair',
        // docs/143 (owner console, all behind flags seeded OFF). Owner and operator hold them through '*'; no other role is given them.
        // Do mode: propose a plan, and start one (starting approves no step). Telegram brief: the gateway tool's permission.
        // Event watches: propose one, and enable/disable/reject one (enabling grants no authority for its action).
        'hq.do.propose', 'hq.do.start', 'hq.briefing.deliver', 'hq.watches.propose', 'hq.watches.enable',

        // docs/140 (ruling docs/131 item 3, docs/138 P3-W1): resolving an open identity conflict with evidence, through
        // the human-only gateway tool `customers.identity_conflicts.resolve` only. Owner and operator hold it through
        // '*'; no other role is given it.
        'hq.customers.identity_conflicts.resolve',
        // docs/138 ruling 7, docs/141: Pause All AI. `pause` can only turn AI execution off; `resume` is the
        // distinct operation that turns it back on. Both also need group-wide authority (ruling 13). Owner and
        // operator hold them through '*'; no other role is given either.
        'hq.ai.pause', 'hq.ai.resume',
        // docs/145 (HQ completion, agent M4). Phase 6: the content calendar (`plan` writes plans and drafts, `review` approves
        // or rejects a draft), the owner's claims rules (`claims.manage`, owner/operator only), research digests.
        'hq.content.view', 'hq.content.plan', 'hq.content.review', 'hq.content.claims.manage',
        'hq.research.view', 'hq.research.digest',
        // Phase 8: the avatar consent registry and the (unregistered, approval-gated) generation tool.
        'hq.avatar.view', 'hq.avatar.consent.manage', 'hq.avatar.generate',
        // Phase 9: the skill registry. `propose` records or suspends; `activate` approves, activates and retires.
        'hq.skills.view', 'hq.skills.propose', 'hq.skills.activate',
        // Phase 10: the evidence ladder. `grant` sets thresholds and grants (also needs group-wide authority);
        // `revoke` only ever stops autonomy. Owner and operator hold all three through '*'; no other role.
        'hq.autonomy.view', 'hq.autonomy.grant', 'hq.autonomy.revoke',
        // docs/144: the HQ-completion connectors. Owner and operator hold them through '*'; no other role is given any.
        // `connectors.view` reads the connector status screen; `courier.read` tracks parcels; `voice.use` speaks and
        // listens; `media.draft` asks for and reviews video drafts; `mail.ingest` polls the supplier mailbox and
        // `ingestion.review` decides on its drafts; `erp.draft_bill` asks the ERP for a draft bill (a second person
        // approves); `ads.draft` prepares a paused ad draft; `meta.health` checks a Meta token.
        'hq.connectors.view', 'hq.tools.courier.read', 'hq.voice.use', 'hq.tools.media.draft', 'hq.tools.mail.ingest',
        'hq.ingestion.review', 'hq.tools.erp.draft_bill', 'hq.tools.ads.draft', 'hq.tools.meta.health',
        // Ruling 31 Oct item 12 (R2, docs/147-r2): setting a business's monthly media budget and reconciling a provider's billed
        // cost. Held through '*' by owner and operator; `MediaBudgets::setLimit()` also requires the OWNER role.
        'hq.media.budget.manage',
    ];

    public const ROLES = [
        'owner' => '*',
        'operator' => '*',
        'brand_manager' => [
            'hq.dashboard.view', 'hq.jobs.view', 'hq.jobs.manage', 'hq.businesses.view',
            'hq.credentials.view', 'hq.flags.view', 'hq.customers.view',
            // Sees what the control plane holds; changes none of it.
            'hq.tools.view', 'hq.approvals.view', 'hq.agents.view', 'hq.policies.view',
            // Writes the brand's guidance; a review and an activation are somebody else's acts.
            'hq.playbooks.view', 'hq.playbooks.draft',
            // docs/113 section 4: may record a follow-up or an interest; deciding is owner/operator (Q4).
            'hq.followups.view', 'hq.followups.propose',
            // docs/145-a: plans the brand's posts and writes drafts; reviewing them and the claims rules are not theirs.
            'hq.content.view', 'hq.content.plan', 'hq.research.view',
        ],
        'support_agent' => ['hq.dashboard.view', 'hq.jobs.view', 'hq.businesses.view', 'hq.customers.view', 'hq.inbox.view', 'hq.inbox.manage',
            // The person who hears "stop messaging me" can record it; starting contact is not theirs.
            'hq.consent.view', 'hq.consent.restrict',
            // docs/113 section 4: the person who hears a promise can record it.
            'hq.followups.view', 'hq.followups.propose'],
        'finance_viewer' => ['hq.dashboard.view', 'hq.audit.view', 'hq.businesses.view', 'hq.ai.cost.view'],
        // docs/149 (architecture ruling 1 Nov 2026): the least-privilege second approver. Sees and decides approvals of
        // its own businesses (App\Support\Authorization\ApproverRole: confirmed 2FA, business scope rows required, never
        // its own proposal, and each category's domain permission assigned per person, never bundled here). Nothing else.
        'approver' => ['hq.dashboard.view', 'hq.approvals.view', 'hq.approvals.decide'],
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
