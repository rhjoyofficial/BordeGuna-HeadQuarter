<?php

use App\Consent\ConsentLedger;
use App\Consent\ConsentPurpose;
use App\Conversation\ConversationState;
use App\Conversation\SupportContext;
use App\Customer360\Customer360Reader;
use App\FollowUps\Proposer;
use App\Gateway\CallerKind;
use App\Gateway\Identity\ActingIdentity;
use App\Gateway\OutcomeClass;
use App\Gateway\OutcomeStatus;
use App\Gateway\ToolGateway;
use App\Gateway\ToolRequest;
use App\Identity\Quarantine\CrossBusinessDetector;
use App\Identity\Quarantine\CrossBusinessSuppression;
use App\Identity\Quarantine\Finding;
use App\Identity\Repair\RepairPlanner;
use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Conversation;
use App\Models\CrossBusinessQuarantine;
use App\Models\Customer;
use App\Models\CustomerIdentityRepair;
use App\Models\CustomerMerge;
use App\Models\CustomerMergeReversal;
use App\Models\Store;
use App\Models\User;
use App\Models\UserBusinessScope;
use App\Tools\Identity\QuarantineRepairTool;
use App\Tools\ToolRegistry;
use Database\Seeders\AgentSeeder;
use Database\Seeders\DomainSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\Execution\Contexts;

/*
 | docs/126 (ruling docs/120 A3 all ten points, the detector and suppression paragraphs, C Stage 2, items 36, 37, 39).
 |
 | The fixture is the REAL production case (docs/121 §5) as the W1 migration leaves it: rows planted in the old shape
 | (no business on an identity), the docs/122 migration applied, so the Bionic store's `shop_order` and `shop_user`
 | ids under the LaLaDia customer are quarantined (`store_business_mismatch`) with open conflict rows, and the phone
 | (used by orders of both businesses) stays live under LaLaDia. Then the repair: a dry run that writes nothing, a
 | person's review of every row, ONE batch applied through the human-only gateway tool, and the proofs.
 */

beforeEach(function () {
    Cache::flush();
    Http::fake();
    config(['hq.alerts.telegram_bot_token' => '', 'hq.alerts.telegram_chat_id' => '']);
    $this->seed(DomainSeeder::class);
    $this->owner = $this->hqUser('owner');
    $this->laladia = Business::where('slug', 'laladia')->sole();
    $this->bionic = Business::where('slug', 'bionic')->sole();
    $this->lld = Store::where('slug', 'laladia-com')->sole();
    $this->bnc = Store::where('slug', 'bionic-garden')->sole();
});

function repScopeMigration(): object
{
    return require database_path('migrations/2026_10_15_100000_scope_customer_identities_to_business.php');
}

function repOldCustomer(int $businessId, string $name, ?string $phone): int
{
    return (int) DB::table('customers')->insertGetId(['business_id' => $businessId, 'name' => $name, 'primary_phone_canonical' => $phone,
        'first_seen_at' => now()->subMonths(2), 'last_seen_at' => now()->subDays(3), 'created_at' => now()->subMonths(2), 'updated_at' => now()->subDays(3)]);
}

function repOldIdentity(int $customerId, string $channel, string $externalId, ?int $storeId = null): int
{
    return (int) DB::table('customer_identities')->insertGetId(['customer_id' => $customerId, 'channel' => $channel, 'external_id' => $externalId,
        'source_store_id' => $storeId, 'confidence' => 1, 'created_at' => now()->subMonth(), 'updated_at' => now()->subMonth()]);
}

function repOrder(int $businessId, int $storeId, int $customerId, string $ref, ?string $phone, int $gross, string $placed = '-10 days'): int
{
    return (int) DB::table('facts_orders')->insertGetId(['business_id' => $businessId, 'store_id' => $storeId, 'order_ref' => $ref, 'status' => 'delivered',
        'gross' => $gross, 'customer_id' => $customerId, 'customer_phone_canonical' => $phone, 'placed_at' => now()->modify($placed),
        'created_at' => now()->modify($placed), 'updated_at' => now()->modify($placed)]);
}

/**
 * docs/121 §5: one Bionic order fact (business 3, store 2, placed 7 Sep) on LaLaDia customer #1; a store-scoped
 * `shop_order` and `shop_user` under the wrong business; one phone identity with evidence in both businesses.
 * Planted in the pre-W1 shape, then the W1 migration runs exactly as it will on production.
 *
 * @return array{customer: int, own: int, order16: int, phone: int, shopOrder: int, shopUser: int}
 */
function plantProductionCase(object $t, string $phone = '01711000016', string $ref = 'BNC-16', string $user = '216'): array
{
    repScopeMigration()->down();
    $customer = repOldCustomer($t->laladia->id, 'Customer One', $phone);
    $phoneId = repOldIdentity($customer, 'phone', $phone);
    $own = repOrder($t->laladia->id, $t->lld->id, $customer, 'LLD-1001', $phone, 1350, '-40 days');
    repOldIdentity($customer, 'shop_order', 'LLD-1001', $t->lld->id);
    $order16 = repOrder($t->bionic->id, $t->bnc->id, $customer, $ref, $phone, 820, '-39 days');
    $shopOrder = repOldIdentity($customer, 'shop_order', $ref, $t->bnc->id);
    $shopUser = repOldIdentity($customer, 'shop_user', $user, $t->bnc->id);
    // What the pre-fix upserter left: both businesses' copies counted on the LaLaDia row.
    DB::table('customers')->where('id', $customer)->update(['orders_count' => 2, 'lifetime_value' => 2170]);
    repScopeMigration()->up();

    return ['customer' => $customer, 'own' => $own, 'order16' => $order16, 'phone' => $phoneId, 'shopOrder' => $shopOrder, 'shopUser' => $shopUser];
}

/** Every table the repair may touch, by full rows. */
function repSnapshot(): array
{
    $tables = ['customers', 'customer_identities', 'customer_identity_conflicts', 'customer_merges', 'customer_merge_reversals', 'customer_identity_repairs',
        'facts_orders', 'conversations', 'conversation_participants', 'conversation_messages', 'memories', 'consent_events', 'consent_state',
        'audit_logs', 'cross_business_quarantines', 'cross_business_quarantine_reviews', 'tool_calls'];

    return collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->orderBy('id')->get()->toJson()])->all();
}

/** The detector's findings written as flags (the docs/117 command), then every flag reviewed by the person on the screen. */
function flagAndReview(object $t, string $decision = 'needs_repair', ?callable $only = null): void
{
    Artisan::call('hq:identity:cross-business', ['--flag' => true]);
    foreach (CrossBusinessQuarantine::query()->orderBy('id')->get() as $flag) {
        if ($only !== null && ! $only($flag)) {
            continue;
        }
        (fn () => $this->actingAsHq($this->owner)->post("/customers/quarantine/{$flag->id}/review", ['decision' => $decision, 'note' => 'checked the order and the store'])->assertRedirect())->call($t);
    }
}

function repairAs(User $user, string $key, int $businessId, ?ActingIdentity $identity = null, string $reason = 'Bionic order on the LaLaDia customer, reviewed on the screen')
{
    return app(ToolGateway::class)->execute(new ToolRequest(
        toolKey: QuarantineRepairTool::KEY,
        args: ['business_slug' => Business::find($businessId)->slug, 'repair_key' => $key, 'reason' => $reason],
        caller: CallerKind::HumanUi, identity: $identity ?? ActingIdentity::human($user), correlationId: (string) Str::uuid(), businessId: $businessId,
    ));
}

function bionicBatch(object $t): array
{
    $batch = app(RepairPlanner::class)->plan()->batchForBusiness($t->bionic->id);
    expect($batch)->not->toBeNull();

    return $batch;
}

it('reproduces the production case as W1 leaves it, and the dry run proposes exactly the order #16 split while writing nothing (A3 points 1, 2)', function () {
    $p = plantProductionCase($this);

    // The fixture is the case docs/121 §5 describes, after the W1 migration.
    $ids = DB::table('customer_identities')->orderBy('id')->get()->keyBy('id');
    expect($ids[$p['shopOrder']]->quarantine_reason)->toBe('store_business_mismatch')
        ->and($ids[$p['shopUser']]->quarantine_reason)->toBe('store_business_mismatch')
        ->and($ids[$p['phone']]->quarantined_at)->toBeNull()
        ->and((int) $ids[$p['phone']]->business_id)->toBe($this->laladia->id)
        ->and(DB::table('customer_identity_conflicts')->whereNull('resolved_at')->where('kind', 'quarantined')->pluck('identity_id')->map(fn ($v) => (int) $v)->sort()->values()->all())
        ->toBe([$p['shopOrder'], $p['shopUser']]);

    $before = repSnapshot();
    $plan = app(RepairPlanner::class)->plan();
    $again = app(RepairPlanner::class)->plan();
    $this->artisan('hq:identity:repair', ['--recount' => true])
        ->expectsOutputToContain('Batch for business [bionic]')
        ->expectsOutputToContain('to a NEW customer of [bionic]')
        ->expectsOutputToContain('lineage: no merge lineage')
        ->expectsOutputToContain('Dry run: nothing written')
        ->assertSuccessful();
    $this->artisan('hq:identity:repair', ['--json' => true])->assertSuccessful();
    expect(repSnapshot())->toBe($before);

    // Same data, same plan and key.
    expect(array_keys($plan->batches))->toBe([$this->bionic->id])
        ->and($again->batches[$this->bionic->id]['key'])->toBe($plan->batches[$this->bionic->id]['key']);
    $split = $plan->batches[$this->bionic->id]['splits'][0];
    expect($plan->batches[$this->bionic->id]['splits'])->toHaveCount(1)
        ->and($split['from_customer_id'])->toBe($p['customer'])
        ->and($split['from_business_id'])->toBe($this->laladia->id)
        ->and($split['to_customer_id'])->toBeNull()
        ->and($split['to_customer_created'])->toBeTrue()
        ->and($split['merge_id'])->toBeNull()
        ->and($split['new_customer'])->toBe(['phone' => '01711000016', 'name' => null])
        ->and($split['rows']['orders'])->toBe([$p['order16']])
        ->and($split['rows']['identities_move'])->toBe([$p['shopOrder'], $p['shopUser']])
        ->and($split['rows']['identities_copy'])->toBe([['channel' => 'phone', 'external_id' => '01711000016']])
        ->and($split['rows']['conflicts_resolve'])->toHaveCount(2)
        ->and($split['review']['authorized'])->toBeFalse()
        ->and($split['review']['unreviewed_rows'])->toHaveCount(3)
        ->and($plan->ambiguous)->toBe([])
        ->and($plan->heldIdentities)->toBe([])
        ->and($plan->openConflicts)->toBe([]);
});

it('refuses an unreviewed batch without writing even the batch row, then applies the reviewed one from the screen (A3 points 3, 4, 5, 10; item 36)', function () {
    $p = plantProductionCase($this);
    $key = bionicBatch($this)['key'];

    // Item 36: only after an explicit needs_repair review of every row.
    $this->actingAsHq($this->owner)->post('/customers/quarantine/repair', ['business_id' => $this->bionic->id, 'key' => $key, 'reason' => 'too early, nobody reviewed it'])
        ->assertSessionHasErrors('repair');
    expect(CustomerIdentityRepair::count())->toBe(0)->and(CustomerMergeReversal::count())->toBe(0);

    flagAndReview($this);
    $page = $this->actingAsHq($this->owner)->get('/customers/quarantine')->assertOk();
    $page->assertInertia(fn ($a) => $a->component('customers/quarantine')->where('can_repair', true)->where('batches.0.key', $key)->where('batches.0.authorized', true));

    $this->actingAsHq($this->owner)->post('/customers/quarantine/repair', ['business_id' => $this->bionic->id, 'key' => $key, 'reason' => 'Bionic order on the LaLaDia customer, reviewed'])
        ->assertSessionHasNoErrors()->assertRedirect();

    // Point 4: a customer of the row's own business, created from the row's own evidence (the phone, nothing of LaLaDia).
    $target = Customer::where('business_id', $this->bionic->id)->sole();
    expect($target->primary_phone_canonical)->toBe('01711000016')->and($target->name)->toBeNull()->and($target->email)->toBeNull()
        ->and((int) DB::table('facts_orders')->where('id', $p['order16'])->value('customer_id'))->toBe($target->id)
        ->and((int) DB::table('facts_orders')->where('id', $p['own'])->value('customer_id'))->toBe($p['customer']);
    $ids = DB::table('customer_identities')->orderBy('id')->get()->keyBy('id');
    foreach ([$p['shopOrder'], $p['shopUser']] as $id) {
        expect((int) $ids[$id]->customer_id)->toBe($target->id)->and((int) $ids[$id]->business_id)->toBe($this->bionic->id)->and($ids[$id]->quarantined_at)->toBeNull();
    }
    // The LaLaDia phone stays LaLaDia's; Bionic gets its own copy of the business-scoped identity.
    expect((int) $ids[$p['phone']]->customer_id)->toBe($p['customer'])->and((int) $ids[$p['phone']]->business_id)->toBe($this->laladia->id)
        ->and(DB::table('customer_identities')->where('business_id', $this->bionic->id)->where('channel', 'phone')->where('external_id', '01711000016')->value('customer_id'))->toBe($target->id);
    // The conflicts are resolved by the repair, with the person and the batch named.
    $conflicts = DB::table('customer_identity_conflicts')->orderBy('id')->get();
    expect($conflicts->whereNull('resolved_at'))->toHaveCount(0)
        ->and($conflicts->pluck('resolved_by')->unique()->values()->all())->toBe([$this->owner->id])
        ->and($conflicts->pluck('resolution')->every(fn ($r) => str_starts_with($r, 'repaired:')))->toBeTrue();

    // Point 5: lineage and audit evidence.
    $batch = CustomerIdentityRepair::sole();
    $reversal = CustomerMergeReversal::sole();
    expect($batch->status)->toBe('verified')->and($batch->verified_at)->not->toBeNull()->and($batch->applied_by)->toBe($this->owner->id)
        ->and($reversal->repair_id)->toBe($batch->id)->and($reversal->merge_id)->toBeNull()
        ->and($reversal->from_customer_id)->toBe($p['customer'])->and($reversal->to_customer_id)->toBe($target->id)
        ->and($reversal->to_customer_created)->toBeTrue()
        ->and($reversal->moved['orders'])->toBe([$p['order16']])
        ->and($reversal->moved['identities'])->toBe([$p['shopOrder'], $p['shopUser']]);
    $actions = AuditLog::orderBy('id')->pluck('action')->all();
    expect($actions)->toContain('customer.repair.started', 'customer.created', 'customer.repair.applied', 'customer.repair.verified', 'tool.customers_quarantine_repair');
    expect(AuditLog::where('action', 'customer.repair.applied')->sole()->actor_id)->toBe($this->owner->id);

    // Point 10: verified only after ownership and the business-scoped reads were checked.
    expect($batch->report['verification']['ok'])->toBeTrue()->and($batch->report['verification']['checks'])->toBeGreaterThan(3)
        ->and(app(CrossBusinessDetector::class)->detect())->toBe([]);
});

it('is idempotent: a second apply changes nothing and the next dry run proposes nothing (A3 point 6)', function () {
    plantProductionCase($this);
    flagAndReview($this);
    $key = bionicBatch($this)['key'];
    expect(repairAs($this->owner, $key, $this->bionic->id)->output['status'])->toBe('verified');

    $rows = fn () => collect(['customers', 'customer_identities', 'customer_identity_conflicts', 'facts_orders', 'customer_merge_reversals', 'customer_identity_repairs', 'consent_events'])
        ->mapWithKeys(fn ($t) => [$t => DB::table($t)->orderBy('id')->get()->toJson()])->all();
    $before = $rows();
    $second = repairAs($this->owner, $key, $this->bionic->id);
    expect($second->status)->toBe(OutcomeStatus::Succeeded)->and($second->output['status'])->toBe('already_verified')->and($second->output['replayed'])->toBeTrue()
        ->and($rows())->toBe($before)
        ->and(app(RepairPlanner::class)->plan()->isEmpty())->toBeTrue();
    $this->artisan('hq:identity:repair')->expectsOutputToContain('No split to propose')->assertSuccessful();
});

/**
 * A pre-guard cross-business merge as the W1 migration leaves it (the docs/117 fixture, in the new shape): the
 * Bionic customer absorbed into the LaLaDia one; its identities, order and memories re-pointed; its pointer set;
 * a stray Bionic thread with a participant and a message under the LaLaDia row; Bionic consent rows under the
 * LaLaDia row (a grant, and a do-not-contact that is still the latest word at its scope); business-less memories.
 */
function plantMergeCase(object $t): array
{
    $kept = Customer::create(['business_id' => $t->laladia->id, 'name' => 'Karim LaLaDia', 'primary_phone_canonical' => '01711222333']);
    DB::table('customer_identities')->insert(['customer_id' => $kept->id, 'business_id' => $t->laladia->id, 'channel' => 'phone', 'external_id' => '01711222333', 'confidence' => 1, 'created_at' => now(), 'updated_at' => now()]);
    repOrder($t->laladia->id, $t->lld->id, $kept->id, 'LLD-OWN-1', '01711222333', 700);
    $absorbed = Customer::create(['business_id' => $t->bionic->id, 'name' => 'Secret Bionic Person', 'primary_phone_canonical' => '01999888777', 'email' => 'secret-bionic@example.test']);
    $moved = [];
    foreach ([['phone', '01999888777', null, null], ['messenger', 'psid-secret-bionic', null, null], ['shop_order', 'BNC-SECRET-9', $t->bnc->id, 'store_business_mismatch']] as [$ch, $ext, $store, $q]) {
        $moved[] = (int) DB::table('customer_identities')->insertGetId(['customer_id' => $kept->id, 'business_id' => $t->laladia->id, 'channel' => $ch, 'external_id' => $ext,
            'source_store_id' => $store, 'confidence' => 1, 'quarantined_at' => $q === null ? null : now(), 'quarantine_reason' => $q, 'created_at' => now(), 'updated_at' => now()]);
    }
    $bOrder = repOrder($t->bionic->id, $t->bnc->id, $kept->id, 'BNC-SECRET-9', '01999888777', 5000);
    $memory = fn (?int $business, string $text, string $at) => (int) DB::table('memories')->insertGetId(['uuid' => (string) Str::uuid(), 'kind' => 'episodic',
        'business_id' => $business, 'customer_id' => $kept->id, 'content' => $text, 'provenance' => 'business', 'created_at' => now()->modify($at), 'updated_at' => now()->modify($at)]);
    $scopedMemory = $memory($t->bionic->id, 'Secret bionic scoped memory', '-6 days');
    $earlyUnscoped = $memory(null, 'Secret bionic unscoped memory', '-6 days');
    $merge = CustomerMerge::create(['kept_id' => $kept->id, 'absorbed_id' => $absorbed->id, 'reason' => 'same person (pre-guard)', 'moved_identity_ids' => $moved]);
    DB::table('customer_merges')->where('id', $merge->id)->update(['created_at' => now()->subDays(5)]);
    DB::table('customers')->where('id', $absorbed->id)->update(['merged_into_id' => $kept->id]);
    DB::table('customers')->where('id', $kept->id)->update(['orders_count' => 2, 'lifetime_value' => 5700, 'email' => 'secret-bionic@example.test']);
    $lateUnscoped = $memory(null, 'Kept later unscoped memory', '-1 day');

    $thread = (int) DB::table('conversations')->insertGetId(['uuid' => (string) Str::uuid(), 'business_id' => $t->bionic->id, 'customer_id' => $kept->id, 'channel' => 'whatsapp',
        'status' => 'open', 'state' => ConversationState::HumanRequired->value, 'opened_at' => now()->subDays(2), 'last_message_at' => now()->subDays(2), 'created_at' => now(), 'updated_at' => now()]);
    $participant = (int) DB::table('conversation_participants')->insertGetId(['conversation_id' => $thread, 'participant_type' => 'customer', 'customer_id' => $kept->id,
        'role' => 'participant', 'joined_at' => now()->subDays(2), 'created_at' => now(), 'updated_at' => now()]);
    $message = (int) DB::table('conversation_messages')->insertGetId(['uuid' => (string) Str::uuid(), 'conversation_id' => $thread, 'direction' => 'inbound', 'author_type' => 'customer',
        'customer_id' => $kept->id, 'channel' => 'whatsapp', 'body' => 'secret bionic message', 'received_at' => now()->subDays(2), 'created_at' => now(), 'updated_at' => now()]);

    $consent = fn (string $state, string $purpose, string $at) => (int) DB::table('consent_events')->insertGetId(['customer_id' => $kept->id, 'business_id' => $t->bionic->id,
        'channel' => 'whatsapp', 'purpose' => $purpose, 'state' => $state, 'basis' => 'operator', 'occurred_at' => now()->modify($at), 'recorded_at' => now()->modify($at),
        'actor_type' => 'system', 'reason' => 'stray']);
    $grant = $consent('granted', 'service', '-8 days');
    $dnc = $consent('do_not_contact', 'marketing', '-7 days');

    return compact('kept', 'absorbed', 'moved', 'bOrder', 'scopedMemory', 'earlyUnscoped', 'lateUnscoped', 'merge', 'thread', 'participant', 'message', 'grant', 'dnc');
}

it('reverses a historical merge with lineage: the merge row untouched, a reversal row, the absorbed row back, threads moved, idempotent (A3 points 4, 5, 6, 7)', function () {
    $m = plantMergeCase($this);
    $mergeRow = DB::table('customer_merges')->where('id', $m['merge']->id)->first();
    flagAndReview($this);
    $batch = bionicBatch($this);
    $split = $batch['splits'][0];
    expect($split['to_customer_id'])->toBe($m['absorbed']->id)->and($split['merge_id'])->toBe($m['merge']->id)->and($split['clear_merge_pointer'])->toBeTrue();

    // The console path: --apply goes through the same gateway tool, as the person named by --by.
    $this->artisan('hq:identity:repair', ['--apply' => true, '--key' => $batch['key'], '--by' => $this->owner->email, '--reason' => 'pre-guard merge, reviewed on the screen'])
        ->expectsOutputToContain('verified')->assertSuccessful();

    // Point 7: the original merge row is byte for byte what it was; the reversal is its own, append-only row.
    expect(DB::table('customer_merges')->where('id', $m['merge']->id)->first())->toEqual($mergeRow);
    $reversal = CustomerMergeReversal::sole();
    expect($reversal->merge_id)->toBe($m['merge']->id)->and($reversal->from_customer_id)->toBe($m['kept']->id)->and($reversal->to_customer_id)->toBe($m['absorbed']->id)
        ->and($reversal->to_customer_created)->toBeFalse();
    expect(fn () => DB::transaction(fn () => DB::table('customer_merge_reversals')->where('id', $reversal->id)->update(['reason' => 'rewritten'])))->toThrow(Exception::class, 'append-only');
    expect(fn () => DB::transaction(fn () => DB::table('customer_merge_reversals')->where('id', $reversal->id)->delete()))->toThrow(Exception::class, 'append-only');
    expect(fn () => $reversal->delete())->toThrow(LogicException::class);

    // Point 4: rows of Bionic go to the Bionic customer; nothing of LaLaDia moves.
    $absorbed = $m['absorbed']->fresh();
    expect($absorbed->merged_into_id)->toBeNull()
        ->and((int) DB::table('facts_orders')->where('id', $m['bOrder'])->value('customer_id'))->toBe($absorbed->id)
        ->and((int) DB::table('conversations')->where('id', $m['thread'])->value('customer_id'))->toBe($absorbed->id)
        ->and((int) DB::table('conversation_participants')->where('id', $m['participant'])->value('customer_id'))->toBe($absorbed->id)
        ->and((int) DB::table('conversation_messages')->where('id', $m['message'])->value('customer_id'))->toBe($absorbed->id)
        ->and((int) DB::table('memories')->where('id', $m['scopedMemory'])->value('customer_id'))->toBe($absorbed->id);
    foreach ($m['moved'] as $id) {
        $row = DB::table('customer_identities')->where('id', $id)->first();
        expect((int) $row->customer_id)->toBe($absorbed->id)->and((int) $row->business_id)->toBe($this->bionic->id)->and($row->quarantined_at)->toBeNull();
    }
    expect(DB::table('customer_identities')->where('customer_id', $m['kept']->id)->pluck('external_id')->all())->toBe(['01711222333']);

    // Point 6, merge lineage: the merge row still joins two businesses, yet nothing is proposed again (the reversal names it).
    expect(app(RepairPlanner::class)->plan()->isEmpty())->toBeTrue()
        ->and(collect(app(CrossBusinessDetector::class)->detect())->firstWhere('reason', 'cross_business_merge')?->detail['reversed_by'])->toBe($reversal->id);
});

it('never moves or converts consent: rows untouched, the grant not carried, the do-not-contact preserved on the target, a human re-record list (A3 point 9)', function () {
    $m = plantMergeCase($this);
    $before = DB::table('consent_events')->orderBy('id')->get()->toJson();
    flagAndReview($this);
    $plan = app(RepairPlanner::class)->plan();
    $list = $plan->humanLists['consent_rerecord'];
    expect($list)->toHaveCount(2)
        ->and($list[0]['id'])->toBe($m['dnc'])->and($list[0]['restriction'])->toBeTrue()   // restrictions first
        ->and($list[1]['id'])->toBe($m['grant'])->and($list[1]['restriction'])->toBeFalse();

    repairAs($this->owner, $plan->batches[$this->bionic->id]['key'], $this->bionic->id);

    // The original rows: same ids, same content, still under the LaLaDia row.
    $after = DB::table('consent_events')->orderBy('id')->get();
    expect($after->whereIn('id', [$m['grant'], $m['dnc']])->values()->toJson())->toBe($before);
    // One new row: the do-not-contact, appended for the Bionic customer, naming the original. No grant.
    $carried = $after->where('customer_id', $m['absorbed']->id)->values();
    expect($carried)->toHaveCount(1)
        ->and($carried[0]->state)->toBe('do_not_contact')->and($carried[0]->purpose)->toBe('marketing')->and($carried[0]->evidence_ref)->toBe("consent_events#{$m['dnc']}");
    $ledger = app(ConsentLedger::class);
    expect($ledger->permits($m['absorbed']->id, $this->bionic->id, 'whatsapp', ConsentPurpose::Service)->permitted)->toBeFalse()
        ->and($ledger->permits($m['absorbed']->id, $this->bionic->id, 'whatsapp', ConsentPurpose::Marketing)->reason)->toContain('do-not-contact');
    $report = CustomerIdentityRepair::sole()->report;
    expect($report['human_lists']['consent_rerecord'])->toHaveCount(2)
        ->and($report['splits'][0]['moved']['restrictions_carried'][0]['from_event'])->toBe($m['dnc']);
});

it('withholds business-less memories from Customer 360 until an authorized person assigns them (A3 point 8)', function () {
    $m = plantMergeCase($this);
    flagAndReview($this);
    repairAs($this->owner, bionicBatch($this)['key'], $this->bionic->id);
    $reader = app(Customer360Reader::class);

    $kept = json_encode($reader->read($m['kept']->fresh(), $this->owner));
    expect($kept)->not->toContain('Kept later unscoped memory')->not->toContain('Secret bionic unscoped memory')
        ->and(CrossBusinessSuppression::heldMemoryIds($m['kept']->id))->toBe([$m['earlyUnscoped'], $m['lateUnscoped']]);

    $this->actingAsHq($this->owner)->get('/customers/quarantine')->assertOk()
        ->assertInertia(fn ($a) => $a->has('held_memories', 2)->where('held_memories.0.memory_id', $m['earlyUnscoped'])
            ->where('held_memories.0.choices', [$this->laladia->id, $this->bionic->id]));

    // Not without the permission, and only to a business the evidence names.
    $manager = $this->hqUser('brand_manager');
    $this->actingAsHq($manager)->post("/customers/quarantine/memories/{$m['lateUnscoped']}/assign", ['business_id' => $this->laladia->id, 'note' => 'ours'])->assertForbidden();
    $other = Business::create(['slug' => 'other-biz', 'name' => 'Other']);
    $this->actingAsHq($this->owner)->post("/customers/quarantine/memories/{$m['lateUnscoped']}/assign", ['business_id' => $other->id, 'note' => 'guess'])->assertSessionHasErrors('memory');

    $this->actingAsHq($this->owner)->post("/customers/quarantine/memories/{$m['lateUnscoped']}/assign", ['business_id' => $this->laladia->id, 'note' => 'LaLaDia agent wrote it after the merge'])
        ->assertSessionHasNoErrors();
    expect((int) DB::table('memories')->where('id', $m['lateUnscoped'])->value('business_id'))->toBe($this->laladia->id)
        ->and(json_encode($reader->read($m['kept']->fresh(), $this->owner)))->toContain('Kept later unscoped memory')->not->toContain('Secret bionic unscoped memory')
        ->and(AuditLog::where('action', 'memory.business_assigned')->sole()->actor_id)->toBe($this->owner->id);

    // Assigned to Bionic, the other one becomes a Bionic row under the LaLaDia customer: the next batch carries it, never the page.
    $this->actingAsHq($this->owner)->post("/customers/quarantine/memories/{$m['earlyUnscoped']}/assign", ['business_id' => $this->bionic->id, 'note' => 'came from the Bionic side'])
        ->assertSessionHasNoErrors();
    expect(json_encode($reader->read($m['kept']->fresh(), $this->owner)))->not->toContain('Secret bionic unscoped memory')
        ->and(collect(app(CrossBusinessDetector::class)->detect())->contains(fn (Finding $f) => $f->subjectId === $m['earlyUnscoped'] && $f->reason === 'memory_other_business'))->toBeTrue();
});

it('quarantines and reports a row whose business it cannot establish, never guessing (A3 last paragraph)', function () {
    // A clean split and an ambiguous one in the same Bionic batch.
    $clean = plantProductionCase($this);
    $l2 = Customer::create(['business_id' => $this->laladia->id, 'name' => 'L2', 'primary_phone_canonical' => '01722000022']);
    $ambiguousOrder = repOrder($this->bionic->id, $this->bnc->id, $l2->id, 'BNC-AMB', '01722000022', 400);
    // Two Bionic customers carry that phone: which one the order belongs to is a person's call.
    $b1 = Customer::create(['business_id' => $this->bionic->id, 'name' => 'B1', 'primary_phone_canonical' => '01722000022']);
    $b2 = Customer::create(['business_id' => $this->bionic->id, 'name' => 'B2', 'primary_phone_canonical' => '01722000022']);

    $plan = app(RepairPlanner::class)->plan();
    expect($plan->batches[$this->bionic->id]['splits'])->toHaveCount(1)
        ->and($plan->ambiguous)->toHaveCount(1)
        ->and($plan->ambiguous[0]['reason'])->toBe('repair_ambiguous_target')
        ->and($plan->ambiguous[0]['why'])->toContain((string) $b1->id)->toContain((string) $b2->id);

    flagAndReview($this);
    $out = repairAs($this->owner, bionicBatch($this)['key'], $this->bionic->id)->output;

    expect($out['status'])->toBe('verified')->and($out['ambiguous_flagged'])->toBe(1)
        ->and((int) DB::table('facts_orders')->where('id', $ambiguousOrder)->value('customer_id'))->toBe($l2->id)
        ->and((int) DB::table('facts_orders')->where('id', $clean['order16'])->value('customer_id'))->not->toBe($clean['customer']);
    $flag = CrossBusinessQuarantine::where('reason', 'repair_ambiguous_target')->sole();
    expect($flag->subject_table)->toBe('facts_orders')->and($flag->subject_id)->toBe($ambiguousOrder)->and($flag->kind)->toBe('order')
        ->and($flag->detail['why'])->toContain('more than one customer')
        ->and($flag->flagged_by)->toBe($this->owner->id);
    // Still reported, still not proposed for a batch.
    $next = app(RepairPlanner::class)->plan();
    expect($next->isEmpty())->toBeTrue()->and($next->ambiguous)->toHaveCount(1);
});

it('holds an identity used by rows of two businesses instead of moving it (A3 last paragraph)', function () {
    $p = plantProductionCase($this);
    // A LaLaDia thread's participant also uses the Bionic shop_user id: moving it would create a new crossing.
    $thread = (int) DB::table('conversations')->insertGetId(['uuid' => (string) Str::uuid(), 'business_id' => $this->laladia->id, 'customer_id' => $p['customer'], 'channel' => 'messenger',
        'status' => 'open', 'state' => ConversationState::HumanRequired->value, 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('conversation_participants')->insert(['conversation_id' => $thread, 'participant_type' => 'customer', 'customer_id' => $p['customer'],
        'identity_id' => $p['shopUser'], 'role' => 'participant', 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

    $split = bionicBatch($this)['splits'][0];
    $plan = app(RepairPlanner::class)->plan();
    expect($split['rows']['identities_move'])->toBe([$p['shopOrder']])
        ->and(collect($plan->ambiguous)->firstWhere('reason', 'repair_ambiguous_identity')['subject_id'])->toBe($p['shopUser'])
        ->and(collect($plan->openConflicts)->pluck('identity_id')->all())->toBe([$p['shopUser']]);
});

it('is restartable after a mid-batch failure: the done split is skipped, the failed one applied on resume (A3 point 6)', function () {
    $a = plantProductionCase($this);
    $l2 = Customer::create(['business_id' => $this->laladia->id, 'name' => 'L2', 'primary_phone_canonical' => '01733000033']);
    $second = repOrder($this->bionic->id, $this->bnc->id, $l2->id, 'BNC-33', '01733000033', 300);
    flagAndReview($this);
    $batch = bionicBatch($this);
    expect($batch['splits'])->toHaveCount(2);

    // The database fails the second split's order move, part way through the batch.
    DB::unprepared(<<<SQL
        CREATE FUNCTION rep_test_fail() RETURNS trigger AS \$\$ BEGIN RAISE EXCEPTION 'simulated crash mid-batch'; END; \$\$ LANGUAGE plpgsql;
        CREATE TRIGGER rep_test_fail BEFORE UPDATE ON facts_orders FOR EACH ROW WHEN (OLD.id = {$second}) EXECUTE FUNCTION rep_test_fail();
    SQL);
    $first = repairAs($this->owner, $batch['key'], $this->bionic->id)->output;
    expect($first['status'])->toBe('partial')
        ->and(collect($first['splits'])->pluck('status')->all())->toBe(['applied', 'failed'])
        ->and(CustomerMergeReversal::count())->toBe(1)
        ->and((int) DB::table('facts_orders')->where('id', $second)->value('customer_id'))->toBe($l2->id)
        ->and(Customer::where('business_id', $this->bionic->id)->count())->toBe(1);   // the failed split's customer rolled back with it

    DB::unprepared('DROP TRIGGER rep_test_fail ON facts_orders; DROP FUNCTION rep_test_fail();');
    $resume = repairAs($this->owner, $batch['key'], $this->bionic->id)->output;
    expect($resume['status'])->toBe('verified')
        ->and(collect($resume['splits'])->pluck('status')->all())->toBe(['already_applied', 'applied'])
        ->and(CustomerMergeReversal::count())->toBe(2)
        ->and(CustomerIdentityRepair::sole()->status)->toBe('verified')
        ->and(AuditLog::where('action', 'customer.repair.resumed')->count())->toBe(1)
        ->and(app(RepairPlanner::class)->plan()->isEmpty())->toBeTrue();
});

it('refuses a concurrent change and a second repair running at the same time (A3 point 6)', function () {
    $p = plantProductionCase($this);
    flagAndReview($this);
    $key = bionicBatch($this)['key'];

    // Another repair holds the lock: refused, nothing written.
    config(['database.connections.rep_other' => config('database.connections.pgsql')]);
    DB::connection('rep_other')->select('SELECT pg_advisory_lock(126000126)');
    $locked = repairAs($this->owner, $key, $this->bionic->id);
    DB::connection('rep_other')->select('SELECT pg_advisory_unlock(126000126)');
    DB::purge('rep_other');
    expect($locked->status)->toBe(OutcomeStatus::Failed)->and($locked->error)->toContain('Another identity repair')
        ->and(CustomerIdentityRepair::count())->toBe(0);

    // A shop event touches order #16 after the person read the dry run: the key they hold no longer names the data.
    DB::table('facts_orders')->where('id', $p['order16'])->update(['status' => 'returned', 'updated_at' => now()->addMinute()]);
    $changed = repairAs($this->owner, $key, $this->bionic->id);
    expect($changed->status)->toBe(OutcomeStatus::Failed)->and($changed->error)->toContain('the data changed since the dry run')
        ->and(CustomerIdentityRepair::count())->toBe(0)
        ->and((int) DB::table('facts_orders')->where('id', $p['order16'])->value('customer_id'))->toBe($p['customer']);

    // The person looks again: the new dry run has a new key, and that one applies.
    $fresh = bionicBatch($this)['key'];
    expect($fresh)->not->toBe($key)
        ->and(repairAs($this->owner, $fresh, $this->bionic->id)->output['status'])->toBe('verified');
});

it('refuses a split whose rows change while its batch is being applied, under the lock (A3 point 6)', function () {
    plantProductionCase($this);
    $l2 = Customer::create(['business_id' => $this->laladia->id, 'name' => 'L2', 'primary_phone_canonical' => '01733000033']);
    $second = repOrder($this->bionic->id, $this->bnc->id, $l2->id, 'BNC-33', '01733000033', 300);
    flagAndReview($this);
    $key = bionicBatch($this)['key'];

    // The batch starts, the second split fails part way; then a shop event changes that split's order.
    DB::unprepared(<<<SQL
        CREATE FUNCTION rep_test_fail() RETURNS trigger AS \$\$ BEGIN RAISE EXCEPTION 'simulated crash mid-batch'; END; \$\$ LANGUAGE plpgsql;
        CREATE TRIGGER rep_test_fail BEFORE UPDATE ON facts_orders FOR EACH ROW WHEN (OLD.id = {$second}) EXECUTE FUNCTION rep_test_fail();
    SQL);
    expect(repairAs($this->owner, $key, $this->bionic->id)->output['status'])->toBe('partial');
    DB::unprepared('DROP TRIGGER rep_test_fail ON facts_orders; DROP FUNCTION rep_test_fail();');
    DB::table('facts_orders')->where('id', $second)->update(['gross' => 999, 'updated_at' => now()->addMinute()]);

    $resume = repairAs($this->owner, $key, $this->bionic->id)->output;
    expect($resume['status'])->toBe('partial')
        ->and($resume['splits'][0]['status'])->toBe('already_applied')
        ->and($resume['splits'][1]['status'])->toBe('refused')->and($resume['splits'][1]['why'])->toContain('concurrent change')
        ->and((int) DB::table('facts_orders')->where('id', $second)->value('customer_id'))->toBe($l2->id)
        ->and(CustomerMergeReversal::count())->toBe(1);
});

it('recounts by business and verifies every count against the source rows, documenting what is excluded (item 37)', function () {
    $p = plantProductionCase($this);
    flagAndReview($this);
    $recount = repairAs($this->owner, bionicBatch($this)['key'], $this->bionic->id)->output['recount'];

    expect($recount['verified'])->toBeTrue();
    $byBusiness = collect($recount['businesses'])->keyBy('business_id');
    foreach ([$this->laladia->id, $this->bionic->id] as $b) {
        expect($byBusiness[$b]['orders'])->toBe(DB::table('facts_orders')->where('business_id', $b)->count())
            ->and($byBusiness[$b]['orders_under_own_customer'])->toBe($byBusiness[$b]['orders'] - $byBusiness[$b]['orders_without_customer'])
            ->and($byBusiness[$b]['live_identities'])->toBe(DB::table('customer_identities')->where('business_id', $b)->whereNull('quarantined_at')->count());
    }
    $customers = collect($recount['customers'])->keyBy('customer_id');
    expect($customers[$p['customer']]['orders_count'])->toBe(['stored' => 1, 'from_rows' => 1, 'ok' => true])
        ->and($customers[$p['customer']]['lifetime_value']['from_rows'])->toBe('1350.00')
        ->and($customers[$p['customer']]['foreign_rows_still_under'])->toBe(0)
        ->and($recount['excluded'])->toBe(['still_crossing' => [], 'quarantined_identities_by_reason' => [], 'open_conflicts' => 0]);
    $target = Customer::where('business_id', $this->bionic->id)->sole();
    expect($target->orders_count)->toBe(1)->and((string) $target->lifetime_value)->toBe('820.00');
});

it('detects participants and messages and suppresses them before any flag, then reads business-scoped after the repair: 360, inbox, support context (item 39, A3 point 10)', function () {
    $m = plantMergeCase($this);
    $thread = DB::table('conversations')->where('id', $m['thread'])->first();

    // Item 39: read-only findings for the thread rows; suppression without a single flag written.
    $reasons = collect(app(CrossBusinessDetector::class)->detect())->pluck('reason')->all();
    expect($reasons)->toContain('participant_other_business', 'message_other_business')
        ->and(CrossBusinessQuarantine::count())->toBe(0)
        ->and(app(CrossBusinessSuppression::class)->forCustomer($m['kept'])->withheld())->toContain('threads');

    flagAndReview($this);
    repairAs($this->owner, bionicBatch($this)['key'], $this->bionic->id);

    $reader = app(Customer360Reader::class);
    $kept = json_encode($reader->read($m['kept']->fresh(), $this->owner));
    $absorbed = json_encode($reader->read($m['absorbed']->fresh(), $this->owner));
    expect($kept)->not->toContain('BNC-SECRET-9')->not->toContain($thread->uuid)->not->toContain('Secret bionic')
        ->and($absorbed)->toContain('BNC-SECRET-9')->toContain($thread->uuid);

    $this->actingAsHq($this->owner)->get('/inbox/'.$thread->uuid)->assertOk()
        ->assertInertia(fn ($a) => $a->where('thread.customer_id', $m['absorbed']->id)->has('orders', 1)->where('orders.0.ref', 'BNC-SECRET-9'));
    $context = collect(app(SupportContext::class)->blocksFor(Conversation::find($m['thread'])))->map(fn ($b) => $b->content)->implode("\n");
    expect($context)->toContain('BNC-SECRET-9')->toContain('orders on record: 1')->not->toContain('LLD-OWN-1')->not->toContain('Karim');
});

it('is the human-only gateway tool: refused to agents permanently, without the permission, and across a business the person may not read (item 36)', function () {
    $this->seed(AgentSeeder::class);
    $d = app(ToolRegistry::class)->get(QuarantineRepairTool::KEY)->descriptor();
    expect($d->permanentlyHumanOnly)->toBeTrue()->and($d->agentExecutable)->toBeFalse()->and($d->permission)->toBe('hq.customers.quarantine.repair')
        ->and($d->riskTier->value)->toBe('r3')->and($d->hasEffect())->toBeTrue();
    foreach (Agent::all() as $agent) {
        expect($agent->mayUseTool(QuarantineRepairTool::KEY))->toBeFalse();
    }

    plantProductionCase($this);
    flagAndReview($this);
    $key = bionicBatch($this)['key'];

    $agent = Agent::create(['key' => 'test-repairer', 'name' => 'Test', 'status' => 'active', 'enabling_user_id' => $this->owner->id, 'allowed_tools' => [QuarantineRepairTool::KEY]]);
    $asAgent = repairAs($this->owner, $key, $this->bionic->id, ActingIdentity::forExecution(Contexts::for($agent)));
    expect($asAgent->status)->toBe(OutcomeStatus::Denied)->and($asAgent->policyReason)->toContain('permanently human-only');

    $reviewer = User::factory()->create();
    $reviewer->givePermissionTo(['hq.customers.quarantine.review']);
    $noPermission = repairAs($reviewer->fresh(), $key, $this->bionic->id);
    expect($noPermission->status)->toBe(OutcomeStatus::Denied)->and($noPermission->class)->toBe(OutcomeClass::PermissionDenied);
    $manager = $this->hqUser('brand_manager');
    $this->actingAsHq($manager)->post('/customers/quarantine/repair', ['business_id' => $this->bionic->id, 'key' => $key, 'reason' => 'I would like to repair it'])->assertForbidden();

    // Holding the permission but restricted to Bionic: the batch joins LaLaDia, so it is refused before anything is written.
    $restricted = User::factory()->create();
    $restricted->givePermissionTo([QuarantineRepairTool::PERMISSION]);
    UserBusinessScope::create(['user_id' => $restricted->id, 'business_id' => $this->bionic->id, 'access' => 'full']);
    $scoped = repairAs($restricted->fresh(), $key, $this->bionic->id);
    expect($scoped->status)->toBe(OutcomeStatus::Failed)->and($scoped->error)->toContain('may not read');

    expect(CustomerIdentityRepair::count())->toBe(0)->and(CustomerMergeReversal::count())->toBe(0)
        ->and(Customer::where('business_id', $this->bionic->id)->count())->toBe(0);
    // Owner and operator hold the permission through '*'; nobody else.
    foreach (RolesAndPermissionsSeeder::ROLES as $role => $permissions) {
        expect($permissions === '*' || ! in_array('hq.customers.quarantine.repair', $permissions, true))->toBeTrue("[{$role}] is given the repair permission");
    }
});

it('withholds on the follow-ups screen a phone the Customer 360 page withholds (docs/122 Q3)', function () {
    $p = plantProductionCase($this, '01744000044', 'BNC-44', '244');
    // The phone only a Bionic order under this LaLaDia customer carries: drop the LaLaDia order's copy so it is withheld.
    DB::table('facts_orders')->where('id', $p['own'])->update(['customer_phone_canonical' => null]);
    expect(CrossBusinessSuppression::withheldPhoneCustomerIds([$p['customer']]))->toBe([$p['customer']]);
    DB::table('follow_ups')->insert(['uuid' => (string) Str::uuid(), 'business_id' => $this->laladia->id, 'customer_id' => $p['customer'], 'origin' => 'operator', 'kind' => 'service',
        'channel' => 'whatsapp', 'due_at' => now()->addDay(), 'intent' => 'call back about the delivery', 'state' => 'proposed', 'proposer_type' => Proposer::PERSON,
        'proposed_by_user_id' => $this->owner->id, 'created_at' => now(), 'updated_at' => now()]);

    $this->actingAsHq($this->owner)->get('/follow-ups')->assertOk()
        ->assertInertia(fn ($a) => $a->where('followUps.0.customer.id', $p['customer'])->where('followUps.0.customer.phone', null));
});
