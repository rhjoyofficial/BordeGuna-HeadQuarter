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
 | The Phase 4 draft write's contract with the ERP (docs/83 C.3, docs/79).
 |
 | ⚠ The fixtures under tests/fixtures/erp/sales-order-drafts are NOT written here. The ERP's own test
 | `tests/Feature/HqApi/HqSalesOrderDraftTest.php` writes them from the real endpoints (with
 | HQ_API_WRITE_FIXTURES=1) and pins the same contract digest this file pins, so a change on either
 | side fails that side's test until the other is brought along. HQ holds a copy; this test proves
 | the copy was not edited by hand and that the connector reads every body the ERP sends.
 |
 | Origin: bordeguna-erp branch feature/hq-sales-order-draft, the commit that added the drafts.
 */

const DRAFTS_ERP = 'https://erp.drafts.test';

/** The contract identity, the same value the ERP test pins as DRAFTS_CONTRACT_DIGEST. */
const ERP_DRAFTS_CONTRACT_DIGEST = '414fdaa6dae0d89a260e3af7db2838211d2b7898cd32c72d4afc714862d2a02f';

function draftsFixture(string $name): array
{
    return json_decode((string) file_get_contents(base_path("tests/fixtures/erp/sales-order-drafts/{$name}.json")), true);
}

/** The fixtures were captured for ERP business 1. */
function draftsConnector(int $erpBusinessId = 1): ErpConnector
{
    return new ErpConnector('laladia', $erpBusinessId, DRAFTS_ERP, 'tok', app(ConnectorCallLogger::class));
}

function draftsUrl(string $path = '', int $business = 1): string
{
    return DRAFTS_ERP."/api/hq/v1/{$business}/sales-order-drafts".($path === '' ? '' : '/'.$path);
}

function draftsRequest(string $key = 'hq:a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1'): array
{
    return ['idempotency_key' => $key, 'store_key' => 'laladia', 'destination' => [], 'lines' => [], 'payment_method' => 'cod', 'proposed' => [], 'hq_reference' => []];
}

it('holds an unedited copy of the ERP fixtures, whose digest is the one both repositories pin', function () {
    $manifest = draftsFixture('manifest');
    $digests = [];
    foreach ($manifest['files'] as $name => $digest) {
        $canonical = (string) json_encode(draftsFixture($name), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        expect(hash('sha256', $canonical))->toBe($digest, "fixture [{$name}] differs from the copy the ERP wrote");
        $digests[$name] = $digest;
    }

    $contract = hash('sha256', (string) json_encode($digests, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    expect($manifest['contract'])->toBe(ErpConnector::SALES_ORDER_DRAFTS_CONTRACT)
        ->and($manifest['contract_digest'])->toBe($contract)
        ->and($contract)->toBe(ERP_DRAFTS_CONTRACT_DIGEST)
        ->and(count($manifest['files']))->toBe(8);
});

it('reads the created, replayed and read-back bodies, and sends the key in the header and the body', function () {
    Http::fake([
        draftsUrl() => Http::sequence()->push(draftsFixture('created'), 201)->push(draftsFixture('replayed')),
        draftsUrl('*') => Http::response(draftsFixture('read')),
    ]);
    $erp = draftsConnector();

    $created = $erp->createSalesOrderDraft(draftsRequest());
    $replayed = $erp->createSalesOrderDraft(draftsRequest());
    $read = $erp->readSalesOrderDraft('hq:'.str_repeat('a1', 32));

    expect($created->found)->toBeTrue()
        ->and($created->data['status'])->toBe('draft')
        ->and($created->data['replayed'])->toBeFalse()
        ->and($created->data['effects']['sales_order_created'])->toBeFalse()
        ->and($replayed->data['replayed'])->toBeTrue()
        ->and($replayed->data['id'])->toBe($created->data['id'])
        ->and($read->data)->not->toHaveKey('replayed')
        ->and($read->data['lines'])->toBe($created->data['lines']);

    Http::assertSent(fn (Request $r) => $r->method() === 'POST'
        && $r->header('Idempotency-Key') === ['hq:'.str_repeat('a1', 32)]
        && $r['idempotency_key'] === 'hq:'.str_repeat('a1', 32));
    expect(ConnectorCall::where('operation', 'createSalesOrderDraft')->pluck('idempotency_key')->unique()->values()->all())
        ->toBe(['hq:'.str_repeat('a1', 32)]);
});

it('reads "no draft under that key" as an answer, and every refusal as its own class', function () {
    Http::fake([
        draftsUrl('hq%3Anothing-here-00000') => Http::response(draftsFixture('not-found'), 404),
        draftsUrl() => Http::sequence()
            ->push(draftsFixture('key-reused'), 409)
            ->push(draftsFixture('quote-mismatch'), 422)
            ->push(draftsFixture('refused'), 422)
            ->push(draftsFixture('forbidden'), 403),
    ]);
    $erp = draftsConnector();

    $none = $erp->readSalesOrderDraft('hq:nothing-here-00000');
    expect($none->found)->toBeFalse()->and($none->code)->toBe('not_found');

    // A key reused for another draft: refused, never retried.
    expect(fn () => $erp->createSalesOrderDraft(draftsRequest()))->toThrow(Refused::class, 'already used for a different draft');

    // A figure that moved: the ERP's sentences arrive as the errors.
    try {
        $erp->createSalesOrderDraft(draftsRequest());
        $this->fail('a quote mismatch must be refused');
    } catch (ValidationFailed $e) {
        expect($e->getMessage())->toContain('no longer match')
            ->and($e->errors)->toBe(['lines.0.unit_price' => ['Proposed 800.00, the ERP\'s price is 850.00.']]);
    }
    expect(fn () => $erp->createSalesOrderDraft(draftsRequest()))->toThrow(ValidationFailed::class, 'will not take this draft');
    expect(fn () => $erp->createSalesOrderDraft(draftsRequest()))->toThrow(Unsupported::class, 'sales.orders.draft');
});

it('calls a POST that never answered uncertain, because the draft may exist', function () {
    Http::fake(fn () => throw new ConnectionException('timed out'));

    expect(fn () => draftsConnector()->createSalesOrderDraft(draftsRequest()))->toThrow(Uncertain::class, 'unreachable');
    expect(ConnectorCall::where('operation', 'createSalesOrderDraft')->value('status'))->toBe('uncertain');
});

it('refuses a body from another contract, another business, or one that is not a draft', function () {
    $other = draftsFixture('read');
    $other['meta']['contract'] = 'hq-order-reads.v1';
    $foreign = draftsFixture('read');
    $foreign['meta']['business_id'] = 2;
    $open = draftsFixture('read');
    $open['data']['status'] = 'open';

    Http::fake([draftsUrl('*') => Http::sequence()->push($other)->push($foreign)->push($open)]);
    $erp = draftsConnector();

    expect(fn () => $erp->readSalesOrderDraft('k'))->toThrow(Unsupported::class, 'contract')
        ->and(fn () => $erp->readSalesOrderDraft('k'))->toThrow(Unsupported::class, 'business 2')
        ->and(fn () => $erp->readSalesOrderDraft('k'))->toThrow(Unsupported::class, 'not a draft');
});
