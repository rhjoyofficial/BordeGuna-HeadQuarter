<?php

use App\Facts\FactsIntegrityChecker;
use App\Models\AuditLog;
use App\Models\FactsOrder;
use App\Models\Store;
use App\Tools\ToolContext;
use App\Tools\ToolRegistry;
use Database\Seeders\DomainSeeder;

/*
 | The arithmetic guard. Every case here is shaped after something that actually happened or could
 | plausibly happen to a real order fact, not after the four orders that were repaired.
 */

beforeEach(fn () => $this->seed(DomainSeeder::class));

function fact(array $overrides = []): FactsOrder
{
    $store = Store::where('slug', 'laladia-com')->sole();

    return FactsOrder::create(array_merge([
        'business_id' => $store->business_id, 'store_id' => $store->id,
        'order_ref' => 'LLD-'.fake()->unique()->numerify('########'),
        'status' => 'pending', 'subtotal' => 999, 'discount_total' => 0, 'shipping_cost' => 90, 'gross' => 1089,
        'placed_at' => now()->subHour(),
        'lines' => [['sku' => 'PIC-HIL-V1', 'name' => 'Pickle', 'qty' => 1, 'unit_price' => 999, 'total' => 999]],
    ], $overrides));
}

function checkAll(): array
{
    return app(FactsIntegrityChecker::class)->check(null, now()->subDays(7)->toImmutable());
}

it('passes an order whose lines and totals agree', function () {
    fact();
    $r = checkAll();
    expect($r['checked'])->toBe(1)->and($r['ok'])->toBe(1)->and($r['discrepancies'])->toBe(0)->and($r['without_lines'])->toBe(0);
});

it('catches the defect it was built for: a charged order whose lines claim to be free', function () {
    // Exactly what the unit_price/price mismatch produced on four live orders.
    fact(['lines' => [['sku' => 'PIC-HIL-V1', 'qty' => 1, 'unit_price' => 0, 'total' => 0]]]);

    $codes = array_column(checkAll()['findings'], 'code');
    expect($codes)->toContain(FactsIntegrityChecker::ZERO_PRICED_LINE)
        ->and($codes)->toContain(FactsIntegrityChecker::LINES_NOT_SUBTOTAL);
});

it('catches a line whose own arithmetic is wrong', function () {
    fact(['lines' => [['sku' => 'X', 'qty' => 3, 'unit_price' => 100, 'total' => 999]]]);

    expect(array_column(checkAll()['findings'], 'code'))->toContain(FactsIntegrityChecker::LINE_ARITHMETIC);
});

it('catches totals that do not add up, with or without lines', function () {
    fact(['gross' => 1000]); // 999 + 90 - 0 is 1089, not 1000
    expect(array_column(checkAll()['findings'], 'code'))->toContain(FactsIntegrityChecker::TOTALS);
});

it('counts an order with no line detail separately instead of calling it broken', function () {
    // Reconciliation inserts these: the shop's admin list endpoint carries no items.
    fact(['lines' => null]);
    $r = checkAll();
    expect($r['without_lines'])->toBe(1)->and($r['discrepancies'])->toBe(0)->and($r['ok'])->toBe(1);
});

it('accepts a genuinely free line on a genuinely free order', function () {
    // A zero price is only suspicious when the order charged for something.
    fact(['subtotal' => 0, 'shipping_cost' => 0, 'gross' => 0,
        'lines' => [['sku' => 'GIFT', 'qty' => 1, 'unit_price' => 0, 'total' => 0]]]);

    expect(checkAll()['discrepancies'])->toBe(0);
});

it('tolerates half-paisa rounding rather than reporting noise', function () {
    fact(['lines' => [['sku' => 'X', 'qty' => 3, 'unit_price' => 333, 'total' => 999.002]], 'subtotal' => 999]);
    expect(checkAll()['discrepancies'])->toBe(0);
});

it('respects its window and its store scope', function () {
    fact(['placed_at' => now()->subDays(30), 'gross' => 1]); // broken, but outside the window
    expect(checkAll()['checked'])->toBe(0);

    fact();
    $bionic = Store::where('slug', 'bionic-garden')->sole();
    expect(app(FactsIntegrityChecker::class)->check($bionic, now()->subDays(7)->toImmutable())['checked'])->toBe(0);
});

it('runs as a registered tool, records what it found, and calls a discrepancy a success', function () {
    fact(['lines' => [['sku' => 'PIC-HIL-V1', 'qty' => 1, 'unit_price' => 0, 'total' => 0]]]);
    $tool = app(ToolRegistry::class)->get('commerce.check_fact_integrity');

    $result = $tool->execute(['hours' => 168], new ToolContext(idempotencyKey: 'i', correlationId: 'c'));

    // Finding a problem is the tool working, not the tool failing.
    expect($result->ok())->toBeTrue()
        ->and($result->output['checked'])->toBe(1)
        ->and($result->output['discrepancies'])->toBeGreaterThan(0)
        ->and($result->output['findings'][0]['order_ref'])->toBe(FactsOrder::sole()->order_ref);

    $audit = AuditLog::where('action', 'facts.integrity_checked')->sole();
    expect($audit->reason)->toContain('reported, not repaired')
        ->and($audit->after['discrepancies'])->toBeGreaterThan(0);

    // Read-only: the fact is untouched by having been checked.
    expect(FactsOrder::sole()->lines[0]['unit_price'])->toBe(0);
    expect($tool->reconcile(new ToolContext(idempotencyKey: 'i', correlationId: 'c')))->toBeNull();
});

it('fails the tool on an unknown store, and the command exits non-zero on a discrepancy', function () {
    $tool = app(ToolRegistry::class)->get('commerce.check_fact_integrity');
    expect($tool->execute(['store_slug' => 'nope'], new ToolContext(idempotencyKey: 'i', correlationId: 'c'))->status)->toBe('failed');

    fact(['lines' => [['sku' => 'X', 'qty' => 1, 'unit_price' => 0, 'total' => 0]]]);
    $this->artisan('hq:facts:check --store=laladia-com')->assertFailed();
});

it('the command is quiet and successful when everything adds up', function () {
    fact();
    $this->artisan('hq:facts:check')->expectsOutputToContain('0 discrepancy(ies)')->assertSuccessful();
});
