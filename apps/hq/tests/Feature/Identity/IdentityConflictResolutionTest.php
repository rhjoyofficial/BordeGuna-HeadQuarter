<?php

use App\Consent\ConsentBasis;
use App\Consent\ConsentLedger;
use App\Consent\ConsentPurpose;
use App\Consent\ConsentState;
use App\Gateway\CallerKind;
use App\Gateway\Identity\ActingIdentity;
use App\Gateway\OutcomeClass;
use App\Gateway\OutcomeStatus;
use App\Gateway\ToolGateway;
use App\Gateway\ToolRequest;
use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\ConsentEvent;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use App\Models\CustomerIdentityConflict;
use App\Models\CustomerIdentityConflictResolution;
use App\Models\Store;
use App\Models\User;
use App\Models\UserBusinessScope;
use App\Tools\Identity\ResolveIdentityConflictTool;
use App\Tools\ToolRegistry;
use Database\Seeders\AgentSeeder;
use Database\Seeders\DomainSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Execution\Contexts;

/*
 | docs/140 (ruling docs/131 item 3, docs/138 section 5 P3-W1): a person's evidence-backed resolution of an open
 | `customer_identity_conflicts` row, through the permanently human-only gateway tool `customers.identity_conflicts.resolve`.
 |
 | Proved here: a move goes only to the claimant of the same business, carries the identity's stop-contact records and no
 | grant, and leaves an append-only resolution row, the conflict closed and an audit row; a repeat is a replay and another
 | choice is refused; agents can never hold or run it; a restricted person sees and resolves only their own business's
 | conflicts; nothing crosses a business; a held identity is released only when the rows support it.
 */

beforeEach(function () {
    Cache::flush();
    $this->seed(DomainSeeder::class);
    $this->owner = $this->hqUser('owner');
    $this->laladia = Business::where('slug', 'laladia')->sole();
    $this->bionic = Business::where('slug', 'bionic')->sole();
});

function icCustomer(Business $business, string $name): Customer
{
    return Customer::create(['business_id' => $business->id, 'name' => $name, 'first_seen_at' => now(), 'last_seen_at' => now()]);
}

function icIdentity(Customer $customer, string $channel, string $externalId, ?string $heldReason = null, ?int $storeId = null): CustomerIdentity
{
    $id = DB::table('customer_identities')->insertGetId(['business_id' => $customer->business_id, 'customer_id' => $customer->id, 'channel' => $channel,
        'external_id' => $externalId, 'source_store_id' => $storeId, 'confidence' => 1, 'quarantined_at' => $heldReason === null ? null : now(),
        'quarantine_reason' => $heldReason, 'created_at' => now(), 'updated_at' => now()]);

    return CustomerIdentity::findOrFail($id);
}

function icConflict(CustomerIdentity $identity, string $kind, ?Customer $claimant = null, ?int $businessId = null): CustomerIdentityConflict
{
    return CustomerIdentityConflict::create([
        'business_id' => $businessId ?? $identity->business_id, 'identity_id' => $identity->id, 'kind' => $kind,
        'reason' => $kind === 'link_refused' ? 'held_by_another_customer' : (string) $identity->quarantine_reason,
        'holder_customer_id' => $identity->customer_id, 'claimant_customer_id' => $claimant?->id, 'source' => 'test',
        'occurrences' => 1, 'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);
}

/** A refused re-point in one business: the phone held by one customer, claimed by another of the same business. */
function icLinkRefused(object $t, ?Business $business = null, string $phone = '01711000140'): array
{
    $business ??= $t->laladia;
    $holder = icCustomer($business, 'Holder');
    $claimant = icCustomer($business, 'Claimant');
    $identity = icIdentity($holder, 'phone', $phone);

    return ['holder' => $holder, 'claimant' => $claimant, 'identity' => $identity, 'conflict' => icConflict($identity, 'link_refused', $claimant)];
}

function icResolveAs(User $user, CustomerIdentityConflict $conflict, string $resolution, ?Business $business = null, ?ActingIdentity $identity = null)
{
    $business ??= Business::findOrFail($conflict->business_id);

    return app(ToolGateway::class)->execute(new ToolRequest(
        toolKey: ResolveIdentityConflictTool::KEY,
        args: ['business_slug' => $business->slug, 'conflict_id' => $conflict->id, 'resolution' => $resolution,
            'evidence' => 'Called the customer on 16 Oct; the number is the claimant\'s own', 'reason' => 'the holder was a mistyped order phone'],
        caller: CallerKind::HumanUi, identity: $identity ?? ActingIdentity::human($user), correlationId: (string) Str::uuid(), businessId: $business->id,
    ));
}

it('moves the identity to the claimant of the same business from the screen popup, carrying stop-contact and no grant, with an append-only record and an audit row', function () {
    $c = icLinkRefused($this);
    $ledger = app(ConsentLedger::class);
    $ledger->record(customerId: $c['holder']->id, businessId: $this->laladia->id, purpose: ConsentPurpose::Service, state: ConsentState::Granted,
        basis: ConsentBasis::Inbound, channel: 'whatsapp', attributes: ['identity_id' => $c['identity']->id, 'reason' => 'wrote to us']);
    $dnc = $ledger->record(customerId: $c['holder']->id, businessId: $this->laladia->id, purpose: null, state: ConsentState::DoNotContact,
        basis: ConsentBasis::Operator, channel: null, attributes: ['identity_id' => $c['identity']->id, 'reason' => 'asked us to stop on this number']);
    $wide = $ledger->record(customerId: $c['holder']->id, businessId: $this->laladia->id, purpose: ConsentPurpose::Marketing, state: ConsentState::Withdrawn,
        basis: ConsentBasis::Operator, channel: null, attributes: ['reason' => 'no offers']);

    $this->actingAsHq($this->owner)->get('/customers/identity-conflicts')->assertOk()
        ->assertInertia(fn ($page) => $page->component('customers/identity-conflicts')->where('can_resolve', true)
            ->has('conflicts', 1)->where('conflicts.0.id', $c['conflict']->id)->where('conflicts.0.key', '*******0140')
            ->where('conflicts.0.choices', ['kept_owner', 'moved_to_claimant', 'not_resolvable']));

    $this->actingAsHq($this->owner)->post("/customers/identity-conflicts/{$c['conflict']->id}/resolve", [
        'resolution' => 'moved_to_claimant', 'evidence' => 'Called the claimant; the number is theirs', 'reason' => 'mistyped phone on an old order',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $conflict = $c['conflict']->fresh();
    $record = CustomerIdentityConflictResolution::sole();
    expect($c['identity']->fresh()->customer_id)->toBe($c['claimant']->id)
        ->and($c['identity']->fresh()->business_id)->toBe($this->laladia->id)
        ->and($conflict->resolution)->toBe('moved_to_claimant')->and($conflict->resolved_at)->not->toBeNull()->and($conflict->resolved_by)->toBe($this->owner->id)
        ->and($record->owner_before_customer_id)->toBe($c['holder']->id)->and($record->owner_after_customer_id)->toBe($c['claimant']->id)
        ->and($record->evidence['stated'])->toBe('Called the claimant; the number is theirs')->and($record->reason)->toBe('mistyped phone on an old order')
        ->and($record->resolved_by)->toBe($this->owner->id)
        ->and($record->carried_restrictions)->toHaveCount(1)->and($record->carried_restrictions[0]['from_event'])->toBe($dnc->id)
        ->and(collect($record->evidence['checked']['consent_review'])->pluck('consent_event_id')->all())->toBe([$wide->id]);

    // The stop-contact followed the number; the grant did not (the new owner must be given one by a person).
    $claimantEvents = ConsentEvent::where('customer_id', $c['claimant']->id)->get();
    expect($claimantEvents)->toHaveCount(1)
        ->and($claimantEvents[0]->state)->toBe(ConsentState::DoNotContact)
        ->and($claimantEvents[0]->identity_id)->toBe($c['identity']->id);

    $audit = AuditLog::where('action', 'identity.conflict.resolved')->sole();
    expect($audit->actor_id)->toBe($this->owner->id)->and($audit->actor_type)->toBe('human')
        ->and(DB::table('tool_calls')->where('tool_key', ResolveIdentityConflictTool::KEY)->where('status', 'succeeded')->count())->toBe(1);

    // Append-only, in the database itself.
    expect(fn () => DB::table('customer_identity_conflict_resolutions')->where('id', $record->id)->update(['reason' => 'rewritten later']))
        ->toThrow(QueryException::class, 'append-only');
});

it('is idempotent: the same choice again is a replay, another choice is refused, and nothing is written twice', function () {
    $c = icLinkRefused($this);
    expect(icResolveAs($this->owner, $c['conflict'], 'kept_owner')->status)->toBe(OutcomeStatus::Succeeded);

    $again = icResolveAs($this->owner, $c['conflict'], 'kept_owner');
    expect($again->status)->toBe(OutcomeStatus::Succeeded)->and($again->output['replayed'])->toBeTrue()->and($again->output['status'])->toBe('already_resolved');

    $other = icResolveAs($this->owner, $c['conflict'], 'moved_to_claimant');
    expect($other->status)->toBe(OutcomeStatus::Failed)->and($other->error)->toContain('already resolved');

    $this->actingAsHq($this->owner)->post("/customers/identity-conflicts/{$c['conflict']->id}/resolve", [
        'resolution' => 'not_resolvable', 'evidence' => 'Nothing new to add here', 'reason' => 'trying to change my mind',
    ])->assertSessionHasErrors('resolution');

    expect(CustomerIdentityConflictResolution::count())->toBe(1)
        ->and(AuditLog::where('action', 'identity.conflict.resolved')->count())->toBe(1)
        ->and($c['identity']->fresh()->customer_id)->toBe($c['holder']->id)
        ->and($c['conflict']->fresh()->resolution)->toBe('kept_owner');
});

it('is permanently human-only: no agent holds it, an agent call is refused, and only owner and operator hold the permission', function () {
    $this->seed(AgentSeeder::class);
    $d = app(ToolRegistry::class)->get(ResolveIdentityConflictTool::KEY)->descriptor();
    expect($d->permanentlyHumanOnly)->toBeTrue()->and($d->agentExecutable)->toBeFalse()->and($d->permission)->toBe('hq.customers.identity_conflicts.resolve')
        ->and($d->riskTier->value)->toBe('r3')->and($d->hasEffect())->toBeTrue();
    foreach (Agent::all() as $agent) {
        expect($agent->mayUseTool(ResolveIdentityConflictTool::KEY))->toBeFalse();
    }

    $c = icLinkRefused($this);
    $agent = Agent::create(['key' => 'test-resolver', 'name' => 'Test', 'status' => 'active', 'enabling_user_id' => $this->owner->id, 'allowed_tools' => [ResolveIdentityConflictTool::KEY]]);
    $asAgent = icResolveAs($this->owner, $c['conflict'], 'moved_to_claimant', identity: ActingIdentity::forExecution(Contexts::for($agent)));
    expect($asAgent->status)->toBe(OutcomeStatus::Denied)->and($asAgent->policyReason)->toContain('permanently human-only');

    $reviewer = User::factory()->create();
    $reviewer->givePermissionTo(['hq.customers.quarantine.review']);
    $denied = icResolveAs($reviewer->fresh(), $c['conflict'], 'moved_to_claimant');
    expect($denied->status)->toBe(OutcomeStatus::Denied)->and($denied->class)->toBe(OutcomeClass::PermissionDenied);

    $this->actingAsHq($this->hqUser('brand_manager'))->post("/customers/identity-conflicts/{$c['conflict']->id}/resolve", [
        'resolution' => 'moved_to_claimant', 'evidence' => 'I think it is theirs', 'reason' => 'I would like to move it',
    ])->assertForbidden();

    // No human behind a console call: refused by the tool itself.
    $system = app(ToolGateway::class)->execute(ToolRequest::forConsole(ResolveIdentityConflictTool::KEY, ['business_slug' => 'laladia', 'conflict_id' => $c['conflict']->id,
        'resolution' => 'kept_owner', 'evidence' => 'a system thinks so', 'reason' => 'an automated sweep'], null, $this->laladia->id));
    expect($system->status)->toBe(OutcomeStatus::Denied)->and((string) $system->policyReason)->toContain('does not hold [hq.customers.identity_conflicts.resolve]');

    expect(CustomerIdentityConflictResolution::count())->toBe(0)->and($c['conflict']->fresh()->resolved_at)->toBeNull()
        ->and($c['identity']->fresh()->customer_id)->toBe($c['holder']->id);
    foreach (RolesAndPermissionsSeeder::ROLES as $role => $permissions) {
        expect($permissions === '*' || ! in_array(ResolveIdentityConflictTool::PERMISSION, $permissions, true))->toBeTrue("[{$role}] is given the resolve permission");
    }
});

it('shows a restricted person only their own business\'s conflicts and lets them resolve nothing of another business', function () {
    $lld = icLinkRefused($this, $this->laladia, '01711000141');
    $bnc = icLinkRefused($this, $this->bionic, '01711000141');

    $scoped = $this->hqUser('owner');
    UserBusinessScope::create(['user_id' => $scoped->id, 'business_id' => $this->bionic->id, 'access' => 'full']);
    $scoped = $scoped->fresh();

    $this->actingAsHq($scoped)->get('/customers/identity-conflicts')->assertOk()
        ->assertInertia(fn ($page) => $page->has('conflicts', 1)->where('conflicts.0.id', $bnc['conflict']->id)->where('conflicts.0.business', $this->bionic->name));
    $this->actingAsHq($scoped)->post("/customers/identity-conflicts/{$lld['conflict']->id}/resolve", [
        'resolution' => 'kept_owner', 'evidence' => 'Seen on the LaLaDia order', 'reason' => 'not my business but trying',
    ])->assertNotFound();
    // Straight at the gateway, naming LaLaDia: refused before the tool runs.
    $gateway = icResolveAs($scoped, $lld['conflict'], 'kept_owner');
    expect($gateway->status)->not->toBe(OutcomeStatus::Succeeded)->and((string) ($gateway->policyReason ?? $gateway->error))->toContain('is not scoped to business');

    // Their own business: allowed.
    $this->actingAsHq($scoped)->post("/customers/identity-conflicts/{$bnc['conflict']->id}/resolve", [
        'resolution' => 'not_resolvable', 'evidence' => 'Two customers, no order to decide by', 'reason' => 'cannot tell who owns it',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($lld['conflict']->fresh()->resolved_at)->toBeNull()
        ->and($bnc['conflict']->fresh()->resolution)->toBe('not_resolvable')
        ->and(CustomerIdentityConflictResolution::pluck('business_id')->all())->toBe([$this->bionic->id]);
});

it('never resolves across a business: another business named, a claimant of another business, or a conflict filed under the wrong business', function () {
    $c = icLinkRefused($this);

    // The conflict is LaLaDia's; naming Bionic as the business finds nothing to resolve.
    $wrongBusiness = icResolveAs($this->owner, $c['conflict'], 'moved_to_claimant', $this->bionic);
    expect($wrongBusiness->status)->toBe(OutcomeStatus::Failed)->and($wrongBusiness->error)->toContain('not a conflict of this business');

    // A claimant of another business is never a target, even when the conflict row names one.
    $holder = icCustomer($this->laladia, 'LaLaDia holder');
    $stranger = icCustomer($this->bionic, 'Bionic claimant');
    $identity = icIdentity($holder, 'phone', '01711000142');
    $cross = icConflict($identity, 'link_refused', $stranger);
    $refused = icResolveAs($this->owner, $cross, 'moved_to_claimant');
    expect($refused->status)->toBe(OutcomeStatus::Failed)->and($refused->error)->toContain('never moves across a business');

    // A conflict row whose business is not its identity's: refused, never acted on.
    $misfiled = icConflict(icIdentity(icCustomer($this->laladia, 'Other'), 'phone', '01711000143'), 'link_refused', icCustomer($this->bionic, 'B'), $this->bionic->id);
    $mis = icResolveAs($this->owner, $misfiled, 'kept_owner');
    expect($mis->status)->toBe(OutcomeStatus::Failed)->and($mis->error)->toContain('not an identity of this business');

    expect(CustomerIdentityConflictResolution::count())->toBe(0)
        ->and($identity->fresh()->customer_id)->toBe($holder->id)
        ->and(CustomerIdentity::where('business_id', $this->bionic->id)->count())->toBe(0)
        ->and($cross->fresh()->resolved_at)->toBeNull();
});

it('releases a held identity only when the rows support it, and keeps it held when the person marks it not resolvable', function () {
    $owner = icCustomer($this->laladia, 'Held owner');
    $identity = icIdentity($owner, 'phone', '01711000144', 'contradicted_by_evidence');
    $conflict = icConflict($identity, 'quarantined');

    $this->actingAsHq($this->owner)->get('/customers/identity-conflicts')->assertOk()
        ->assertInertia(fn ($page) => $page->where('conflicts.0.held', true)->where('conflicts.0.release_blockers', ['no_own_use'])
            ->where('conflicts.0.choices', ['not_resolvable']));
    $blocked = icResolveAs($this->owner, $conflict, 'kept_owner');
    expect($blocked->status)->toBe(OutcomeStatus::Failed)->and($blocked->error)->toContain('do not support releasing')
        ->and($identity->fresh()->quarantined_at)->not->toBeNull();
    // No claimant on a held row: nobody to move it to.
    expect(icResolveAs($this->owner, $conflict, 'moved_to_claimant')->error)->toContain('names no claimant');

    // Evidence arrives: a LaLaDia order placed with this phone. Now keeping the owner releases it.
    DB::table('facts_orders')->insert(['business_id' => $this->laladia->id, 'store_id' => Store::where('slug', 'laladia-com')->sole()->id, 'order_ref' => 'LLD-140',
        'status' => 'delivered', 'gross' => 900, 'customer_id' => $owner->id, 'customer_phone_canonical' => '01711000144', 'placed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $kept = icResolveAs($this->owner, $conflict, 'kept_owner');
    expect($kept->status)->toBe(OutcomeStatus::Succeeded)->and($kept->output['released_from_quarantine'])->toBeTrue()
        ->and($identity->fresh()->quarantined_at)->toBeNull()->and($identity->fresh()->quarantine_reason)->toBeNull()
        ->and(CustomerIdentity::query()->key($this->laladia->id, 'phone', '01711000144')->value('id'))->toBe($identity->id);

    // Another held copy of the same key cannot come back while the first is live; not resolvable leaves it held.
    $second = icIdentity(icCustomer($this->laladia, 'Second'), 'phone', '01711000144', 'duplicate_ambiguous_owner');
    $secondConflict = icConflict($second, 'quarantined');
    expect(icResolveAs($this->owner, $secondConflict, 'kept_owner')->error)->toContain('another live identity');
    expect(icResolveAs($this->owner, $secondConflict, 'not_resolvable')->status)->toBe(OutcomeStatus::Succeeded)
        ->and($second->fresh()->quarantined_at)->not->toBeNull()
        ->and($secondConflict->fresh()->resolution)->toBe('not_resolvable')
        ->and(CustomerIdentityConflictResolution::where('conflict_id', $secondConflict->id)->sole()->released_from_quarantine)->toBeFalse();
});

it('shows a reviewer without the resolve permission the conflicts and no resolve action', function () {
    icLinkRefused($this);
    $reviewer = $this->hqUser('finance_viewer');
    $reviewer->givePermissionTo('hq.customers.quarantine.review');

    $this->actingAsHq($reviewer->fresh())->get('/customers/identity-conflicts')->assertOk()
        ->assertInertia(fn ($page) => $page->where('can_resolve', false)->has('conflicts', 1));
    $this->actingAsHq($this->hqUser('support_agent'))->get('/customers/identity-conflicts')->assertForbidden();
});

it('rolls the resolutions table back only while it is empty', function () {
    $migration = require database_path('migrations/2026_10_17_110000_create_identity_conflict_resolutions.php');
    $c = icLinkRefused($this);
    icResolveAs($this->owner, $c['conflict'], 'not_resolvable');

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Refusing to roll back: 1 identity conflict resolution');
    expect(DB::selectOne("SELECT to_regclass('customer_identity_conflict_resolutions') IS NOT NULL AS present")->present)->toBeTrue();
});
