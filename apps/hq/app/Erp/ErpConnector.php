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
     * The one write. `idempotencyKey` is sent as the ERP's idempotency_key so a retried call gets
     * the same ticket back (replayed=true) instead of opening a second one.
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
