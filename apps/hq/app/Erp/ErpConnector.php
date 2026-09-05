<?php

namespace App\Erp;

use App\Commerce\Exceptions\Refused;
use App\Commerce\Exceptions\Unavailable;
use App\Commerce\Exceptions\Uncertain;
use App\Commerce\Exceptions\Unsupported;
use App\Commerce\Exceptions\ValidationFailed;
use App\Commerce\Support\ConnectorCallLogger;
use App\Erp\Dto\ErpLowStockLine;
use App\Erp\Dto\ErpOverview;
use App\Erp\Dto\ErpTicketResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
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

        return ErpOverview::fromApi($this->erpBusinessId, $r['data'] ?? [], $r['meta']['as_of'] ?? null);
    }

    /** @return ErpLowStockLine[] */
    public function lowStock(): array
    {
        $r = $this->get($this->path('stock/low'), 'lowStock');

        return array_map(fn (array $row) => ErpLowStockLine::fromApi($row), $r['data'] ?? []);
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
            ->withHeaders(['X-Correlation-Id' => (string) Str::uuid()])
            ->timeout($this->timeoutSeconds)
            ->connectTimeout(5);
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
