<?php

use App\Commerce\Exceptions\Refused;
use App\Commerce\Exceptions\Uncertain;
use App\Commerce\Exceptions\Unsupported;
use App\Commerce\Exceptions\ValidationFailed;
use App\Commerce\Support\ConnectorCallLogger;
use App\Erp\ErpConnector;
use App\Models\ConnectorCall;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 | The Phase 4 order completion's contract with the ERP (docs/88 A point 3, docs/79 F).
 |
 | ⚠ The fixtures under tests/fixtures/erp/sales-orders are NOT written here. The ERP's own test
 | `tests/Feature/HqApi/HqSalesOrderTest.php` writes them from the real endpoints (with
 | HQ_API_WRITE_FIXTURES=1) and pins the same contract digest this file pins, so a change on either side
 | fails that side's test until the other is brought along. HQ holds a copy; this test proves the copy was
 | not edited by hand and that the connector reads every body the ERP sends.
 |
 | Origin: bordeguna-erp branch feature/hq-sales-order-confirm, the commit that added the order routes.
 */

const ORDERS_ERP = 'https://erp.orders.test';

/** The contract identity, the same value the ERP test pins as ORDERS_CONTRACT_DIGEST. */
const ERP_ORDERS_CONTRACT_DIGEST = 'b3aaf4087f03a2bd669cc5693108f853a6772aaa0a81f0c04728cdbafcfa21b6';

function ordersFixture(string $name): array
{
    return json_decode((string) file_get_contents(base_path("tests/fixtures/erp/sales-orders/{$name}.json")), true);
}

/** The fixtures were captured for ERP business 1. */
function ordersConnector(int $erpBusinessId = 1): ErpConnector
{
    return new ErpConnector('laladia', $erpBusinessId, ORDERS_ERP, 'tok', app(ConnectorCallLogger::class));
}

function ordersUrl(string $path = '', int $business = 1): string
{
    return ORDERS_ERP."/api/hq/v1/{$business}/sales-orders".($path === '' ? '' : '/'.$path);
}

function ordersRequest(string $key = 'hq:order-0000000001'): array
{
    return ['idempotency_key' => $key, 'draft_key' => 'hq:draft-0000000001', 'proposed' => [], 'hq_reference' => ['proposal_fingerprint' => str_repeat('f0', 32)]];
}

it('holds an unedited copy of the ERP fixtures, whose digest is the one both repositories pin', function () {
    $manifest = ordersFixture('manifest');
    $digests = [];
    foreach ($manifest['files'] as $name => $digest) {
        $canonical = (string) json_encode(ordersFixture($name), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        expect(hash('sha256', $canonical))->toBe($digest, "fixture [{$name}] differs from the copy the ERP wrote");
        $digests[$name] = $digest;
    }

    $contract = hash('sha256', (string) json_encode($digests, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    expect($manifest['contract'])->toBe(ErpConnector::SALES_ORDERS_CONTRACT)
        ->and($manifest['contract_digest'])->toBe($contract)
        ->and($contract)->toBe(ERP_ORDERS_CONTRACT_DIGEST)
        ->and(count($manifest['files']))->toBe(15);
});

it('reads the quote, and the ERP refusing one, as the ERP wrote them', function () {
    Http::fake([ordersUrl('quote') => Http::sequence()->push(ordersFixture('quote'))->push(ordersFixture('quote-refused'), 422)]);
    $erp = ordersConnector();

    $quote = $erp->quoteSalesOrder(['store_key' => 'laladia', 'destination' => [], 'lines' => [['sku' => 'HON-500', 'quantity' => 2]]]);
    expect($quote->found)->toBeTrue()
        ->and($quote->data['agreed_total'])->toBe('2470.50')
        ->and($quote->data['lines'][1]['made_to_order'])->toBeTrue()
        ->and($quote->data['effects']['written'])->toBeFalse();

    try {
        $erp->quoteSalesOrder(['store_key' => 'laladia', 'destination' => [], 'lines' => [['sku' => 'NOPE-1', 'quantity' => 1]]]);
        $this->fail('a refused quote must be reported');
    } catch (ValidationFailed $e) {
        expect($e->getMessage())->toContain('would not take this order')
            ->and($e->errors)->toBe(['lines.0.sku' => ['No product with SKU NOPE-1 in this business.']]);
    }
});

it('reads the created, replayed and read-back order bodies, and sends the key in the header and the body', function () {
    Http::fake([
        ordersUrl() => Http::sequence()->push(ordersFixture('created'), 201)->push(ordersFixture('replayed')),
        ordersUrl('by-key/*') => Http::response(ordersFixture('by-key')),
        ordersUrl('*') => Http::response(ordersFixture('read')),
    ]);
    $erp = ordersConnector();

    $created = $erp->recordSalesOrder(ordersRequest());
    $replayed = $erp->recordSalesOrder(ordersRequest());
    $byKey = $erp->readSalesOrderByKey('hq:order-0000000001');
    $read = $erp->readSalesOrder('LLD1-20260921-ABCD2345');

    expect($created->found)->toBeTrue()
        ->and($created->data['status'])->toBe('open')
        ->and($created->data['approval_status'])->toBe('pending')
        ->and($created->data['deliverable'])->toBeFalse()
        ->and($created->data['replayed'])->toBeFalse()
        ->and($created->data['effects'])->toBe(['sales_order_created' => true, 'approval_requested' => true, 'approved_by_hq' => false,
            'stock_moved' => false, 'journal_posted' => false, 'payment_taken' => false, 'courier_booked' => false])
        ->and($created->data['hq']['draft_key'])->toBe('hq:draft-0000000001')
        ->and($replayed->data['replayed'])->toBeTrue()
        ->and($replayed->data['id'])->toBe($created->data['id'])
        ->and($byKey->data)->not->toHaveKey('replayed')
        ->and($byKey->data['order_number'])->toBe($created->data['order_number'])
        ->and($read->data['lines'])->toBe($created->data['lines']);

    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === ordersUrl()
        && $r->header('Idempotency-Key') === ['hq:order-0000000001']
        && $r['idempotency_key'] === 'hq:order-0000000001');
    expect(ConnectorCall::where('operation', 'recordSalesOrder')->pluck('idempotency_key')->unique()->values()->all())
        ->toBe(['hq:order-0000000001']);
});

it('reads "no order" as an answer, and every refusal of an order as its own class', function () {
    Http::fake([
        ordersUrl('by-key/hq%3Anobody-0001') => Http::response(ordersFixture('not-found'), 404),
        ordersUrl('LLD-NOBODY-0001') => Http::response(ordersFixture('not-found'), 404),
        ordersUrl() => Http::sequence()
            ->push(ordersFixture('key-reused'), 409)
            ->push(ordersFixture('already-confirmed'), 409)
            ->push(ordersFixture('draft-repriced'), 422)
            ->push(ordersFixture('forbidden'), 403),
    ]);
    $erp = ordersConnector();

    $none = $erp->readSalesOrderByKey('hq:nobody-0001');
    $nobody = $erp->readSalesOrder('LLD-NOBODY-0001');
    expect($none->found)->toBeFalse()->and($none->code)->toBe('not_found')
        ->and($nobody->found)->toBeFalse()->and($nobody->message)->toContain('No order HQ placed');

    // A key reused for another order, and a draft that already became an order: refused, never retried.
    expect(fn () => $erp->recordSalesOrder(ordersRequest()))->toThrow(Refused::class, 'already used for a different order');
    expect(fn () => $erp->recordSalesOrder(ordersRequest()))->toThrow(Refused::class, 'a draft becomes one order, once');

    // The ERP priced the draft again and something moved: its sentences arrive as the errors.
    try {
        $erp->recordSalesOrder(ordersRequest());
        $this->fail('a repriced draft must be refused');
    } catch (ValidationFailed $e) {
        expect($e->getMessage())->toContain('no longer match')
            ->and(array_keys($e->errors))->toBe(['lines.0.unit_price', 'draft.goods_total', 'draft.agreed_total'])
            ->and($e->errors['lines.0.unit_price'][0])->toContain('Write a new draft');
    }
    expect(fn () => $erp->recordSalesOrder(ordersRequest()))->toThrow(Unsupported::class, 'sales.view');
});

it('reads the cancelled and replayed cancellation bodies, and every refusal of a cancellation as its own class', function () {
    Http::fake([
        ordersUrl('LLD1-20260921-ABCD2345/cancel') => Http::sequence()
            ->push(ordersFixture('cancelled'))
            ->push(ordersFixture('cancel-replayed'))
            ->push(ordersFixture('already-cancelled'), 409),
        ordersUrl('LLD1-20260921-EFGH6789/cancel') => Http::response(ordersFixture('has-deliveries'), 409),
        ordersUrl('SO-NOT-HQ-0001/cancel') => Http::response(ordersFixture('not-found'), 404),
    ]);
    $erp = ordersConnector();
    $cancel = ['idempotency_key' => 'hq:cancel-000000001', 'reason' => 'The customer changed their mind on the phone.'];

    $cancelled = $erp->cancelSalesOrder('LLD1-20260921-ABCD2345', $cancel);
    $replayed = $erp->cancelSalesOrder('LLD1-20260921-ABCD2345', $cancel);
    expect($cancelled->data['status'])->toBe('cancelled')
        ->and($cancelled->data['cancellation'])->toBe(['requested' => true, 'idempotency_key' => 'hq:cancel-000000001', 'reason' => 'The customer changed their mind on the phone.'])
        ->and($cancelled->data['replayed'])->toBeFalse()
        ->and($cancelled->data['next_step'])->toBe('None. The order is cancelled.')
        ->and($replayed->data['replayed'])->toBeTrue();

    expect(fn () => $erp->cancelSalesOrder('LLD1-20260921-ABCD2345', $cancel))->toThrow(Refused::class, 'already cancelled');
    expect(fn () => $erp->cancelSalesOrder('LLD1-20260921-EFGH6789', $cancel))->toThrow(Refused::class, 'has deliveries against it');
    expect(fn () => $erp->cancelSalesOrder('SO-NOT-HQ-0001', $cancel))->toThrow(Refused::class, 'No order HQ placed');

    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/cancel')
        && $r->header('Idempotency-Key') === ['hq:cancel-000000001'] && $r['reason'] === $cancel['reason']);
});

it('calls a POST that never answered uncertain, for the order and for the cancellation, because either may exist', function () {
    Http::fake(fn () => throw new ConnectionException('timed out'));

    expect(fn () => ordersConnector()->recordSalesOrder(ordersRequest()))->toThrow(Uncertain::class, 'unreachable');
    expect(fn () => ordersConnector()->cancelSalesOrder('LLD1-20260921-ABCD2345', ['idempotency_key' => 'hq:cancel-000000001', 'reason' => 'x x x']))->toThrow(Uncertain::class, 'unreachable');
    expect(ConnectorCall::whereIn('operation', ['recordSalesOrder', 'cancelSalesOrder'])->pluck('status')->unique()->all())->toBe(['uncertain']);
});

it('refuses a body from another contract, another business, or one that names no order', function () {
    $other = ordersFixture('read');
    $other['meta']['contract'] = 'hq-sales-order-drafts.v1';
    $foreign = ordersFixture('read');
    $foreign['meta']['business_id'] = 2;
    $nameless = ordersFixture('read');
    unset($nameless['data']['order_number']);

    Http::fake([ordersUrl('*') => Http::sequence()->push($other)->push($foreign)->push($nameless)]);
    $erp = ordersConnector();

    expect(fn () => $erp->readSalesOrder('k'))->toThrow(Unsupported::class, 'contract')
        ->and(fn () => $erp->readSalesOrder('k'))->toThrow(Unsupported::class, 'business 2')
        ->and(fn () => $erp->readSalesOrder('k'))->toThrow(Unsupported::class, 'names no order');
});
