<?php

use App\Identity\IdentityQuarantined;
use App\Identity\IdentityResolver;
use App\Models\Business;
use App\Models\Store;
use Database\Seeders\DomainSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | docs/122 (ruling docs/120 A2 item 6, Stage 1): the migration over clean, duplicate and corrupted data.
 |
 | Each test rolls the migration back (PostgreSQL DDL is transactional, and the test's transaction undoes it all),
 | plants rows in the OLD shape (no business on an identity, NULL stores never unique), applies the migration
 | again and reads what it decided: the owner's business where the evidence agrees, quarantine where it does not,
 | never a guess.
 */

/**
 * The W1 migration, with the docs/140 resolutions table (which references customer_identity_conflicts) taken down before
 * it and put back after it, as a real rollback and re-apply would order them.
 */
function scopeMigration(): object
{
    $scope = require database_path('migrations/2026_10_15_100000_scope_customer_identities_to_business.php');
    $resolutions = require database_path('migrations/2026_10_17_110000_create_identity_conflict_resolutions.php');

    return new class($scope, $resolutions)
    {
        public function __construct(private object $scope, private object $resolutions) {}

        public function down(): void
        {
            if (DB::selectOne("SELECT to_regclass('customer_identity_conflict_resolutions') IS NOT NULL AS present")->present) {
                $this->resolutions->down();
            }
            $this->scope->down();
        }

        public function up(): void
        {
            $this->scope->up();
            $this->resolutions->up();
        }
    };
}

beforeEach(function () {
    $this->seed(DomainSeeder::class);
    $this->lld = Store::where('slug', 'laladia-com')->sole();
    $this->bnc = Store::where('slug', 'bionic-garden')->sole();
    $this->laladia = Business::where('slug', 'laladia')->sole();
    $this->bionic = Business::where('slug', 'bionic')->sole();
    scopeMigration()->down();
});

function oldCustomer(int $businessId, string $name): int
{
    return (int) DB::table('customers')->insertGetId(['business_id' => $businessId, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
}

function oldIdentity(int $customerId, string $channel, string $externalId, ?int $storeId = null): int
{
    return (int) DB::table('customer_identities')->insertGetId(['customer_id' => $customerId, 'channel' => $channel, 'external_id' => $externalId,
        'source_store_id' => $storeId, 'confidence' => 1, 'created_at' => now(), 'updated_at' => now()]);
}

function oldOrder(int $businessId, int $storeId, int $customerId, string $ref, ?string $phone): void
{
    DB::table('facts_orders')->insert(['business_id' => $businessId, 'store_id' => $storeId, 'order_ref' => $ref, 'status' => 'pending', 'gross' => 1,
        'customer_id' => $customerId, 'customer_phone_canonical' => $phone, 'placed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
}

/** @return array<int, object> identity rows by id after the migration */
function identitiesAfter(): array
{
    return DB::table('customer_identities')->orderBy('id')->get()->keyBy('id')->all();
}

it('assigns every row of a clean dataset its owner\'s business and quarantines nothing', function () {
    $l = oldCustomer($this->laladia->id, 'L');
    $b = oldCustomer($this->bionic->id, 'B');
    // One phone in both businesses (possible before only because NULL stores were never unique), each used by
    // its own business's orders: two legitimate rows.
    $lPhone = oldIdentity($l, 'phone', '01711000000');
    $bPhone = oldIdentity($b, 'phone', '01711000000');
    $lUser = oldIdentity($l, 'shop_user', '7', $this->lld->id);
    $bOrder = oldIdentity($b, 'shop_order', 'BNC-1', $this->bnc->id);
    $lEmail = oldIdentity($l, 'email', 'l@example.test');
    oldOrder($this->laladia->id, $this->lld->id, $l, 'LLD-1', '01711000000');
    oldOrder($this->bionic->id, $this->bnc->id, $b, 'BNC-1', '01711000000');

    scopeMigration()->up();

    $rows = identitiesAfter();
    expect((int) $rows[$lPhone]->business_id)->toBe($this->laladia->id)
        ->and((int) $rows[$bPhone]->business_id)->toBe($this->bionic->id)
        ->and((int) $rows[$lUser]->business_id)->toBe($this->laladia->id)
        ->and((int) $rows[$bOrder]->business_id)->toBe($this->bionic->id)
        ->and((int) $rows[$lEmail]->business_id)->toBe($this->laladia->id)
        ->and(collect($rows)->whereNotNull('quarantined_at')->count())->toBe(0)
        ->and(DB::table('customer_identity_conflicts')->count())->toBe(0);

    // The schema the ruling orders, read from the catalogue.
    $index = DB::selectOne("SELECT indexdef FROM pg_indexes WHERE indexname = 'customer_identities_business_key'")->indexdef;
    expect($index)->toContain('UNIQUE')->toContain('(business_id, channel, external_id, source_store_id) NULLS NOT DISTINCT')
        ->toContain('WHERE (quarantined_at IS NULL)');
    $constraints = collect(DB::select("SELECT conname, pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conrelid = 'customer_identities'::regclass"))->pluck('def', 'conname');
    expect($constraints['customer_identities_customer_business_foreign'])->toContain('FOREIGN KEY (customer_id, business_id) REFERENCES customers(id, business_id)')
        ->and($constraints['customer_identities_store_business_foreign'])->toContain('FOREIGN KEY (live_store_id, business_id) REFERENCES stores(id, business_id)')
        ->and($constraints->has('customer_identities_channel_unique'))->toBeFalse()
        ->and(DB::selectOne("SELECT is_nullable FROM information_schema.columns WHERE table_name = 'customer_identities' AND column_name = 'business_id'")->is_nullable)->toBe('NO');

    // And the resolver reads it per business.
    expect(app(IdentityResolver::class)->resolve($this->bnc, ['phone' => '01711000000'])->id)->toBe($b)
        ->and(app(IdentityResolver::class)->resolve($this->lld, ['phone' => '01711000000'])->id)->toBe($l);
});

it('keeps one copy of a duplicate live and holds a value two customers of one business share', function () {
    $l = oldCustomer($this->laladia->id, 'L');
    $m = oldCustomer($this->laladia->id, 'M');
    $first = oldIdentity($l, 'phone', '01722000000');
    $copy = oldIdentity($l, 'phone', '01722000000');
    $sharedL = oldIdentity($l, 'email', 'shared@example.test');
    $sharedM = oldIdentity($m, 'email', 'shared@example.test');

    scopeMigration()->up();

    $rows = identitiesAfter();
    expect($rows[$first]->quarantined_at)->toBeNull()
        ->and($rows[$copy]->quarantine_reason)->toBe('duplicate_same_customer')
        ->and($rows[$sharedL]->quarantine_reason)->toBe('duplicate_ambiguous_owner')
        ->and($rows[$sharedM]->quarantine_reason)->toBe('duplicate_ambiguous_owner');

    $conflicts = DB::table('customer_identity_conflicts')->orderBy('identity_id')->get();
    expect($conflicts->pluck('identity_id')->map(fn ($v) => (int) $v)->all())->toBe([$copy, $sharedL, $sharedM])
        ->and($conflicts->pluck('kind')->unique()->all())->toBe(['quarantined'])
        ->and($conflicts->pluck('source')->unique()->all())->toBe(['migration.2026_10_15_100000'])
        ->and($conflicts->whereNull('resolved_at')->count())->toBe(3);

    // The live copy still resolves; the shared value is refused, never guessed.
    expect(app(IdentityResolver::class)->resolve($this->lld, ['phone' => '01722000000'])->id)->toBe($l)
        ->and(fn () => app(IdentityResolver::class)->resolve($this->lld, ['email' => 'shared@example.test']))->toThrow(IdentityQuarantined::class);
});

it('quarantines the corrupted rows the leak left, and keeps those its own evidence supports', function () {
    $l = oldCustomer($this->laladia->id, 'L');
    $b = oldCustomer($this->bionic->id, 'B');
    // A Bionic store's shop user under a LaLaDia customer (the steal across businesses).
    $stolenUser = oldIdentity($l, 'shop_user', '99', $this->bnc->id);
    // A phone under the LaLaDia customer that only Bionic orders ever used.
    $foreignPhone = oldIdentity($l, 'phone', '01733000000');
    oldOrder($this->bionic->id, $this->bnc->id, $l, 'BNC-LEAK', '01733000000');
    // A PSID under the LaLaDia customer that only a Bionic thread's participant used.
    $foreignPsid = oldIdentity($l, 'messenger_psid', 'psid-bnc-only');
    $thread = (int) DB::table('conversations')->insertGetId(['uuid' => (string) Str::uuid(), 'business_id' => $this->bionic->id, 'customer_id' => $l,
        'channel' => 'messenger', 'status' => 'open', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('conversation_participants')->insert(['conversation_id' => $thread, 'participant_type' => 'customer', 'customer_id' => $l,
        'identity_id' => $foreignPsid, 'role' => 'participant', 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    // A phone used by both businesses' orders: LaLaDia's own evidence supports the owner, so it stays live.
    $bothPhone = oldIdentity($l, 'phone', '01744000000');
    oldOrder($this->laladia->id, $this->lld->id, $l, 'LLD-BOTH', '01744000000');
    oldOrder($this->bionic->id, $this->bnc->id, $l, 'BNC-BOTH', '01744000000');
    // An identity nobody's evidence contradicts stays with its owner.
    $quiet = oldIdentity($b, 'email', 'quiet@example.test');

    scopeMigration()->up();

    $rows = identitiesAfter();
    expect($rows[$stolenUser]->quarantine_reason)->toBe('store_business_mismatch')
        ->and((int) $rows[$stolenUser]->business_id)->toBe($this->laladia->id)
        ->and($rows[$stolenUser]->live_store_id)->toBeNull()
        ->and($rows[$foreignPhone]->quarantine_reason)->toBe('contradicted_by_evidence')
        ->and($rows[$foreignPsid]->quarantine_reason)->toBe('contradicted_by_evidence')
        ->and($rows[$bothPhone]->quarantined_at)->toBeNull()
        ->and((int) $rows[$bothPhone]->business_id)->toBe($this->laladia->id)
        ->and($rows[$quiet]->quarantined_at)->toBeNull()
        ->and((int) $rows[$quiet]->business_id)->toBe($this->bionic->id);

    // Nothing was moved: every held row keeps its customer and its references.
    expect((int) $rows[$foreignPsid]->customer_id)->toBe($l)
        ->and((int) DB::table('conversation_participants')->where('conversation_id', $thread)->value('identity_id'))->toBe($foreignPsid)
        ->and(DB::table('facts_orders')->where('order_ref', 'BNC-LEAK')->value('customer_id'))->toBe($l)
        ->and(DB::table('customer_identity_conflicts')->pluck('reason')->sort()->values()->all())
        ->toBe(['contradicted_by_evidence', 'contradicted_by_evidence', 'store_business_mismatch']);

    // Bionic now gets its own customer for the stolen shop user and the foreign phone; LaLaDia's rows stay held.
    $bncUser = app(IdentityResolver::class)->resolve($this->bnc, ['shop_user_id' => 99, 'phone' => '01733000000']);
    expect($bncUser->business_id)->toBe($this->bionic->id)->and($bncUser->id)->not->toBe($l)
        ->and(fn () => app(IdentityResolver::class)->resolve($this->lld, ['phone' => '01733000000']))->toThrow(IdentityQuarantined::class);
});

it('rolls back for real when nothing would be lost, and re-applies to the same result', function () {
    $l = oldCustomer($this->laladia->id, 'L');
    $phone = oldIdentity($l, 'phone', '01711000000');
    scopeMigration()->up();
    scopeMigration()->down();

    $columns = collect(DB::select("SELECT column_name FROM information_schema.columns WHERE table_name = 'customer_identities'"))->pluck('column_name');
    expect($columns)->not->toContain('business_id')->not->toContain('quarantined_at')->not->toContain('live_store_id')
        ->and(DB::selectOne("SELECT count(*) AS n FROM pg_constraint WHERE conname = 'customer_identities_channel_unique'")->n)->toBe(1)
        ->and(DB::selectOne("SELECT to_regclass('customer_identity_conflicts') AS t")->t)->toBeNull()
        ->and(DB::table('customer_identities')->where('id', $phone)->value('customer_id'))->toBe($l);

    scopeMigration()->up();
    expect((int) DB::table('customer_identities')->where('id', $phone)->value('business_id'))->toBe($this->laladia->id);
});

it('refuses to roll back once a conflict is recorded, rather than drop audit evidence', function () {
    $l = oldCustomer($this->laladia->id, 'L');
    oldIdentity($l, 'shop_user', '1', $this->bnc->id);
    scopeMigration()->up();

    expect(fn () => scopeMigration()->down())->toThrow(RuntimeException::class, 'Restore the pre-migration backup instead');
    expect(DB::table('customer_identity_conflicts')->count())->toBe(1);
});
