<?php

use App\Commerce\Exceptions\Unsupported;
use App\Commerce\Support\ConnectorCallLogger;
use App\Erp\Dto\ErpOverview;
use App\Erp\ErpConnector;
use App\Models\Business;
use App\Models\FactsErpSnapshot;
use App\Models\IntegrationCredential;
use App\Scheduling\Tools\ToolContext;
use App\Scheduling\Tools\ToolRegistry;
use Database\Seeders\DomainSeeder;
use Illuminate\Support\Facades\Http;

/*
 | These fixtures are the REAL responses captured from the production ERP on 7 September 2026 with
 | `hq:erp:inspect`, key for key, including the offset on `as_of` and the sections the token cannot
 | see. Nothing here was written from a DTO or from the endpoint's source.
 |
 | That distinction is the whole point. The shop defect survived two days because its fixture was
 | written from the same design document as the mapper, so the two agreed with each other and
 | neither agreed with production. A fixture is only evidence if it came from the other system.
 */

function erpFixture(string $name): array
{
    return json_decode(file_get_contents(base_path("tests/fixtures/erp/{$name}.json")), true);
}

function erpAt(string $base = 'https://erp.test'): ErpConnector
{
    return new ErpConnector('bor-de-guna', 4, $base, 'tok', app(ConnectorCallLogger::class));
}

function fakeErp(array $overrides = []): void
{
    Http::fake($overrides + [
        'erp.test/api/hq/v1/4/overview' => Http::response(erpFixture('overview')),
        'erp.test/api/hq/v1/4/stock/low' => Http::response(erpFixture('low-stock')),
        'erp.test/api/hq/v1/businesses' => Http::response(erpFixture('businesses')),
        'erp.test/api/hq/v1/4/reports' => Http::response(erpFixture('reports')),
    ]);
}

it('the captured overview is the shape HQ claims to read, section for section', function () {
    $payload = erpFixture('overview');

    // Every section HQ knows about is a key the ERP actually sends.
    foreach (ErpOverview::SECTIONS as $section) {
        expect($payload['data'])->toHaveKey($section);
    }
    // And HQ knows about every section the ERP sends: a new one must not slip past unnoticed.
    expect(array_keys($payload['data']))->toEqualCanonicalizing(ErpOverview::SECTIONS);
});

it('purchasing is PRESENT and null: a permission boundary, not a missing field', function () {
    $payload = erpFixture('overview');

    // The two facts that separate "the token may not ask" from "the ERP stopped answering".
    expect($payload['data'])->toHaveKey('purchasing')
        ->and($payload['data']['purchasing'])->toBeNull();

    // The ERP sends purchasing's contents only when the token holds purchasing.reports.view, which
    // HQ was deliberately not granted; it sends the key either way.
    fakeErp();
    expect(erpAt()->overview()->purchasing)->toBeNull();
});

it('stock_value is a zero the ERP itself sent, not one HQ invented', function () {
    $payload = erpFixture('overview');

    // Present, integer, zero. Had HQ invented it, the key would be absent from the payload.
    expect($payload['data']['inventory'])->toHaveKey('stock_value')
        ->and($payload['data']['inventory']['stock_value'])->toBe(0)
        ->and($payload['data']['inventory']['active_products'])->toBe(118);

    fakeErp();
    expect(erpAt()->overview()->inventory['stock_value'])->toBe(0);
});

it('an empty low-stock list is only accepted when the ERP says it meant to send none', function () {
    fakeErp();
    $low = erpAt()->lowStock();
    expect($low->lines)->toBe([])->and($low->count())->toBe(0)->and($low->asOf)->toBe('2026-09-07T11:41:21+06:00');

    // The same empty list, with the ERP claiming rows, is data loss rather than good news.
    fakeErp(['erp.test/api/hq/v1/4/stock/low' => Http::response(['data' => [], 'meta' => ['count' => 3]])]);
    expect(fn () => erpAt()->lowStock())->toThrow(Unsupported::class, 'meta.count says 3 row(s)');
});

it('refuses a response with no data key instead of persisting a confident blank', function () {
    fakeErp(['erp.test/api/hq/v1/4/overview' => Http::response(['meta' => ['as_of' => 'now']])]);
    expect(fn () => erpAt()->overview())->toThrow(Unsupported::class, 'has no `data` key');

    fakeErp(['erp.test/api/hq/v1/4/stock/low' => Http::response(['meta' => []])]);
    expect(fn () => erpAt()->lowStock())->toThrow(Unsupported::class, 'has no `data` key');
});

it('refuses an overview whose section has silently stopped being sent', function () {
    $payload = erpFixture('overview');
    unset($payload['data']['inventory']);
    fakeErp(['erp.test/api/hq/v1/4/overview' => Http::response($payload)]);

    // Without this, a vanished section would be stored as null and read as "no permission".
    expect(fn () => erpAt()->overview())->toThrow(Unsupported::class, 'missing section(s) [inventory]');
});

it('stores as_of as the instant the ERP meant, not its wall-clock reading', function () {
    $this->seed(DomainSeeder::class);
    $business = Business::where('slug', 'bor-de-guna')->sole();
    IntegrationCredential::create(['provider' => 'erp_api_token', 'account_ref' => 'bor-de-guna', 'business_id' => $business->id,
        'credential_type' => 'token', 'payload' => ['value' => 'tok'], 'status' => 'active']);
    config(['hq.erp.base_url' => 'https://erp.test']);
    fakeErp();

    $context = new ToolContext(idempotencyKey: 'i', correlationId: 'c', businessId: $business->id);
    app(ToolRegistry::class)->get('erp.poll_overview')->execute([], $context);
    app(ToolRegistry::class)->get('erp.poll_low_stock')->execute([], $context);

    // 11:41:09+06:00 is 05:41:09 UTC. Storing "11:41:09" would put the snapshot in its own future.
    $overview = FactsErpSnapshot::where('kind', 'overview')->sole();
    expect($overview->as_of->utc()->toDateTimeString())->toBe('2026-09-07 05:41:09')
        ->and($overview->payload['inventory']['stock_value'])->toBe(0)
        ->and($overview->payload['purchasing'])->toBeNull();

    // Low stock now carries the ERP's timestamp too, so the column means one thing on both rows.
    expect(FactsErpSnapshot::where('kind', 'low_stock')->sole()->as_of->utc()->toDateTimeString())->toBe('2026-09-07 05:41:21');
});

it('leaves as_of unknown rather than wrong when the ERP sends something unparseable', function () {
    $this->seed(DomainSeeder::class);
    $business = Business::where('slug', 'bor-de-guna')->sole();
    IntegrationCredential::create(['provider' => 'erp_api_token', 'account_ref' => 'bor-de-guna', 'business_id' => $business->id,
        'credential_type' => 'token', 'payload' => ['value' => 'tok'], 'status' => 'active']);
    config(['hq.erp.base_url' => 'https://erp.test']);
    $payload = erpFixture('overview');
    $payload['meta']['as_of'] = 'not a timestamp';
    fakeErp(['erp.test/api/hq/v1/4/overview' => Http::response($payload)]);

    app(ToolRegistry::class)->get('erp.poll_overview')->execute([], new ToolContext(idempotencyKey: 'i', correlationId: 'c', businessId: $business->id));

    expect(FactsErpSnapshot::where('kind', 'overview')->sole()->as_of)->toBeNull();
});

it('the businesses and reports payloads match what the connector reads out of them', function () {
    fakeErp();
    $businesses = erpAt()->businesses();
    expect($businesses)->toHaveCount(1)->and($businesses[0]['id'])->toBe(4)->and($businesses[0]['is_active'])->toBeTrue();
    expect(erpAt()->probe()['ok'])->toBeTrue();

    // Only one report is reachable with the abilities this token was given, and its parameter
    // carries `help` and `default` as nulls rather than omitting them.
    $reports = erpAt()->reports();
    expect($reports)->toHaveCount(1)->and($reports[0]['key'])->toBe('sales_by_brand')
        ->and($reports[0]['parameters'][0])->toHaveKeys(['key', 'label', 'type', 'required', 'options', 'help', 'default'])
        ->and($reports[0]['parameters'][0]['help'])->toBeNull();
});
