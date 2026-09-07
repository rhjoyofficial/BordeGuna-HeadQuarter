<?php

use App\Commerce\Exceptions\Refused;
use App\Commerce\Exceptions\Unavailable;
use App\Commerce\Exceptions\Uncertain;
use App\Commerce\Exceptions\Unsupported;
use App\Commerce\Exceptions\ValidationFailed;
use App\Commerce\Support\ConnectorCallLogger;
use App\Erp\ErpConnector;
use App\Erp\ErpRegistry;
use App\Models\Business;
use App\Models\ConnectorCall;
use App\Models\FactsErpSnapshot;
use App\Models\IntegrationCredential;
use App\Scheduling\Tools\ToolContext;
use App\Scheduling\Tools\ToolRegistry;
use Database\Seeders\DomainSeeder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 | Behaviour tests: auth, path scoping, failure classification, the one write. The response shapes
 | here are hand-built to exercise a specific branch, and several are deliberately unrealistic
 | (a 503 body, a 422). For the question "is this the shape the ERP really sends", see
 | ErpContractFidelityTest, which uses payloads captured from production rather than written here.
 */

const ERP = 'https://erp.test';

function erp(int $businessId = 4): ErpConnector
{
    return new ErpConnector('bor-de-guna', $businessId, ERP, 'tok', app(ConnectorCallLogger::class));
}

it('sends the bearer token, scopes every path to the business, and maps the overview', function () {
    Http::fake([
        // `invoices_past_due_date` is here because the real endpoint always sends it; the earlier
        // fixture omitted it, which is the drift ErpContractFidelityTest now guards against.
        ERP.'/api/hq/v1/4/overview' => Http::response(['data' => [
            'sales' => ['invoiced_this_month' => 1200.5, 'invoices_past_due_date' => 2, 'customers' => 3],
            'inventory' => ['stock_value' => 500, 'low_stock_count' => 1, 'active_products' => 2],
            'purchasing' => null, 'approvals' => ['pending' => 0], 'tickets' => null,
        ], 'meta' => ['as_of' => '2026-09-05T10:00:00+00:00']]),
    ]);
    $o = erp()->overview();

    expect($o->erpBusinessId)->toBe(4)->and($o->sales['customers'])->toBe(3)->and($o->purchasing)->toBeNull()->and($o->tickets)->toBeNull()
        ->and($o->asOf)->toBe('2026-09-05T10:00:00+00:00');
    Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer tok') && $r->hasHeader('X-Correlation-Id') && $r->url() === ERP.'/api/hq/v1/4/overview');
    expect(ConnectorCall::where('connector', 'erp:bor-de-guna')->where('operation', 'overview')->where('status', 'succeeded')->count())->toBe(1);
});

it('maps low stock lines and paginated reads', function () {
    Http::fake([
        ERP.'/api/hq/v1/4/stock/low' => Http::response(['data' => [['product_id' => 9, 'sku' => 'GHEE-500', 'name' => 'Ghee 500g', 'on_hand' => 3, 'threshold' => 10]], 'meta' => ['count' => 1]]),
        ERP.'/api/hq/v1/4/stock*' => Http::response(['data' => [['sku' => 'GHEE-500', 'on_hand' => 3]], 'meta' => ['page' => 1, 'total' => 1]]),
    ]);
    $low = erp()->lowStock();
    expect($low->lines)->toHaveCount(1)->and($low->count())->toBe(1)
        ->and($low->lines[0]->sku)->toBe('GHEE-500')->and($low->lines[0]->onHand)->toBe(3.0)->and($low->lines[0]->threshold)->toBe(10.0);

    $stock = erp()->stock(['warehouse' => 'MAIN']);
    expect($stock['meta']['total'])->toBe(1);
    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/4/stock?') && str_contains(urldecode($r->url()), 'filter[warehouse]=MAIN'));
});

it('classifies failures: 403 unsupported, 404 refused, 422 validation, 5xx and network unavailable', function () {
    Http::fake([
        ERP.'/api/hq/v1/4/overview' => Http::response(['error' => ['code' => 'forbidden', 'message' => 'Token lacks ability x.']], 403),
        ERP.'/api/hq/v1/4/products/NOPE' => Http::response(['error' => ['code' => 'not_found', 'message' => 'No product with SKU NOPE.']], 404),
        ERP.'/api/hq/v1/4/reports/x/run' => Http::response(['message' => 'The given data was invalid.'], 422),
        ERP.'/api/hq/v1/4/stock/low' => Http::response('', 503),
        ERP.'/api/hq/v1/4/tickets*' => fn () => throw new ConnectionException('timeout'),
    ]);
    $e = erp();
    expect(fn () => $e->overview())->toThrow(Unsupported::class, 'Token lacks ability x.');
    expect(fn () => $e->product('NOPE'))->toThrow(Refused::class);
    expect(fn () => $e->runReport('x'))->toThrow(ValidationFailed::class);
    expect(fn () => $e->lowStock())->toThrow(Unavailable::class);
    expect(fn () => $e->tickets())->toThrow(Unavailable::class);
    expect(ConnectorCall::where('connector', 'erp:bor-de-guna')->where('status', 'failed')->count())->toBe(5);
});

it('opens a ticket with the idempotency key, reports replays, and is uncertain on a network failure', function () {
    Http::fake([
        ERP.'/api/hq/v1/4/tickets' => Http::sequence()
            ->push(['data' => ['id' => 12, 'ticket_number' => 'TK-2026-27-0001', 'replayed' => false]], 201)
            ->push(['data' => ['id' => 12, 'ticket_number' => 'TK-2026-27-0001', 'replayed' => true]], 200)
            ->pushResponse(fn () => throw new ConnectionException('reset')),
    ]);
    $ticket = ['subject' => 'Late delivery', 'caller_name' => 'Anika', 'category' => 'delivery', 'priority' => 'high'];
    $first = erp()->openTicket($ticket, 'conv-9');
    $second = erp()->openTicket($ticket, 'conv-9');

    expect($first->ticketNumber)->toBe('TK-2026-27-0001')->and($first->replayed)->toBeFalse()->and($second->replayed)->toBeTrue();
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r['idempotency_key'] === 'conv-9' && $r['subject'] === 'Late delivery');
    expect(fn () => erp()->openTicket($ticket, 'conv-9'))->toThrow(Uncertain::class);
    expect(ConnectorCall::where('operation', 'openTicket')->where('idempotency_key', 'conv-9')->count())->toBe(3)
        ->and(ConnectorCall::where('operation', 'openTicket')->where('status', 'uncertain')->count())->toBe(1);
});

it('probes by checking the token can see its own business', function () {
    Http::fake([ERP.'/api/hq/v1/businesses' => Http::response(['data' => [['id' => 4, 'name' => 'Bor de Guna', 'is_active' => true]]])]);
    expect(erp()->probe()['ok'])->toBeTrue()->and(erp(1)->probe()['ok'])->toBeFalse();
});

it('resolves businesses to connectors from integration_credentials and refuses those without an ERP counterpart or token', function () {
    $this->seed(DomainSeeder::class);
    config(['hq.erp.base_url' => ERP]);
    $registry = app(ErpRegistry::class);

    expect(fn () => $registry->for('econic'))->toThrow(Unsupported::class, 'no ERP counterpart');
    expect(fn () => $registry->for('bor-de-guna'))->toThrow(Unsupported::class, 'No active erp_api_token');
    expect($registry->businessesWithConnectors())->toBe([]);

    IntegrationCredential::create(['provider' => 'erp_api_token', 'account_ref' => 'bor-de-guna', 'credential_type' => 'api_token', 'payload' => ['value' => 'secret'], 'status' => 'active']);
    $erp = $registry->for('bor-de-guna');
    expect($erp->erpBusinessId)->toBe(4)->and($registry->businessesWithConnectors())->toBe(['bor-de-guna']);

    Http::fake([ERP.'/api/hq/v1/businesses' => Http::response(['data' => []])]);
    $erp->businesses();
    Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer secret'));
});

it('poll tools write the snapshot read model, replace it on the next poll, and fail cleanly without a token', function () {
    $this->seed(DomainSeeder::class);
    config(['hq.erp.base_url' => ERP]);
    $tools = app(ToolRegistry::class);
    $business = Business::where('slug', 'bor-de-guna')->firstOrFail();
    $ctx = fn (int $attempt = 1) => new ToolContext(idempotencyKey: 'k-'.$attempt, correlationId: 'c', businessId: $business->id, jobRunId: 77, attempt: $attempt);

    $noToken = $tools->get('erp.poll_overview')->execute([], $ctx());
    expect($noToken->ok())->toBeFalse()->and($noToken->error)->toContain('No active erp_api_token');

    IntegrationCredential::create(['provider' => 'erp_api_token', 'account_ref' => 'bor-de-guna', 'credential_type' => 'api_token', 'payload' => ['value' => 'secret'], 'status' => 'active']);
    Http::fake([
        // All five sections, because the real endpoint always sends all five - the ones this token
        // cannot see arrive as null rather than going missing.
        ERP.'/api/hq/v1/4/overview' => Http::sequence()
            ->push(['data' => ['sales' => null, 'inventory' => ['low_stock_count' => 1], 'purchasing' => null, 'approvals' => null, 'tickets' => null], 'meta' => ['as_of' => '2026-09-05T10:00:00+00:00']])
            ->push(['data' => ['sales' => null, 'inventory' => ['low_stock_count' => 4], 'purchasing' => null, 'approvals' => null, 'tickets' => null], 'meta' => ['as_of' => '2026-09-05T11:00:00+00:00']]),
        ERP.'/api/hq/v1/4/stock/low' => Http::response(['data' => [['product_id' => 9, 'sku' => 'GHEE-500', 'name' => 'Ghee', 'on_hand' => 3, 'threshold' => 10]], 'meta' => ['count' => 1, 'as_of' => '2026-09-05T11:05:00+00:00']]),
    ]);

    $r1 = $tools->get('erp.poll_overview')->execute([], $ctx());
    expect($r1->ok())->toBeTrue()->and($r1->output['kind'])->toBe('overview');
    $tools->get('erp.poll_overview')->execute(['business_slug' => 'bor-de-guna'], $ctx(2));
    $snap = FactsErpSnapshot::where('business_id', $business->id)->where('kind', 'overview')->firstOrFail();
    expect(FactsErpSnapshot::count())->toBe(1)->and($snap->payload['inventory']['low_stock_count'])->toBe(4)->and($snap->as_of->toIso8601String())->toBe('2026-09-05T11:00:00+00:00')->and($snap->job_run_ref)->toBe('77');

    $low = $tools->get('erp.poll_low_stock')->execute([], $ctx());
    expect($low->ok())->toBeTrue()->and($low->output['items'])->toBe(1)
        ->and(FactsErpSnapshot::where('kind', 'low_stock')->first()->payload[0]['sku'])->toBe('GHEE-500');

    expect($tools->get('erp.poll_overview')->execute([], new ToolContext('k', 'c'))->error)->toContain('No business given');
});
