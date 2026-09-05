<?php

namespace App\Commerce\Adapters;

use App\Commerce\Contracts\CommercePlatform;
use App\Commerce\Dto\Availability;
use App\Commerce\Dto\Capabilities;
use App\Commerce\Dto\Combo;
use App\Commerce\Dto\IdempotencyKey;
use App\Commerce\Dto\Order;
use App\Commerce\Dto\OrderDraft;
use App\Commerce\Dto\OrderLine;
use App\Commerce\Dto\OrderQuote;
use App\Commerce\Dto\OrderResult;
use App\Commerce\Dto\Product;
use App\Commerce\Dto\QuoteLine;
use App\Commerce\Dto\StoreRef;
use App\Commerce\Dto\Variant;
use App\Commerce\Exceptions\Refused;
use App\Commerce\Exceptions\Unavailable;
use App\Commerce\Exceptions\Uncertain;
use App\Commerce\Exceptions\Unsupported;
use App\Commerce\Exceptions\ValidationFailed;
use App\Commerce\Support\ConnectorCallLogger;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;

/**
 * Adapter for the LaLaDia shop platform and its forks (Bionic). Verified against the live
 * /api/v1 surface on 5 Sep 2026 (docs/35 section B): reads through the public and
 * `assistant` endpoints (X-API-Key), order lookups through the admin API (Sanctum bearer),
 * order creation through /api/v1/checkout (preview first). The shop computes every price.
 */
final class LaravelShopAdapter implements CommercePlatform
{
    private const CONNECT_TIMEOUT = 5;

    private const READ_TIMEOUT = 15;

    private const WRITE_TIMEOUT = 30;

    public function __construct(
        private readonly StoreRef $store,
        private readonly ?string $assistantKey,
        private readonly ?string $adminToken,
        private readonly ConnectorCallLogger $logger,
    ) {}

    public function store(): StoreRef
    {
        return $this->store;
    }

    public function capabilities(): Capabilities
    {
        return Capabilities::laravelShop(hasAdminToken: $this->adminToken !== null);
    }

    public function searchProducts(string $query, int $limit = 10): array
    {
        return $this->logger->call($this->connector(), 'searchProducts', function () use ($query, $limit) {
            $json = $this->read('/api/v1/assistant/products/search', ['q' => $query, 'per_page' => min($limit, 50)]);

            return array_map(fn ($p) => $this->mapProduct($p), $json['data'] ?? []);
        });
    }

    public function getProduct(string $slug): Product
    {
        return $this->logger->call($this->connector(), 'getProduct', function () use ($slug) {
            $json = $this->read('/api/v1/assistant/products/'.rawurlencode($slug));

            return $this->mapProduct($json['data'] ?? []);
        }, requestRef: $slug);
    }

    public function getVariant(int $variantId): Variant
    {
        // The shop has no variant endpoint; a variant is reached through its product. Callers that
        // hold a Product should use Product::variant(). Here we search the catalogue pages once.
        return $this->logger->call($this->connector(), 'getVariant', function () use ($variantId) {
            $page = 1;
            do {
                $json = $this->read('/api/v1/assistant/products', ['per_page' => 100, 'page' => $page]);
                foreach ($json['data'] ?? [] as $p) {
                    foreach ($p['variants'] ?? [] as $v) {
                        if ((int) $v['id'] === $variantId) {
                            return $this->mapVariant($v);
                        }
                    }
                }
                $last = (int) ($json['meta']['last_page'] ?? 1);
            } while (++$page <= $last && $page <= 20);
            throw new Refused("Variant {$variantId} not found on {$this->store->slug}");
        }, requestRef: (string) $variantId);
    }

    public function getCombos(): array
    {
        return $this->logger->call($this->connector(), 'getCombos', function () {
            $json = $this->read('/api/v1/assistant/combos');

            return array_map(fn ($c) => new Combo(
                id: (int) $c['id'], slug: (string) ($c['slug'] ?? ''), name: (string) ($c['name'] ?? $c['title'] ?? ''),
                price: (float) ($c['final_price'] ?? $c['price'] ?? 0), isActive: (bool) ($c['is_active'] ?? true), items: $c['items'] ?? [],
            ), $json['data'] ?? []);
        });
    }

    /**
     * Until the shop ships its availability endpoint, the verdict is derived from the fields the
     * product payload already carries: product is_active + status, variant is_active + available_stock.
     * `reason` names WHY, so HQ's availability policy (status_based vs stock_based) can decide the wording.
     */
    public function getAvailability(int $variantId): Availability
    {
        return $this->logger->call($this->connector(), 'getAvailability', function () use ($variantId) {
            [$product, $variant] = $this->findProductAndVariant($variantId);
            $status = $product->status;
            $reason = match (true) {
                ! $product->isActive => 'inactive',
                $status === 'out_of_stock' || $status === 'coming_soon' => 'status:'.$status,
                ! $variant->isActive => 'variant_inactive',
                $variant->availableStock !== null && $variant->availableStock <= 0 => 'stock_insufficient',
                default => 'ok',
            };

            return new Availability(
                variantId: $variantId,
                orderable: $reason === 'ok',
                reason: $reason,
                stockHint: $variant->availableStock,
                productStatus: $status,
            );
        }, requestRef: (string) $variantId);
    }

    public function previewOrder(OrderDraft $draft): OrderQuote
    {
        return $this->logger->call($this->connector(), 'previewOrder', function () use ($draft) {
            $path = $draft->landingSlug ? '/api/v1/landing/'.rawurlencode($draft->landingSlug).'/preview' : '/api/v1/checkout/preview';
            $json = $this->write($path, $draft->toShopPayload(), null);
            $data = $json['data'] ?? $json;

            return new OrderQuote(
                subtotal: (float) ($data['subtotal'] ?? 0),
                discount: (float) ($data['discount_total'] ?? $data['discount'] ?? 0),
                shipping: (float) ($data['shipping_cost'] ?? $data['shipping'] ?? 0),
                total: (float) ($data['grand_total'] ?? $data['total'] ?? 0),
                lines: array_map(fn ($l) => new QuoteLine(
                    label: (string) ($l['name'] ?? $l['product_name'] ?? ''), quantity: (int) ($l['quantity'] ?? $l['qty'] ?? 1),
                    unitPrice: (float) ($l['unit_price'] ?? $l['price'] ?? 0), total: (float) ($l['total'] ?? $l['line_total'] ?? 0),
                    variantId: isset($l['variant_id']) ? (int) $l['variant_id'] : null, comboId: isset($l['combo_id']) ? (int) $l['combo_id'] : null,
                ), $data['lines'] ?? $data['items'] ?? []),
                coupon: $data['coupon'] ?? null,
                raw: $data,
            );
        });
    }

    public function createOrder(OrderDraft $draft, IdempotencyKey $key): OrderResult
    {
        return $this->logger->call($this->connector(), 'createOrder', function () use ($draft, $key) {
            $path = $draft->landingSlug ? '/api/v1/landing/'.rawurlencode($draft->landingSlug).'/checkout' : '/api/v1/checkout';
            try {
                $json = $this->write($path, $draft->toShopPayload(checkoutToken: $key->value), $key->value, self::WRITE_TIMEOUT);
            } catch (Uncertain $e) {
                return new OrderResult(OrderResult::UNCERTAIN, error: $e->getMessage());
            } catch (ValidationFailed|Refused $e) {
                return new OrderResult(OrderResult::REFUSED, error: $e->getMessage());
            }
            $data = $json['data'] ?? $json;
            $ref = (string) ($data['order_number'] ?? $data['order']['order_number'] ?? '');
            if ($ref === '') {
                return new OrderResult(OrderResult::UNCERTAIN, error: 'checkout answered 2xx without an order_number');
            }

            return new OrderResult(OrderResult::CONFIRMED, order: $this->mapOrder(is_array($data['order'] ?? null) ? $data['order'] : $data), providerRef: $ref);
        }, idempotencyKey: $key->value);
    }

    public function getOrder(string $orderRef): Order
    {
        $this->requireAdmin('getOrder');

        return $this->logger->call($this->connector(), 'getOrder', function () use ($orderRef) {
            $json = $this->read('/api/v1/admin/orders/'.rawurlencode($orderRef), [], admin: true);

            return $this->mapOrder($json['data'] ?? $json);
        }, requestRef: $orderRef);
    }

    public function findOrdersByPhone(string $phoneCanonical, int $limit = 10): array
    {
        $this->requireAdmin('findOrdersByPhone');

        return $this->logger->call($this->connector(), 'findOrdersByPhone', function () use ($phoneCanonical, $limit) {
            $json = $this->read('/api/v1/admin/orders', ['search' => $phoneCanonical, 'per_page' => $limit], admin: true);

            return array_map(fn ($o) => $this->mapOrder($o), $json['data'] ?? []);
        });
    }

    public function listRecentOrders(\DateTimeInterface $since, int $limit = 100): array
    {
        $this->requireAdmin('listRecentOrders');

        return $this->logger->call($this->connector(), 'listRecentOrders', function () use ($since, $limit) {
            $json = $this->read('/api/v1/admin/orders', ['per_page' => min($limit, 100), 'sort' => '-placed_at'], admin: true);
            $orders = array_map(fn ($o) => $this->mapOrder($o), $json['data'] ?? []);

            return array_values(array_filter($orders, fn (Order $o) => $o->placedAt === null || strtotime($o->placedAt) >= $since->getTimestamp()));
        });
    }

    public function reconcileOrder(IdempotencyKey $key, OrderDraft $draft): ?Order
    {
        if ($this->adminToken === null) {
            return null; // cannot look anything up without the admin token; caller keeps the run `uncertain`
        }
        $candidates = $this->findOrdersByPhone($draft->customer->phone, 10);
        $wantQty = array_sum(array_map(fn ($l) => $l->quantity, $draft->lines));
        foreach ($candidates as $order) {
            $recent = $order->placedAt && strtotime($order->placedAt) >= time() - 900;
            $sameQty = array_sum(array_map(fn (OrderLine $l) => $l->quantity, $order->lines)) === $wantQty;
            $marked = $draft->conversationRef && str_contains(json_encode($order->raw), "[hq:{$draft->conversationRef}]");
            if ($marked || ($recent && $sameQty)) {
                return $order;
            }
        }

        return null;
    }

    public function probe(): array
    {
        return $this->logger->call($this->connector(), 'probe', function () {
            $public = $this->http()->get($this->store->baseUrl.'/api/v1/products', ['per_page' => 1]);
            $assistant = $this->assistantKey ? $this->http()->withHeaders(['X-API-Key' => $this->assistantKey])->get($this->store->baseUrl.'/api/v1/assistant/products', ['per_page' => 1]) : null;
            $admin = $this->adminToken ? $this->http()->withToken($this->adminToken)->get($this->store->baseUrl.'/api/v1/admin/orders', ['per_page' => 1]) : null;

            return [
                'store' => $this->store->slug,
                'public_products' => $public->status(),
                'assistant_products' => $assistant?->status() ?? 'no key',
                'admin_orders' => $admin?->status() ?? 'no token',
            ];
        });
    }

    // ---- HTTP -------------------------------------------------------------------------------

    private function connector(): string
    {
        return 'shop:'.$this->store->slug;
    }

    private function http(int $timeout = self::READ_TIMEOUT): PendingRequest
    {
        return Http::acceptJson()
            ->connectTimeout(self::CONNECT_TIMEOUT)
            ->timeout($timeout)
            ->withHeaders(array_filter(['X-Correlation-Id' => Context::get('correlation_id'), 'User-Agent' => 'BordeGuna-HQ/phase-1']));
    }

    private function read(string $path, array $query = [], bool $admin = false): array
    {
        $request = $this->http();
        if ($admin) {
            $this->requireAdmin($path);
            $request = $request->withToken($this->adminToken);
        } elseif (str_starts_with($path, '/api/v1/assistant/')) {
            if ($this->assistantKey === null) {
                throw new Unsupported("No assistant key stored for {$this->store->slug}");
            }
            $request = $request->withHeaders(['X-API-Key' => $this->assistantKey]);
        }
        try {
            $response = $request->get($this->store->baseUrl.$path, $query);
        } catch (ConnectionException $e) {
            throw new Unavailable("{$this->store->slug} unreachable: ".$e->getMessage(), 0, $e);
        }

        return $this->decode($response, write: false);
    }

    private function write(string $path, array $payload, ?string $idempotencyKey, int $timeout = self::READ_TIMEOUT): array
    {
        $request = $this->http($timeout);
        if ($idempotencyKey) {
            $request = $request->withHeaders(['Idempotency-Key' => $idempotencyKey]);
        }
        try {
            $response = $request->post($this->store->baseUrl.$path, $payload);
        } catch (ConnectionException $e) {
            // A write that timed out after the request left us MAY have been applied.
            throw new Uncertain("{$this->store->slug} did not answer the write: ".$e->getMessage(), 0, $e);
        }

        return $this->decode($response, write: true);
    }

    private function decode(Response $response, bool $write): array
    {
        $status = $response->status();
        $json = $response->json() ?? [];
        if ($status >= 200 && $status < 300) {
            if (($json['success'] ?? true) === false) {
                throw new Refused((string) ($json['message'] ?? 'refused'), $status);
            }

            return $json;
        }
        if ($status === 422) {
            throw new ValidationFailed((string) ($json['message'] ?? 'validation failed'), $json['errors'] ?? []);
        }
        if ($status === 401 || $status === 403) {
            throw new Unsupported("{$this->store->slug} refused credentials ({$status})", $status);
        }
        if ($status === 404 || $status === 409 || ($status >= 400 && $status < 500)) {
            throw new Refused((string) ($json['message'] ?? "HTTP {$status}"), $status);
        }
        if ($write) {
            throw new Uncertain("{$this->store->slug} answered HTTP {$status} to a write", $status);
        }
        throw new Unavailable("{$this->store->slug} answered HTTP {$status}", $status);
    }

    private function requireAdmin(string $op): void
    {
        if ($this->adminToken === null) {
            throw new Unsupported("{$op} needs the shop admin token for {$this->store->slug}; none stored");
        }
    }

    // ---- mapping ----------------------------------------------------------------------------

    private function mapProduct(array $p): Product
    {
        return new Product(
            id: (int) ($p['id'] ?? 0), slug: (string) ($p['slug'] ?? ''), name: (string) ($p['name'] ?? ''),
            isActive: (bool) ($p['is_active'] ?? false), status: $p['status'] ?? null, basePrice: (float) ($p['base_price'] ?? 0),
            categorySlug: $p['category']['slug'] ?? null, categoryName: $p['category']['name'] ?? null,
            variants: array_map(fn ($v) => $this->mapVariant($v), $p['variants'] ?? []),
            imageUrl: $p['image_url'] ?? $p['thumbnail'] ?? null,
            freeDeliveryMinGrams: isset($p['free_delivery_min_grams']) ? (int) $p['free_delivery_min_grams'] : null,
            shortDescription: $p['short_description'] ?? null,
        );
    }

    private function mapVariant(array $v): Variant
    {
        return new Variant(
            id: (int) $v['id'], title: (string) ($v['title'] ?? ''), sku: $v['sku'] ?? null,
            price: (float) ($v['price'] ?? 0), finalPrice: (float) ($v['final_price'] ?? $v['price'] ?? 0),
            stock: isset($v['stock']) ? (int) $v['stock'] : null,
            availableStock: isset($v['available_stock']) ? (int) $v['available_stock'] : (isset($v['stock']) ? (int) $v['stock'] - (int) ($v['reserved_stock'] ?? 0) : null),
            weightGrams: isset($v['weight_grams']) ? (int) $v['weight_grams'] : null,
            isActive: (bool) ($v['is_active'] ?? true),
            discountType: $v['discount_type'] ?? null,
            discountValue: isset($v['discount_value']) ? (float) $v['discount_value'] : null,
            saleEndsAt: $v['sale_ends_at'] ?? null,
        );
    }

    private function mapOrder(array $o): Order
    {
        return new Order(
            ref: (string) ($o['order_number'] ?? $o['ref'] ?? ''),
            status: (string) ($o['order_status'] ?? $o['status'] ?? 'unknown'),
            paymentStatus: $o['payment_status'] ?? null,
            paymentMethod: $o['payment_method'] ?? null,
            grandTotal: (float) ($o['grand_total'] ?? 0),
            placedAt: $o['placed_at'] ?? $o['created_at'] ?? null,
            customerPhone: $o['customer_phone'] ?? null,
            customerName: $o['customer_name'] ?? null,
            lines: array_map(fn ($i) => new OrderLine(
                name: (string) ($i['product_name'] ?? $i['name'] ?? ''), quantity: (int) ($i['qty'] ?? $i['quantity'] ?? 1),
                unitPrice: (float) ($i['unit_price'] ?? 0), total: (float) ($i['total'] ?? 0), sku: $i['sku'] ?? null,
                variantId: isset($i['variant_id']) ? (int) $i['variant_id'] : null, comboId: isset($i['combo_id']) ? (int) $i['combo_id'] : null,
            ), $o['items'] ?? []),
            shipments: $o['shipments'] ?? [],
            source: $o['source'] ?? null,
            raw: $o,
        );
    }

    /** @return array{0: Product, 1: Variant} */
    private function findProductAndVariant(int $variantId): array
    {
        $page = 1;
        do {
            $json = $this->read('/api/v1/assistant/products', ['per_page' => 100, 'page' => $page]);
            foreach ($json['data'] ?? [] as $p) {
                foreach ($p['variants'] ?? [] as $v) {
                    if ((int) $v['id'] === $variantId) {
                        return [$this->mapProduct($p), $this->mapVariant($v)];
                    }
                }
            }
            $last = (int) ($json['meta']['last_page'] ?? 1);
        } while (++$page <= $last && $page <= 20);
        throw new Refused("Variant {$variantId} not found on {$this->store->slug}");
    }
}
