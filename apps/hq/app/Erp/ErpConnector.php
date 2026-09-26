<?php

namespace App\Erp;

use App\Commerce\Exceptions\Refused;
use App\Commerce\Exceptions\Unavailable;
use App\Commerce\Exceptions\Uncertain;
use App\Commerce\Exceptions\Unsupported;
use App\Commerce\Exceptions\ValidationFailed;
use App\Commerce\Support\ConnectorCallLogger;
use App\Erp\Dto\ErpLowStock;
use App\Erp\Dto\ErpLowStockLine;
use App\Erp\Dto\ErpOrderRead;
use App\Erp\Dto\ErpOverview;
use App\Erp\Dto\ErpTicketResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Client for the ERP's read-first HQ API (bordeguna-erp branch feature/hq-api, routes/api.php).
 *
 * One connector per (ERP business, token): the token is issued for exactly one business and the
 * ERP refuses any other {business} in the URL, so the connector never lets a caller pick the
 * business per call. Every call is logged to connector_calls. Failures are classified the same
 * way the shop adapter classifies them: Unavailable (network / 5xx), Unsupported (401/403, token
 * lacks the ability), ValidationFailed (422), Refused (404 for an unknown resource), Uncertain
 * (a write whose outcome is unknown).
 */
final class ErpConnector
{
    public const CONNECTOR_PREFIX = 'erp:';

    /** The version the ERP's order-reads stamp on every body (`meta.contract`), docs/81 C.5. */
    public const ORDER_READS_CONTRACT = 'hq-order-reads.v1';

    /** The version the ERP stamps on every draft sales order body (`meta.contract`), docs/83 C.3. */
    public const SALES_ORDER_DRAFTS_CONTRACT = 'hq-sales-order-drafts.v1';

    /** The version the ERP stamps on every sales-order body: quote, order, read-back, cancel (docs/88 A point 3). */
    public const SALES_ORDERS_CONTRACT = 'hq-sales-orders.v1';

    /** The only product filters the ERP's order-reads accept; anything else is refused before it is sent. */
    public const PRODUCT_FILTERS = ['q', 'sku', 'id', 'barcode', 'brand', 'status'];

    /** What the ERP accepts as an `X-Correlation-Id` (HqApiContext); anything else it replaces. */
    private const CORRELATION_PATTERN = '/^[A-Za-z0-9._:-]{8,128}$/';

    public function __construct(
        public readonly string $businessSlug,
        public readonly int $erpBusinessId,
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly ConnectorCallLogger $logger,
        private readonly int $timeoutSeconds = 15,
    ) {}

    public function connectorName(): string
    {
        return self::CONNECTOR_PREFIX.$this->businessSlug;
    }

    /** @return array<int, array{id:int,name:string,is_active:bool}> */
    public function businesses(): array
    {
        return $this->get('businesses', 'businesses')['data'] ?? [];
    }

    public function overview(): ErpOverview
    {
        $r = $this->get($this->path('overview'), 'overview');
        $data = ErpContract::requireDataArray($r, 'overview');
        ErpContract::requireSections($data, ErpOverview::SECTIONS, 'overview');

        return ErpOverview::fromApi($this->erpBusinessId, $data, $r['meta']['as_of'] ?? null);
    }

    /**
     * Low stock, with the ERP's own count and timestamp rather than HQ's clock and HQ's guess at
     * how many rows there should have been.
     */
    public function lowStock(): ErpLowStock
    {
        $r = $this->get($this->path('stock/low'), 'lowStock');
        $rows = ErpContract::requireDataArray($r, 'lowStock');
        $meta = is_array($r['meta'] ?? null) ? $r['meta'] : [];
        ErpContract::requireRowCount($rows, $meta, 'count', 'lowStock');

        return new ErpLowStock(
            array_map(fn (array $row) => ErpLowStockLine::fromApi($row), $rows),
            $meta['as_of'] ?? null,
        );
    }

    /** @return array{data: array, meta: array, links?: array} */
    public function stock(array $filter = [], int $perPage = 200, int $page = 1): array
    {
        return $this->get($this->path('stock'), 'stock', ['filter' => $filter, 'per_page' => $perPage, 'page' => $page]);
    }

    public function products(array $filter = [], int $perPage = 200, int $page = 1): array
    {
        return $this->get($this->path('products'), 'products', ['filter' => $filter, 'per_page' => $perPage, 'page' => $page]);
    }

    public function product(string $sku): array
    {
        return $this->get($this->path('products/'.rawurlencode($sku)), 'product')['data'] ?? [];
    }

    public function customers(?string $phone = null, int $perPage = 100, int $page = 1): array
    {
        $query = ['per_page' => $perPage, 'page' => $page];
        if ($phone !== null) {
            $query['filter'] = ['phone' => $phone];
        }

        return $this->get($this->path('customers'), 'customers', $query);
    }

    public function customer(int $id): array
    {
        return $this->get($this->path("customers/{$id}"), 'customer')['data'] ?? [];
    }

    public function tickets(?string $status = null, int $perPage = 100, int $page = 1): array
    {
        $query = ['per_page' => $perPage, 'page' => $page];
        if ($status !== null) {
            $query['filter'] = ['status' => $status];
        }

        return $this->get($this->path('tickets'), 'tickets', $query);
    }

    /**
     * A write (the others are the draft sales order and the order routes below). `idempotencyKey` is sent as the ERP's
     * idempotency_key so a retried call gets the same ticket back (replayed=true) instead of opening
     * a second one.
     */
    public function openTicket(array $ticket, string $idempotencyKey): ErpTicketResult
    {
        $connector = $this->connectorName();
        $payload = $ticket + ['idempotency_key' => $idempotencyKey];

        return $this->logger->call($connector, 'openTicket', function () use ($payload) {
            try {
                $response = $this->client()->post($this->url($this->path('tickets')), $payload);
            } catch (ConnectionException $e) {
                // The request may or may not have reached the ERP; the idempotency key makes a retry safe.
                throw new Uncertain('ERP unreachable while opening ticket: '.$e->getMessage(), 0, $e);
            }
            $this->throwForStatus($response, 'openTicket');
            $data = $response->json('data') ?? [];

            return new ErpTicketResult((int) ($data['id'] ?? 0), (string) ($data['ticket_number'] ?? ''), (bool) ($data['replayed'] ?? false));
        }, $idempotencyKey);
    }

    // ---- Phase 4 read side: order-reads (docs/81 C.5). Reads only; nothing here writes. -----------

    /**
     * Products by identity: text in the name or SKU, an exact SKU, id, barcode or brand code, and
     * whether active, inactive or both. Cost is not in the answer under any ability.
     *
     * @param  array<string, string|int|null>  $filter  keys from PRODUCT_FILTERS only
     */
    public function lookupProducts(array $filter = [], int $perPage = 20, int $page = 1): ErpOrderRead
    {
        $unknown = array_diff(array_keys($filter), self::PRODUCT_FILTERS);
        if ($unknown !== []) {
            throw new \InvalidArgumentException('lookupProducts does not send the filter(s) ['.implode(', ', $unknown).'].');
        }
        $filter = array_filter($filter, fn ($value) => $value !== null && $value !== '');

        return $this->orderRead($this->path('order-reads/products'), 'lookupProducts', [
            'filter' => $filter, 'per_page' => $perPage, 'page' => $page,
        ], answering: []);
    }

    /** The one product a SKU names in this business, or the ERP saying there is none. */
    public function lookupSku(string $sku): ErpOrderRead
    {
        return $this->orderRead($this->path('order-reads/skus/'.rawurlencode($sku)), 'lookupSku', [], answering: ['not_found'], ref: $sku);
    }

    /**
     * The price the ERP's one pricing engine answers, with the rule that produced it: for a store
     * (its price group), for a named customer, or for nobody (the standard selling price only).
     * "No price in force", "no such SKU", "no such store" and "no such customer" are answers.
     */
    public function readPrice(string $sku, string $quantity = '1', ?string $storeKey = null, ?int $customerId = null, ?string $on = null): ErpOrderRead
    {
        $query = array_filter(['quantity' => $quantity, 'store' => $storeKey, 'customer_id' => $customerId, 'on' => $on], fn ($v) => $v !== null);

        return $this->orderRead($this->path('order-reads/prices/'.rawurlencode($sku)), 'readPrice', $query,
            answering: ['not_found', 'no_price_in_force', 'unknown_store', 'unknown_customer'], ref: $sku);
    }

    /** Stock and availability as the two facts the ERP keeps apart; a verdict only for a named store. */
    public function readAvailability(string $sku, ?string $storeKey = null): ErpOrderRead
    {
        $query = $storeKey === null ? [] : ['store' => $storeKey];

        return $this->orderRead($this->path('order-reads/availability/'.rawurlencode($sku)), 'readAvailability', $query,
            answering: ['not_found', 'unknown_store'], ref: $sku);
    }

    /**
     * Whether a phone names a customer, the way the ERP's checkout would pick one. ⚠ The phone goes
     * in a POST body, never a URL, and is never the recorded request reference.
     */
    public function checkCustomerEligibility(string $phone): ErpOrderRead
    {
        return $this->orderRead($this->path('order-reads/customer-eligibility'), 'checkCustomerEligibility', ['phone' => $phone],
            answering: [], method: 'post');
    }

    // ---- Phase 4 draft write (docs/83 C.3). A DRAFT and nothing after it. -------------------------

    /**
     * Leave a draft sales order in the ERP: priced and checked by the ERP, with no order, number,
     * approval, customer, stock, journal or notification after it (the ERP says so in `effects`).
     *
     * ⚠⚠ The outcome of a POST that never answered is UNKNOWN, not failed: the draft may exist. That
     * is `Uncertain`, and the caller reads the draft back by the same key (`readSalesOrderDraft`)
     * before anything is sent again. A 409 is the ERP refusing a key already used for a different
     * draft, `Refused`, never retried; a 422 is `ValidationFailed` with the ERP's own sentences (a
     * price that moved, a product no longer sellable); a 403 is `Unsupported` (the token lacks
     * `sales.orders.draft`, a readiness state).
     *
     * @param  array<string, mixed>  $draft  the request body, `idempotency_key` included
     */
    public function createSalesOrderDraft(array $draft): ErpOrderRead
    {
        $key = (string) ($draft['idempotency_key'] ?? '');
        if ($key === '') {
            throw new \InvalidArgumentException('createSalesOrderDraft needs an idempotency_key.');
        }

        return $this->logger->call($this->connectorName(), 'createSalesOrderDraft', function () use ($draft, $key) {
            try {
                $response = $this->client()->withHeaders(['Idempotency-Key' => $key])
                    ->post($this->url($this->path('sales-order-drafts')), $draft);
            } catch (ConnectionException $e) {
                throw new Uncertain('ERP unreachable while leaving a draft sales order: '.$e->getMessage(), 0, $e);
            }
            if ($response->status() === 409) {
                throw new Refused('ERP createSalesOrderDraft: '.(string) ($response->json('error.message') ?? 'the key was used for a different draft'), 409);
            }
            $this->throwForStatus($response, 'createSalesOrderDraft');

            return $this->draftRead($response->json() ?? [], 'createSalesOrderDraft');
        }, $key);
    }

    /** The draft left under this key, or the ERP saying there is none (an answer, not a failure). */
    public function readSalesOrderDraft(string $idempotencyKey): ErpOrderRead
    {
        return $this->logger->call($this->connectorName(), 'readSalesOrderDraft', function () use ($idempotencyKey) {
            $response = $this->send(fn (PendingRequest $c) => $c->get($this->url($this->path('sales-order-drafts/'.rawurlencode($idempotencyKey)))));
            if ($response->status() === 404 && $response->json('error.code') === 'not_found') {
                return ErpOrderRead::notFound('not_found', (string) ($response->json('error.message') ?? 'not_found'));
            }
            $this->throwForStatus($response, 'readSalesOrderDraft');

            return $this->draftRead($response->json() ?? [], 'readSalesOrderDraft');
        }, null, $idempotencyKey);
    }

    /** A draft body must carry `data`, the drafts contract version, this business, and the status `draft`. */
    private function draftRead(array $body, string $operation): ErpOrderRead
    {
        $data = ErpContract::requireDataArray($body, $operation);
        $meta = is_array($body['meta'] ?? null) ? $body['meta'] : [];
        ErpContract::requireOrderReadsContract($meta, self::SALES_ORDER_DRAFTS_CONTRACT, $this->erpBusinessId, $operation);
        if (($data['status'] ?? null) !== 'draft') {
            throw new Unsupported("ERP {$operation}: the answer is not a draft (status [".(is_scalar($data['status'] ?? null) ? (string) $data['status'] : 'none').']).');
        }

        return new ErpOrderRead(found: true, data: $data, meta: $meta);
    }

    // ---- Phase 4 order completion (docs/88 A point 3, docs/79 F). ---------------------------------
    //
    // A quote (a read sent as POST), the order made FROM an approved draft (it waits in the ERP's
    // approval inbox; HQ never approves it), the read-back by reference or by HQ's key, and HQ's
    // cancellation of an order it placed. The two writes need abilities no ERP token holds until one
    // is issued for them by name (`sales.orders.record`, `sales.orders.cancel`).

    /**
     * What the ERP would charge for this order now, from its one pricing engine. Writes nothing.
     * A request the ERP would not take is `ValidationFailed` with the ERP's own sentences per field.
     *
     * @param  array<string, mixed>  $quote  store_key, destination, lines [{sku, quantity}]
     */
    public function quoteSalesOrder(array $quote): ErpOrderRead
    {
        return $this->logger->call($this->connectorName(), 'quoteSalesOrder', function () use ($quote) {
            $response = $this->send(fn (PendingRequest $c) => $c->post($this->url($this->path('sales-orders/quote')), $quote));
            $this->throwForStatus($response, 'quoteSalesOrder');

            return $this->orderBody($response->json() ?? [], 'quoteSalesOrder', quote: true);
        });
    }

    /**
     * An approved draft becomes a sales order that WAITS for a person in the ERP's approval inbox.
     *
     * ⚠⚠ The same rules as the draft: a POST that never answered is `Uncertain` (the order may exist;
     * `readSalesOrderByKey` settles it before anything is sent again); a 409 is the ERP refusing
     * (`idempotency_key_reused`, or `draft_already_confirmed`: a draft becomes one order, once) and is
     * never retried; a 422 is `ValidationFailed` with the ERP's sentences (the ERP priced the draft
     * again and something moved: write a new draft); a 404 is `Refused` (no such draft); a 403 is
     * `Unsupported` (the token lacks `sales.orders.record`).
     *
     * @param  array<string, mixed>  $order  idempotency_key, draft_key, proposed, hq_reference
     */
    public function recordSalesOrder(array $order): ErpOrderRead
    {
        $key = (string) ($order['idempotency_key'] ?? '');
        if ($key === '') {
            throw new \InvalidArgumentException('recordSalesOrder needs an idempotency_key.');
        }

        return $this->logger->call($this->connectorName(), 'recordSalesOrder', function () use ($order, $key) {
            try {
                $response = $this->client()->withHeaders(['Idempotency-Key' => $key])
                    ->post($this->url($this->path('sales-orders')), $order);
            } catch (ConnectionException $e) {
                throw new Uncertain('ERP unreachable while recording the order: '.$e->getMessage(), 0, $e);
            }
            if ($response->status() === 409) {
                throw new Refused('ERP recordSalesOrder: '.(string) ($response->json('error.message') ?? 'the ERP refused the order'), 409);
            }
            $this->throwForStatus($response, 'recordSalesOrder');

            return $this->orderBody($response->json() ?? [], 'recordSalesOrder');
        }, $key);
    }

    /** One order HQ placed, by the customer's reference or the ERP order number; "none" is an answer. */
    public function readSalesOrder(string $reference): ErpOrderRead
    {
        return $this->logger->call($this->connectorName(), 'readSalesOrder', function () use ($reference) {
            $response = $this->send(fn (PendingRequest $c) => $c->get($this->url($this->path('sales-orders/'.rawurlencode($reference)))));
            if ($response->status() === 404 && $response->json('error.code') === 'not_found') {
                return ErpOrderRead::notFound('not_found', (string) ($response->json('error.message') ?? 'not_found'));
            }
            $this->throwForStatus($response, 'readSalesOrder');

            return $this->orderBody($response->json() ?? [], 'readSalesOrder');
        }, null, $reference);
    }

    /** The order HQ's key made, for settling a request that never answered; "none" is an answer. */
    public function readSalesOrderByKey(string $idempotencyKey): ErpOrderRead
    {
        return $this->logger->call($this->connectorName(), 'readSalesOrderByKey', function () use ($idempotencyKey) {
            $response = $this->send(fn (PendingRequest $c) => $c->get($this->url($this->path('sales-orders/by-key/'.rawurlencode($idempotencyKey)))));
            if ($response->status() === 404 && $response->json('error.code') === 'not_found') {
                return ErpOrderRead::notFound('not_found', (string) ($response->json('error.message') ?? 'not_found'));
            }
            $this->throwForStatus($response, 'readSalesOrderByKey');

            return $this->orderBody($response->json() ?? [], 'readSalesOrderByKey');
        }, null, $idempotencyKey);
    }

    /**
     * HQ cancels an order it placed, under the ERP's own rule (only an open order with nothing
     * delivered). A POST that never answered is `Uncertain`; a 409 (`already_cancelled`,
     * `has_deliveries`, `idempotency_key_reused`) and a 404 (not an order HQ placed) are `Refused`
     * with the ERP's sentence and never retried; a 403 is `Unsupported` (no `sales.orders.cancel`).
     *
     * @param  array<string, mixed>  $cancellation  idempotency_key, reason, hq_reference
     */
    public function cancelSalesOrder(string $reference, array $cancellation): ErpOrderRead
    {
        $key = (string) ($cancellation['idempotency_key'] ?? '');
        if ($key === '') {
            throw new \InvalidArgumentException('cancelSalesOrder needs an idempotency_key.');
        }

        return $this->logger->call($this->connectorName(), 'cancelSalesOrder', function () use ($reference, $cancellation, $key) {
            try {
                $response = $this->client()->withHeaders(['Idempotency-Key' => $key])
                    ->post($this->url($this->path('sales-orders/'.rawurlencode($reference).'/cancel')), $cancellation);
            } catch (ConnectionException $e) {
                throw new Uncertain('ERP unreachable while cancelling the order: '.$e->getMessage(), 0, $e);
            }
            if ($response->status() === 409) {
                throw new Refused('ERP cancelSalesOrder: '.(string) ($response->json('error.message') ?? 'the ERP refused the cancellation'), 409);
            }
            $this->throwForStatus($response, 'cancelSalesOrder');

            return $this->orderBody($response->json() ?? [], 'cancelSalesOrder');
        }, $key, $reference);
    }

    /**
     * An order body must carry `data`, the orders contract version and this business; an order (not
     * a quote) must also carry a status and an order number, so a body of another shape cannot be
     * read as an order that exists.
     */
    private function orderBody(array $body, string $operation, bool $quote = false): ErpOrderRead
    {
        $data = ErpContract::requireDataArray($body, $operation);
        $meta = is_array($body['meta'] ?? null) ? $body['meta'] : [];
        ErpContract::requireOrderReadsContract($meta, self::SALES_ORDERS_CONTRACT, $this->erpBusinessId, $operation);
        if (! $quote && (! is_string($data['status'] ?? null) || ! is_string($data['order_number'] ?? null))) {
            throw new Unsupported("ERP {$operation}: the answer names no order (status and order_number are required).");
        }

        return new ErpOrderRead(found: true, data: $data, meta: $meta);
    }

    public function pendingApprovals(): array
    {
        return $this->get($this->path('approvals/pending'), 'pendingApprovals')['data'] ?? [];
    }

    public function reports(): array
    {
        return $this->get($this->path('reports'), 'reports')['data'] ?? [];
    }

    public function runReport(string $key, array $parameters = [], int $limit = 500): array
    {
        $connector = $this->connectorName();

        return $this->logger->call($connector, 'runReport', function () use ($key, $parameters, $limit) {
            $response = $this->send(fn (PendingRequest $c) => $c->post($this->url($this->path('reports/'.rawurlencode($key).'/run')), ['parameters' => $parameters, 'limit' => $limit]));
            $this->throwForStatus($response, 'runReport');

            return $response->json('data') ?? [];
        }, null, $key);
    }

    /**
     * The unmapped response for one read endpoint, for auditing what the ERP actually sends.
     *
     * Every other method on this connector hands back a DTO, which is the right thing for callers
     * and the wrong thing for an audit: a DTO can only show the fields somebody already thought to
     * map. This returns the envelope as it arrived, so a key HQ ignores, a key that changed name,
     * and a key that is absent rather than null are all visible. Read-only, and restricted to a
     * fixed list so it cannot become an arbitrary HTTP client pointed at the ERP.
     *
     * @return array{data?: mixed, meta?: array}
     */
    public function rawRead(string $endpoint): array
    {
        $path = match ($endpoint) {
            'overview' => $this->path('overview'),
            'low_stock' => $this->path('stock/low'),
            'businesses' => 'businesses',
            'reports' => $this->path('reports'),
            default => throw new \InvalidArgumentException("rawRead does not know the endpoint [{$endpoint}]."),
        };

        return $this->get($path, $endpoint.':raw');
    }

    /** Cheap liveness check: can this token see its own business? */
    public function probe(): array
    {
        $started = microtime(true);
        try {
            $businesses = $this->businesses();
            $seesOwn = collect($businesses)->contains(fn ($b) => (int) $b['id'] === $this->erpBusinessId);

            return ['ok' => $seesOwn, 'business_id' => $this->erpBusinessId, 'visible_businesses' => count($businesses), 'ms' => (int) ((microtime(true) - $started) * 1000)];
        } catch (\Throwable $e) {
            return ['ok' => false, 'business_id' => $this->erpBusinessId, 'error' => class_basename($e).': '.$e->getMessage(), 'ms' => (int) ((microtime(true) - $started) * 1000)];
        }
    }

    // ---- plumbing --------------------------------------------------------------------------------

    private function path(string $suffix): string
    {
        return $this->erpBusinessId.'/'.$suffix;
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').'/api/hq/v1/'.ltrim($path, '/');
    }

    private function client(): PendingRequest
    {
        return Http::withToken($this->token)
            ->acceptJson()
            ->withHeaders(['X-Correlation-Id' => $this->correlationId()])
            ->timeout($this->timeoutSeconds)
            ->connectTimeout(5);
    }

    /**
     * The gateway's correlation id, so the id the ERP echoes and logs is the one `connector_calls`
     * records. A fresh one only when there is none, or when it is one the ERP would replace anyway.
     */
    private function correlationId(): string
    {
        $id = Context::get('correlation_id');

        return is_string($id) && preg_match(self::CORRELATION_PATTERN, $id) === 1 ? $id : (string) Str::uuid();
    }

    /**
     * One order-read. A 404 whose code is in `$answering` is the ERP stating a fact and comes back
     * as `found: false`; every other failure is classified as for any other call. A found body must
     * carry `data`, the order-reads contract version, and this connector's own business.
     *
     * @param  list<string>  $answering
     */
    private function orderRead(string $path, string $operation, array $query, array $answering, ?string $ref = null, string $method = 'get'): ErpOrderRead
    {
        return $this->logger->call($this->connectorName(), $operation, function () use ($path, $operation, $query, $answering, $method) {
            $response = $this->send(fn (PendingRequest $c) => $method === 'post'
                ? $c->post($this->url($path), $query)
                : $c->get($this->url($path), $query));

            $code = $response->json('error.code');
            if ($response->status() === 404 && is_string($code) && in_array($code, $answering, true)) {
                return ErpOrderRead::notFound($code, (string) ($response->json('error.message') ?? $code));
            }
            $this->throwForStatus($response, $operation);

            $body = $response->json() ?? [];
            $data = ErpContract::requireDataArray($body, $operation);
            $meta = is_array($body['meta'] ?? null) ? $body['meta'] : [];
            ErpContract::requireOrderReadsContract($meta, self::ORDER_READS_CONTRACT, $this->erpBusinessId, $operation);

            return new ErpOrderRead(found: true, data: $data, meta: $meta, links: is_array($body['links'] ?? null) ? $body['links'] : []);
        }, null, $ref);
    }

    private function get(string $path, string $operation, array $query = []): array
    {
        return $this->logger->call($this->connectorName(), $operation, function () use ($path, $query, $operation) {
            $response = $this->send(fn (PendingRequest $c) => $c->get($this->url($path), $query));
            $this->throwForStatus($response, $operation);

            return $response->json() ?? [];
        });
    }

    /** @param  callable(PendingRequest): Response  $fn */
    private function send(callable $fn): Response
    {
        try {
            return $fn($this->client());
        } catch (ConnectionException $e) {
            throw new Unavailable('ERP unreachable: '.$e->getMessage(), 0, $e);
        }
    }

    private function throwForStatus(Response $response, string $operation): void
    {
        $status = $response->status();
        if ($status >= 200 && $status < 300) {
            return;
        }
        $message = (string) ($response->json('error.message') ?? $response->json('message') ?? "HTTP {$status}");
        match (true) {
            $status === 401 || $status === 403 => throw new Unsupported("ERP {$operation}: {$message}", $status),
            $status === 404 => throw new Refused("ERP {$operation}: {$message}", $status),
            $status === 422 => throw new ValidationFailed("ERP {$operation}: {$message}", (array) ($response->json('errors') ?? [])),
            $status === 429 || $status >= 500 => throw new Unavailable("ERP {$operation}: {$message}", $status),
            default => throw new Unavailable("ERP {$operation}: unexpected HTTP {$status}", $status),
        };
    }
}
