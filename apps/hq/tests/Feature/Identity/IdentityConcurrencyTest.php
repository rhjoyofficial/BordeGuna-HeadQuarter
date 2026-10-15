<?php

use App\Gateway\Descriptor\Channel;
use App\Identity\IdentityResolver;
use App\Models\Business;
use App\Models\Store;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/*
 | docs/122 (ruling docs/120 A2): duplicate store-less identities under CONCURRENT creation, on real PostgreSQL.
 |
 | Two extra connections to the test database stand for two workers. The rows both must see (a business, a store,
 | the winner's customer) are COMMITTED through `race_b`, outside the test's own transaction, and removed again at
 | the end; the resolver under test runs on `race_a` inside a transaction that is rolled back, so nothing it writes
 | (audit rows included) outlives the test.
 */

beforeEach(function () {
    foreach (['race_a', 'race_b'] as $name) {
        config(["database.connections.{$name}" => config('database.connections.pgsql')]);
        DB::purge($name);
    }
    $this->tag = 'race-'.Str::lower(Str::random(8));
    $b = DB::connection('race_b');
    $this->businessA = (int) $b->table('businesses')->insertGetId(['name' => 'Race A', 'slug' => $this->tag.'-a', 'created_at' => now(), 'updated_at' => now()]);
    $this->businessB = (int) $b->table('businesses')->insertGetId(['name' => 'Race B', 'slug' => $this->tag.'-b', 'created_at' => now(), 'updated_at' => now()]);
    $this->store = (int) $b->table('stores')->insertGetId(['business_id' => $this->businessA, 'name' => 'Race store', 'slug' => $this->tag.'-store',
        'adapter' => 'laladia', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
});

afterEach(function () {
    DB::setDefaultConnection('pgsql');
    $a = DB::connection('race_a');
    while ($a->transactionLevel() > 0) {
        $a->rollBack();
    }
    $b = DB::connection('race_b');
    $businesses = [$this->businessA, $this->businessB];
    $customers = $b->table('customers')->whereIn('business_id', $businesses)->pluck('id')->all();
    $b->table('customer_identity_conflicts')->whereIn('business_id', $businesses)->delete();
    $b->table('customer_identities')->whereIn('business_id', $businesses)->delete();
    $b->table('customers')->whereIn('id', $customers === [] ? [0] : $customers)->delete();
    $b->table('stores')->where('id', $this->store)->delete();
    $b->table('businesses')->whereIn('id', $businesses)->delete();
    DB::purge('race_a');
    DB::purge('race_b');
});

/** A customer committed by the "other worker", optionally holding the identity already. */
function raceWinner(int $businessId, ?array $identity = null): int
{
    $b = DB::connection('race_b');
    $id = (int) $b->table('customers')->insertGetId(['business_id' => $businessId, 'name' => 'Winner', 'created_at' => now(), 'updated_at' => now()]);
    if ($identity !== null) {
        $b->table('customer_identities')->insert(['business_id' => $businessId, 'customer_id' => $id, 'channel' => $identity[0], 'external_id' => $identity[1],
            'source_store_id' => null, 'confidence' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    return $id;
}

it('lets PostgreSQL itself refuse a second store-less row for one value in one business while the first is in flight', function () {
    $a = DB::connection('race_a');
    $b = DB::connection('race_b');
    $holder = raceWinner($this->businessA);
    $other = raceWinner($this->businessA);
    $row = fn (int $business, int $customer) => ['business_id' => $business, 'customer_id' => $customer, 'channel' => 'phone', 'external_id' => '01799000000',
        'source_store_id' => null, 'confidence' => 1, 'created_at' => now(), 'updated_at' => now()];

    $a->beginTransaction();
    $a->table('customer_identities')->insert($row($this->businessA, $holder));

    // The second worker waits on the first worker's uncommitted key (NULLS NOT DISTINCT: the null store is equal).
    $b->statement("SET lock_timeout = '300ms'");
    expect(fn () => $b->table('customer_identities')->insert($row($this->businessA, $other)))
        ->toThrow(fn (QueryException $e) => expect($e->getCode())->toBe('55P03'));

    $a->commit();
    // Once the first commits, the second is a plain unique violation; nothing was written twice.
    expect(fn () => $b->table('customer_identities')->insert($row($this->businessA, $other)))
        ->toThrow(fn (QueryException $e) => expect($e->getCode())->toBe('23505'));
    // The same value in ANOTHER business is its own row.
    $otherBusinessCustomer = raceWinner($this->businessB);
    $b->table('customer_identities')->insert($row($this->businessB, $otherBusinessCustomer));
    $b->statement('SET lock_timeout = 0');

    expect($b->table('customer_identities')->where('external_id', '01799000000')->where('business_id', $this->businessA)->count())->toBe(1)
        ->and($b->table('customer_identities')->where('external_id', '01799000000')->count())->toBe(2);
});

/**
 * Runs `$work` on `race_a` inside a transaction that is rolled back afterwards; the first time `race_a` reads
 * customer_identities for `$value`, `$interleave` runs (the other worker committing in the window between the
 * resolver's lookup and its insert).
 */
function raceWindow(string $value, Closure $interleave, Closure $work): mixed
{
    $fired = false;
    Event::listen(QueryExecuted::class, function (QueryExecuted $q) use (&$fired, $value, $interleave) {
        if (! $fired && $q->connectionName === 'race_a' && str_contains($q->sql, 'customer_identities') && in_array($value, $q->bindings, true)) {
            $fired = true;
            $interleave();
        }
    });

    DB::connection('race_a')->beginTransaction();
    DB::setDefaultConnection('race_a');
    try {
        return $work();
    } finally {
        DB::setDefaultConnection('pgsql');
    }
}

it('makes the losing first contact take the winner\'s customer, so one phone stays one customer and one row', function () {
    $winner = null;
    $result = raceWindow('01788800000', function () use (&$winner) {
        $winner = raceWinner($this->businessA, ['phone', '01788800000']);
    }, function () {
        $store = Store::on('race_a')->findOrFail($this->store);

        return app(IdentityResolver::class)->resolve($store, ['phone' => '01788800000', 'name' => 'Loser']);
    });

    $a = DB::connection('race_a');
    expect($winner)->not->toBeNull()
        ->and($result->id)->toBe($winner)
        ->and($a->table('customer_identities')->where('business_id', $this->businessA)->where('channel', 'phone')->where('external_id', '01788800000')->count())->toBe(1)
        // The loser's own customer was given up, not left behind as a duplicate.
        ->and($a->table('customers')->where('business_id', $this->businessA)->count())->toBe(1)
        ->and($a->table('customer_identity_conflicts')->where('business_id', $this->businessA)->count())->toBe(0);
});

it('makes a losing stranger on a channel take the winner\'s customer in the same business', function () {
    $winner = null;
    $result = raceWindow('psid-race', function () use (&$winner) {
        $winner = raceWinner($this->businessA, ['messenger_psid', 'psid-race']);
    }, function () {
        return app(IdentityResolver::class)->resolveChannelIdentity(Channel::Messenger, 'psid-race', Business::on('race_a')->findOrFail($this->businessA));
    });

    $a = DB::connection('race_a');
    expect($result->id)->toBe($winner)
        ->and($a->table('customer_identities')->where('business_id', $this->businessA)->where('external_id', 'psid-race')->count())->toBe(1)
        ->and($a->table('customers')->where('business_id', $this->businessA)->count())->toBe(1);
});
