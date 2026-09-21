<?php

use App\Commerce\Exceptions\Refused;
use App\Commerce\Exceptions\Unavailable;
use App\Commerce\Exceptions\Unsupported;
use App\Commerce\Support\ConnectorCallLogger;
use App\Erp\ErpConnector;
use App\Models\ConnectorCall;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;

/*
 | The Phase 4 read side's contract with the ERP (docs/81 C.5, docs/79).
 |
 | ⚠ The fixtures under tests/fixtures/erp/order-reads are NOT written here. The ERP's own test
 | `tests/Feature/HqApi/HqOrderReadTest.php` writes them from the real endpoints (with
 | HQ_API_WRITE_FIXTURES=1) and pins the same contract digest this file pins, so a change on either
 | side fails that side's test until the other is brought along. HQ holds a copy; this test proves
 | the copy was not edited by hand and that the connector reads every body the ERP sends.
 |
 | Origin: bordeguna-erp branch feature/hq-api-order-reads, the commit that added the order-reads.
 */

const ORDER_READS_ERP = 'https://erp.order-reads.test';

/** The contract identity, the same value the ERP test pins as ORDER_READS_CONTRACT_DIGEST. */
const ERP_ORDER_READS_CONTRACT_DIGEST = 'e646da534fe9549ec5bf72a845e537bc1437072b693c2c367c6e33a022289b22';

function orderReadsFixture(string $name): array
{
    return json_decode((string) file_get_contents(base_path("tests/fixtures/erp/order-reads/{$name}.json")), true);
}

/** The fixtures were captured for ERP business 1, so the connector here is for business 1. */
function orderReadsConnector(int $erpBusinessId = 1): ErpConnector
{
    return new ErpConnector('bor-de-guna', $erpBusinessId, ORDER_READS_ERP, 'tok', app(ConnectorCallLogger::class));
}

function orderReadsUrl(string $path, int $business = 1): string
{
    return ORDER_READS_ERP."/api/hq/v1/{$business}/order-reads/{$path}";
}

it('holds an unedited copy of the ERP fixtures, whose digest is the one both repositories pin', function () {
    $manifest = orderReadsFixture('manifest');
    $digests = [];
    foreach ($manifest['files'] as $name => $digest) {
        $canonical = (string) json_encode(orderReadsFixture($name), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        expect(hash('sha256', $canonical))->toBe($digest, "fixture [{$name}] differs from the copy the ERP wrote");
        $digests[$name] = $digest;
    }

    $contract = hash('sha256', (string) json_encode($digests, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    expect($manifest['contract'])->toBe(ErpConnector::ORDER_READS_CONTRACT)
        ->and($manifest['contract_digest'])->toBe($contract)
        ->and($contract)->toBe(ERP_ORDER_READS_CONTRACT_DIGEST)
        ->and(count($manifest['files']))->toBe(12);
});

it('reads every order-read body the ERP sends, found and not found, through the logged connector', function () {
    Http::fake([
        orderReadsUrl('products*') => Http::response(orderReadsFixture('products')),
        orderReadsUrl('skus/HON-500') => Http::response(orderReadsFixture('sku')),
        orderReadsUrl('skus/NOPE-1') => Http::response(orderReadsFixture('sku-not-found'), 404),
        orderReadsUrl('prices/HON-500?quantity=12&store=laladia') => Http::response(orderReadsFixture('price-store')),
        orderReadsUrl('prices/HON-500?quantity=1&customer_id=1') => Http::response(orderReadsFixture('price-customer')),
        orderReadsUrl('prices/HON-500?quantity=1') => Http::response(orderReadsFixture('price-nobody')),
        orderReadsUrl('prices/OIL-1L*') => Http::response(orderReadsFixture('price-none'), 404),
        orderReadsUrl('availability/HON-500') => Http::response(orderReadsFixture('availability')),
        orderReadsUrl('availability/GHE-250?store=laladia') => Http::response(orderReadsFixture('availability-store')),
        orderReadsUrl('customer-eligibility') => Http::response(orderReadsFixture('eligibility')),
    ]);
    $erp = orderReadsConnector();

    $products = $erp->lookupProducts(['q' => 'honey'], 2);
    expect($products->found)->toBeTrue()
        ->and(collect($products->data)->pluck('sku')->all())->toBe(['GHE-250', 'HON-500'])
        ->and($products->meta['total'])->toBe(3)
        ->and($products->asOf())->toBe('2026-09-21T10:00:00+06:00');

    $sku = $erp->lookupSku('HON-500');
    expect($sku->found)->toBeTrue()->and($sku->data['variant_model'])->toBe('none')->and($sku->data['orderable'])->toBeTrue();

    $missing = $erp->lookupSku('NOPE-1');
    expect($missing->found)->toBeFalse()->and($missing->code)->toBe('not_found')->and($missing->message)->toBe('No product with SKU NOPE-1.');

    $store = $erp->readPrice('HON-500', '12', storeKey: 'laladia');
    expect($store->data['unit_price'])->toBe('800.0000')
        ->and($store->data['price_source']['tier'])->toBe('price_group')
        ->and($store->data['price_source']['explanation'])->toBe('Price for group [RETAIL] from 10.0000 units');
    expect($erp->readPrice('HON-500', customerId: 1)->data['price_source']['tier'])->toBe('customer');
    expect($erp->readPrice('HON-500')->data['priced_as'])->toBe('nobody');

    $none = $erp->readPrice('OIL-1L');
    expect($none->found)->toBeFalse()->and($none->code)->toBe('no_price_in_force');

    $availability = $erp->readAvailability('HON-500');
    expect($availability->data['stock']['on_hand'])->toBe('5')
        ->and($availability->data['availability']['verdict_needs_store'])->toBeTrue();
    expect($erp->readAvailability('GHE-250', 'laladia')->data['availability']['store']['message'])->toBe('Out of stock.');

    $eligibility = $erp->checkCustomerEligibility('01711000004');
    expect($eligibility->data['match']['display_name'])->toBe('Salma Begum')
        ->and($eligibility->data['fields_the_erp_does_not_have'])->toBe(['blocked', 'cod_eligible', 'credit_hold']);

    // A phone travels in a POST body and never in a URL or a recorded reference.
    Http::assertSent(fn (Request $r) => $r->url() === orderReadsUrl('customer-eligibility') && $r->method() === 'POST' && $r['phone'] === '01711000004');
    expect(ConnectorCall::where('operation', 'checkCustomerEligibility')->sole()->request_ref)->toBeNull();

    // Every call is logged, and a "not found" answer is a succeeded call with the status the ERP sent.
    expect(ConnectorCall::where('connector', 'erp:bor-de-guna')->count())->toBe(10)
        ->and(ConnectorCall::where('operation', 'lookupSku')->where('request_ref', 'NOPE-1')->sole())
        ->status->toBe('succeeded')->response_status->toBe(404);
});

it('treats a 403 as the token lacking an ability, a 404 it does not know as a refusal, and a 5xx as unavailable', function () {
    Http::fake([
        orderReadsUrl('prices/HON-500*') => Http::response(orderReadsFixture('forbidden'), 403),
        orderReadsUrl('skus/X-1') => Http::response(['error' => ['code' => 'not_found_route', 'message' => 'Route missing.']], 404),
        orderReadsUrl('availability/*') => Http::response(['message' => 'down'], 503),
    ]);
    $erp = orderReadsConnector();

    expect(fn () => $erp->readPrice('HON-500'))->toThrow(Unsupported::class, 'Token lacks ability sales.view.');
    expect(fn () => $erp->lookupSku('X-1'))->toThrow(Refused::class);
    expect(fn () => $erp->readAvailability('HON-500'))->toThrow(Unavailable::class);
});

it('refuses a body from another contract version or for another business, rather than reading it as an answer', function () {
    $otherContract = orderReadsFixture('sku');
    $otherContract['meta']['contract'] = 'hq-order-reads.v2';
    $otherBusiness = orderReadsFixture('sku');
    $otherBusiness['meta']['business_id'] = 2;
    $noData = orderReadsFixture('sku');
    unset($noData['data']);

    Http::fake([
        orderReadsUrl('skus/V2') => Http::response($otherContract),
        orderReadsUrl('skus/B2') => Http::response($otherBusiness),
        orderReadsUrl('skus/ND') => Http::response($noData),
    ]);
    $erp = orderReadsConnector();

    expect(fn () => $erp->lookupSku('V2'))->toThrow(Unsupported::class, 'hq-order-reads.v2');
    expect(fn () => $erp->lookupSku('B2'))->toThrow(Unsupported::class, 'business 2');
    expect(fn () => $erp->lookupSku('ND'))->toThrow(Unsupported::class, 'no `data` key');
});

it('sends the gateway\'s correlation id to the ERP, so the id the ERP logs is the one HQ recorded', function () {
    Http::fake([orderReadsUrl('skus/HON-500') => Http::response(orderReadsFixture('sku'))]);

    Context::scope(fn () => orderReadsConnector()->lookupSku('HON-500'), ['correlation_id' => 'corr-order-reads-1']);
    Http::assertSent(fn (Request $r) => $r->header('X-Correlation-Id') === ['corr-order-reads-1']);
    expect(ConnectorCall::where('operation', 'lookupSku')->sole()->correlation_id)->toBe('corr-order-reads-1');

    // With no id, or one the ERP would not accept, a fresh one is sent instead.
    Context::scope(fn () => orderReadsConnector()->lookupSku('HON-500'), ['correlation_id' => 'bad id!']);
    Http::assertSent(fn (Request $r) => preg_match('/^[0-9a-f-]{36}$/', $r->header('X-Correlation-Id')[0] ?? '') === 1);
});

it('encodes the SKU into the path and sends only the filters the ERP accepts', function () {
    Http::fake([ORDER_READS_ERP.'/*' => Http::response(orderReadsFixture('products'))]);

    orderReadsConnector()->lookupProducts(['q' => 'mustard oil', 'status' => 'all', 'brand' => null], 5, 2);
    Http::assertSent(function (Request $r) {
        $query = [];
        parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $query);

        return str_starts_with($r->url(), orderReadsUrl('products?'))
            && $query === ['filter' => ['q' => 'mustard oil', 'status' => 'all'], 'per_page' => '5', 'page' => '2'];
    });

    expect(fn () => orderReadsConnector()->lookupProducts(['cost' => 'x']))->toThrow(InvalidArgumentException::class);
});
