<?php

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\DomainEvent;
use App\Models\FactsOrder;
use App\Models\IntegrationCredential;
use App\Models\User;
use App\Support\Events\EventRecorder;
use Carbon\CarbonImmutable;
use Database\Seeders\DomainSeeder;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;

/*
 | docs/132: event preservation across containment Option 2 (scheduler stopped), a release and a
 | restore. Ruling docs/131 section 1.1: "Verify that stopping event processing cannot discard
 | payloads or lose source acknowledgements." Each finding (a)-(d) is pinned here where code can pin it.
 */

beforeEach(function () {
    $this->seed(DomainSeeder::class);
    IntegrationCredential::create(['provider' => 'shop_webhook_secret', 'account_ref' => 'laladia-com', 'credential_type' => 'token', 'payload' => ['value' => 'whsec'], 'status' => 'active']);
    $this->bridgeDir = storage_path('framework/testing/event-bridge-'.Str::random(8));
});

afterEach(function () {
    if (is_dir($this->bridgeDir)) {
        array_map('unlink', glob($this->bridgeDir.'/*') ?: []);
        @rmdir($this->bridgeDir);
    }
});

function epvOrder(string $ref = 'LLD-EPV-1'): array
{
    return [
        'order_number' => $ref, 'order_status' => 'pending', 'payment_status' => 'unpaid', 'payment_method' => 'cod',
        'subtotal' => 999.0, 'discount_total' => 0.0, 'shipping_cost' => 90.0, 'grand_total' => 1089.0,
        'customer_name' => 'Karim Ahmed', 'customer_phone' => '01711000000',
        'items' => [['sku' => 'PIC-HIL-V1', 'name' => 'Hilsa Pickle', 'quantity' => 1, 'price' => 999.0]],
    ];
}

function epvDeliver($test, string $delivery, string $ref = 'LLD-EPV-1')
{
    return signedPost($test, ['id' => $delivery, 'event' => 'order.created', 'occurred_at' => now()->toIso8601String(), 'schema_version' => 1, 'data' => epvOrder($ref)], headers: ['HTTP_X_SHOP_DELIVERY' => $delivery]);
}

/** One md5 over every events row's full text, in id order: equal means not one byte changed. */
function epvEventsHash(): string
{
    return (string) DB::selectOne("SELECT md5(coalesce(string_agg(e::text, '|' ORDER BY e.id), '')) AS h FROM events e")->h;
}

function epvUser(): User
{
    return User::factory()->create(['email' => 'operator-'.Str::random(6).'@example.test']);
}

// ── (a) durable before the 202, with nothing draining ─────────────────────────────────────────────

it('(a) records an accepted webhook as one pending row before answering 202, opening no transaction and queueing nothing', function () {
    Queue::fake();
    $begun = 0;
    Event::listen(TransactionBeginning::class, function () use (&$begun) {
        $begun++;
    });

    $response = epvDeliver($this, 'epv-a-1');

    $response->assertStatus(202)->assertJsonPath('duplicate', false);
    $event = DomainEvent::sole();
    expect($response->json('event_id'))->toBe($event->event_id)
        ->and($event->status)->toBe('pending')
        ->and($event->attempts)->toBe(0)
        ->and($event->processed_at)->toBeNull()
        ->and($event->direction)->toBe('inbound')
        ->and($event->payload['order_number'])->toBe('LLD-EPV-1')
        ->and($event->payload['items'][0]['sku'])->toBe('PIC-HIL-V1');
    // A single INSERT outside any transaction is committed by Postgres when it returns, which is
    // before the controller builds the 202. Nothing went to Redis or a queue in its place.
    expect($begun)->toBe(0);
    Queue::assertNothingPushed();
    expect(FactsOrder::count())->toBe(0)->and(Customer::count())->toBe(0);
});

it('(a) keeps accepting and storing deliveries while the drain is paused, and none is processed', function () {
    config(['hq.events.drain_paused' => true]);

    foreach (['epv-p-1', 'epv-p-2', 'epv-p-3'] as $i => $id) {
        epvDeliver($this, $id, "LLD-EPV-P{$i}")->assertStatus(202);
        $this->artisan('hq:events:process')->expectsOutputToContain('drain paused')->assertSuccessful();
    }

    expect(DomainEvent::count())->toBe(3)
        ->and(DomainEvent::where('status', 'pending')->where('attempts', 0)->count())->toBe(3)
        ->and(FactsOrder::count())->toBe(0);
});

// ── (b) nothing else processes or expires events ──────────────────────────────────────────────────

it('(b) leaves pending events byte-identical through every other scheduled command', function () {
    epvDeliver($this, 'epv-b-1')->assertStatus(202);
    // Old enough that retention would compact it if it were processed.
    DB::table('events')->update(['created_at' => now()->subYears(3)]);
    $before = epvEventsHash();

    $this->artisan('hq:scheduler:tick');
    $this->artisan('hq:approvals:sweep');
    $this->artisan('hq:alerts:check');
    $this->artisan('hq:retention:run');
    $this->artisan('model:prune');
    $this->artisan('queue:prune-failed --hours=168');

    expect(epvEventsHash())->toBe($before)->and(DomainEvent::sole()->status)->toBe('pending');
});

it('(b) only the drain, replay, retention (processed rows only), the recorder and the import write the events table', function () {
    $allowed = [
        'Console/Commands/EventsImport.php',
        'Console/Commands/EventsReplay.php',
        'Observe/Retention/RetentionRunner.php',
        'Support/Events/EventRecorder.php',
        'Support/Events/ProcessDomainEvents.php',
    ];
    $writes = '/(table\(\s*[\'"]events[\'"]\s*\)|DomainEvent::)[^;]*->(update|delete|insert|insertOrIgnore|upsert|truncate|forceDelete|increment|decrement)\(|DomainEvent::(create|forceCreate|insert|truncate|updateOrCreate|firstOrCreate)\(|(UPDATE|DELETE\s+FROM|INSERT\s+INTO|TRUNCATE)\s+events\b/s';
    $found = [];
    foreach ((new Finder)->files()->in(app_path())->name('*.php') as $file) {
        $src = $file->getContents();
        $hit = preg_match($writes, $src) === 1
            || (str_contains($src, 'use App\Models\DomainEvent;') && preg_match('/\$event->(forceFill|update|delete|save)\(/', $src) === 1);
        if ($hit) {
            $found[] = str_replace('\\', '/', $file->getRelativePathname());
        }
    }
    sort($found);

    expect($found)->toBe($allowed);
});

// ── (d) idempotent on redelivery and on replay ────────────────────────────────────────────────────

it('(d) a redelivered webhook is one row, and draining then replaying it writes one fact and one customer', function () {
    epvDeliver($this, 'epv-d-1')->assertStatus(202)->assertJsonPath('duplicate', false);
    epvDeliver($this, 'epv-d-1')->assertStatus(202)->assertJsonPath('duplicate', true);
    expect(DomainEvent::count())->toBe(1);

    $this->artisan('hq:events:process')->assertSuccessful();
    $this->artisan('hq:events:process')->assertSuccessful();
    $event = DomainEvent::sole();
    $this->artisan('hq:events:replay', ['--event' => [$event->event_id], '--apply' => true, '--reason' => 'test'])->assertSuccessful();
    $this->artisan('hq:events:replay', ['--event' => [$event->event_id], '--apply' => true, '--reason' => 'test'])->assertSuccessful();

    expect(FactsOrder::count())->toBe(1)->and(Customer::count())->toBe(1)->and($event->fresh()->status)->toBe('processed');
});

// ── the drain pause flag ──────────────────────────────────────────────────────────────────────────

it('the drain pause flag blocks processing and leaves rows pending; clearing it drains them', function () {
    epvDeliver($this, 'epv-f-1')->assertStatus(202);
    $before = epvEventsHash();

    config(['hq.events.drain_paused' => true]);
    $this->artisan('hq:events:process')->assertSuccessful();
    expect(epvEventsHash())->toBe($before)->and(FactsOrder::count())->toBe(0);

    config(['hq.events.drain_paused' => false]);
    $this->artisan('hq:events:process')->assertSuccessful();
    expect(DomainEvent::sole()->status)->toBe('processed')->and(FactsOrder::count())->toBe(1);
});

it('the drain pause flag is off unless HQ_EVENTS_DRAIN_PAUSED is set', function () {
    expect(config('hq.events.drain_paused'))->toBeFalse();
});

// ── the bridge: export ────────────────────────────────────────────────────────────────────────────

it('exports inbound events after a row id, read only, checksummed, with no personal value on stdout', function () {
    epvDeliver($this, 'epv-x-1', 'LLD-EPV-X1')->assertStatus(202);
    $cut = (int) DB::table('events')->max('id');
    epvDeliver($this, 'epv-x-2', 'LLD-EPV-X2')->assertStatus(202);
    epvDeliver($this, 'epv-x-3', 'LLD-EPV-X3')->assertStatus(202);
    app(EventRecorder::class)->record('conversation.state_changed', ['x' => 1], 'hq', 'outbound');
    $hash = epvEventsHash();
    $audits = AuditLog::count();
    $out = $this->bridgeDir.'/after.jsonl';

    $this->artisan('hq:events:export-since', ['--since-id' => $cut, '--out' => $out])
        ->expectsOutputToContain('exported 2 inbound event(s)')
        ->doesntExpectOutputToContain('01711000000')
        ->doesntExpectOutputToContain('Karim')
        ->assertSuccessful();

    expect(epvEventsHash())->toBe($hash)->and(AuditLog::count())->toBe($audits);
    $lines = file($out, FILE_IGNORE_NEW_LINES);
    expect($lines)->toHaveCount(4);
    expect(json_decode($lines[0], true))->toMatchArray(['format' => 'hq-events-bridge', 'version' => 1, 'since_id' => $cut]);
    expect(json_decode($lines[1], true)['payload']['order_number'])->toBe('LLD-EPV-X2');
    $trailer = json_decode($lines[3], true);
    expect($trailer['rows'])->toBe(2)->and($trailer['sha256'])->toBe(hash('sha256', $lines[0]."\n".$lines[1]."\n".$lines[2]."\n"));

    // Never overwrites, and needs exactly one starting point.
    $this->artisan('hq:events:export-since', ['--since-id' => $cut, '--out' => $out])->assertFailed();
    $this->artisan('hq:events:export-since', ['--out' => $this->bridgeDir.'/x.jsonl'])->assertFailed();
    $this->artisan('hq:events:export-since', ['--since-id' => 1, '--since' => '2026-10-16T00:00:00Z', '--out' => $this->bridgeDir.'/y.jsonl'])->assertFailed();
});

it('exports by arrival time with --since', function () {
    epvDeliver($this, 'epv-s-1', 'LLD-EPV-S1')->assertStatus(202);
    DB::table('events')->update(['created_at' => now()->subDay()]);
    epvDeliver($this, 'epv-s-2', 'LLD-EPV-S2')->assertStatus(202);
    $out = $this->bridgeDir.'/since.jsonl';

    $this->artisan('hq:events:export-since', ['--since' => now()->subHour()->toIso8601String(), '--out' => $out])
        ->expectsOutputToContain('exported 1 inbound event(s)')->assertSuccessful();
});

// ── the bridge: import after a restore ────────────────────────────────────────────────────────────

/**
 * Two deliveries arrive after the "dump"; one of them was already processed when exported. The
 * restore is simulated by deleting the rows that arrived after the dump (and the fact the processed
 * one wrote), which is exactly what restoring the pre-deploy dump does to them.
 */
function epvExportThenRestore($test): array
{
    epvDeliver($test, 'epv-i-0', 'LLD-EPV-I0')->assertStatus(202);
    $cut = (int) DB::table('events')->max('id');
    epvDeliver($test, 'epv-i-1', 'LLD-EPV-I1')->assertStatus(202);
    epvDeliver($test, 'epv-i-2', 'LLD-EPV-I2')->assertStatus(202);
    $after = DB::table('events')->where('id', '>', $cut)->orderBy('id')->get(['event_id', 'dedupe_key', 'type', 'source', 'occurred_at', 'created_at'])->map(fn ($r) => (array) $r)->all();
    $payloads = DB::table('events')->where('id', '>', $cut)->orderBy('id')->selectRaw('payload::text AS p')->pluck('p')->all();
    DB::table('events')->where('id', '>', $cut)->limit(1)->update(['status' => 'processed', 'processed_at' => now(), 'attempts' => 1]);

    $out = $test->bridgeDir.'/restore.jsonl';
    $test->artisan('hq:events:export-since', ['--since-id' => $cut, '--out' => $out])->assertSuccessful();
    DB::table('events')->where('id', '>', $cut)->delete();

    return ['file' => $out, 'after' => $after, 'payloads' => $payloads];
}

it('import dry run is the default and writes nothing', function () {
    $x = epvExportThenRestore($this);
    $hash = epvEventsHash();
    $audits = AuditLog::count();

    $this->artisan('hq:events:import', ['file' => $x['file']])
        ->expectsOutputToContain('absent, to insert as pending: 2')
        ->expectsOutputToContain('dry run: nothing was written')
        ->doesntExpectOutputToContain('01711000000')
        ->assertSuccessful();
    $this->artisan('hq:events:import', ['file' => $x['file'], '--dry-run' => true])->assertSuccessful();

    expect(epvEventsHash())->toBe($hash)->and(AuditLog::count())->toBe($audits);
});

it('import apply restores every lost event verbatim as pending and unprocessed, audited, and is idempotent', function () {
    $x = epvExportThenRestore($this);
    $user = epvUser();
    $facts = FactsOrder::count();

    $this->artisan('hq:events:import', ['file' => $x['file'], '--apply' => true, '--by' => $user->email, '--reason' => 'restore bridge test'])
        ->expectsOutputToContain('inserted 2 event(s) as pending')->assertSuccessful();

    $back = DB::table('events')->whereIn('event_id', array_column($x['after'], 'event_id'))->orderBy('id')->get();
    expect($back)->toHaveCount(2);
    foreach ($back as $i => $row) {
        expect($row->event_id)->toBe($x['after'][$i]['event_id'])
            ->and($row->dedupe_key)->toBe($x['after'][$i]['dedupe_key'])
            ->and($row->status)->toBe('pending')
            ->and((int) $row->attempts)->toBe(0)
            ->and($row->processed_at)->toBeNull()
            ->and(CarbonImmutable::parse($row->created_at)->equalTo(CarbonImmutable::parse($x['after'][$i]['created_at'])))->toBeTrue();
        expect(json_decode((string) DB::table('events')->where('event_id', $row->event_id)->selectRaw('payload::text AS p')->value('p'), true))
            ->toEqual(json_decode($x['payloads'][$i], true));
    }
    // Never processed by the import: no fact was written.
    expect(FactsOrder::count())->toBe($facts);

    $audit = AuditLog::where('action', 'events.imported')->sole();
    expect($audit->actor_id)->toBe($user->id)->and($audit->reason)->toBe('restore bridge test')
        ->and($audit->context['inserted'])->toBe(2)->and($audit->context['inserted_event_ids'])->toBe(array_column($x['after'], 'event_id'));

    $hash = epvEventsHash();
    $this->artisan('hq:events:import', ['file' => $x['file'], '--apply' => true, '--by' => $user->email, '--reason' => 'again'])
        ->expectsOutputToContain('already present (same delivery), skipped: 2')
        ->expectsOutputToContain('inserted 0 event(s)')->assertSuccessful();
    expect(epvEventsHash())->toBe($hash);

    // Once the drain is allowed, the imported rows process like any other, through today's handlers.
    $this->artisan('hq:events:process')->assertSuccessful();
    expect(FactsOrder::whereIn('order_ref', ['LLD-EPV-I1', 'LLD-EPV-I2'])->count())->toBe(2);
});

it('import skips a delivery the shop re-sent after the restore (new event_id, same payload)', function () {
    $x = epvExportThenRestore($this);
    epvDeliver($this, 'epv-i-1', 'LLD-EPV-I1')->assertStatus(202)->assertJsonPath('duplicate', false);

    $this->artisan('hq:events:import', ['file' => $x['file'], '--apply' => true, '--by' => epvUser()->email, '--reason' => 'r'])
        ->expectsOutputToContain('already present (same delivery), skipped: 1')
        ->expectsOutputToContain('inserted 1 event(s)')->assertSuccessful();
    expect(DomainEvent::where('dedupe_key', 'shop:laladia-com:epv-i-1')->count())->toBe(1);
});

it('import refuses the whole file when a row conflicts with a different payload, and writes nothing', function () {
    $x = epvExportThenRestore($this);
    signedPost($this, ['id' => 'epv-i-2', 'event' => 'order.created', 'data' => epvOrder('LLD-SOMETHING-ELSE')], headers: ['HTTP_X_SHOP_DELIVERY' => 'epv-i-2'])->assertStatus(202);
    $hash = epvEventsHash();
    $audits = AuditLog::count();

    $this->artisan('hq:events:import', ['file' => $x['file']])->expectsOutputToContain('conflicting: 1')->assertFailed();
    $this->artisan('hq:events:import', ['file' => $x['file'], '--apply' => true, '--by' => epvUser()->email, '--reason' => 'r'])
        ->expectsOutputToContain('refused, nothing written')->assertFailed();

    expect(epvEventsHash())->toBe($hash)->and(AuditLog::count())->toBe($audits);
});

it('import refuses a file whose checksum fails, and an apply without --by, --reason or a known user', function () {
    $x = epvExportThenRestore($this);
    $hash = epvEventsHash();

    $this->artisan('hq:events:import', ['file' => $x['file'], '--apply' => true])->assertExitCode(2);
    $this->artisan('hq:events:import', ['file' => $x['file'], '--apply' => true, '--by' => 'nobody@example.test', '--reason' => 'r'])->assertExitCode(2);

    $tampered = $this->bridgeDir.'/tampered.jsonl';
    file_put_contents($tampered, str_replace('LLD-EPV-I1', 'LLD-EPV-ZZ', (string) file_get_contents($x['file'])));
    $this->artisan('hq:events:import', ['file' => $tampered, '--apply' => true, '--by' => epvUser()->email, '--reason' => 'r'])
        ->expectsOutputToContain('checksum mismatch')->assertFailed();

    $cut = $this->bridgeDir.'/cut.jsonl';
    $lines = file($x['file'], FILE_IGNORE_NEW_LINES);
    file_put_contents($cut, implode("\n", array_slice($lines, 0, -1))."\n");
    $this->artisan('hq:events:import', ['file' => $cut])->assertFailed();

    expect(epvEventsHash())->toBe($hash);
});
