<?php

namespace App\Commerce\Contracts;

use App\Commerce\Dto\Availability;
use App\Commerce\Dto\Capabilities;
use App\Commerce\Dto\Combo;
use App\Commerce\Dto\IdempotencyKey;
use App\Commerce\Dto\Order;
use App\Commerce\Dto\OrderDraft;
use App\Commerce\Dto\OrderQuote;
use App\Commerce\Dto\OrderResult;
use App\Commerce\Dto\Product;
use App\Commerce\Dto\StoreRef;
use App\Commerce\Dto\Variant;

/**
 * The Commerce Platform Interface (ADR-009, docs/15, docs/35 section F).
 *
 * One contract for every store. Prices are ALWAYS computed by the store (preview then create);
 * every write carries an idempotency key; on an uncertain outcome the caller reconciles before
 * retrying (ADR-011). Availability returns the store's own verdict; HQ policy decides the wording.
 * Adapters throw App\Commerce\Exceptions\* and never leak HTTP details to callers.
 */
interface CommercePlatform
{
    public function store(): StoreRef;

    public function capabilities(): Capabilities;

    /** @return list<Product> */
    public function searchProducts(string $query, int $limit = 10): array;

    public function getProduct(string $slug): Product;

    public function getVariant(int $variantId): Variant;

    /** @return list<Combo> */
    public function getCombos(): array;

    public function getAvailability(int $variantId): Availability;

    /** Server-priced quote for a draft; never trust HQ arithmetic. */
    public function previewOrder(OrderDraft $draft): OrderQuote;

    /** Creates the order; returns confirmed, uncertain (reconcile!) or refused. */
    public function createOrder(OrderDraft $draft, IdempotencyKey $key): OrderResult;

    public function getOrder(string $orderRef): Order;

    /** @return list<Order> */
    public function findOrdersByPhone(string $phoneCanonical, int $limit = 10): array;

    /** Orders placed since a moment (admin token); used by reconciliation. @return list<Order> */
    public function listRecentOrders(\DateTimeInterface $since, int $limit = 100): array;

    /**
     * Look for an order the store may already have created for this draft (by idempotency key
     * where the store supports it, else by phone + lines + a time window). Null when nothing matches.
     */
    public function reconcileOrder(IdempotencyKey $key, OrderDraft $draft): ?Order;

    /** Cheap live check that credentials and base URL work; used by the probe command and first use. */
    public function probe(): array;
}
