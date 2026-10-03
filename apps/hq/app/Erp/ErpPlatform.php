<?php

namespace App\Erp;

use App\Erp\Dto\ErpLowStock;
use App\Erp\Dto\ErpOrderRead;
use App\Erp\Dto\ErpOverview;

/**
 * What HQ's core logic needs from an ERP, for one business (docs/101 §11 ruling 9.2: "extract the
 * interface NOW; core logic depends on the interface, not on `ErpConnector`").
 *
 * The contract behind the `erp.read` capability (docs/98 §2.1). Tools, facts, commands, readiness and
 * `ErpRegistry` name this, never the concrete client: `ErpConnector` is today's only implementation
 * (the Bor de Guna ERP's HQ API), and `ErpPlatformStructureTest` fails the suite when anything outside
 * `app/Erp` names it.
 *
 * ⚠ Extracted, not redesigned: the methods are exactly the public methods core logic already called,
 * with the same signatures, DTOs and failure classes (`Unavailable`, `Unsupported`, `ValidationFailed`,
 * `Refused`, `Uncertain` from `App\Commerce\Exceptions`). An implementation must keep those meanings:
 * a write whose outcome is unknown is `Uncertain` and is settled by the matching read-back by key,
 * never by sending again. Methods core logic does not use (customers, tickets, the raw report list)
 * stay on the concrete class until something needs them.
 *
 * The three contract versions are HQ's normalized vocabulary for order bodies (`meta.contract`):
 * facts and tools compare against them, so a second ERP adapter answers in those shapes too.
 */
interface ErpPlatform
{
    /** The version stamped on every order-read body (`meta.contract`), docs/81 C.5. */
    public const ORDER_READS_CONTRACT = 'hq-order-reads.v1';

    /** The version stamped on every draft sales order body (`meta.contract`), docs/83 C.3. */
    public const SALES_ORDER_DRAFTS_CONTRACT = 'hq-sales-order-drafts.v1';

    /** The version stamped on every sales-order body: quote, order, read-back, cancel (docs/88 A point 3). */
    public const SALES_ORDERS_CONTRACT = 'hq-sales-orders.v1';

    // ---- Polled facts --------------------------------------------------------------------------

    public function overview(): ErpOverview;

    public function lowStock(): ErpLowStock;

    // ---- Order reads (read only) ---------------------------------------------------------------

    /** @param  array<string, string|int|null>  $filter */
    public function lookupProducts(array $filter = [], int $perPage = 20, int $page = 1): ErpOrderRead;

    public function lookupSku(string $sku): ErpOrderRead;

    public function readPrice(string $sku, string $quantity = '1', ?string $storeKey = null, ?int $customerId = null, ?string $on = null): ErpOrderRead;

    public function readAvailability(string $sku, ?string $storeKey = null): ErpOrderRead;

    public function checkCustomerEligibility(string $phone): ErpOrderRead;

    // ---- Draft, order, cancel, and the read-backs that settle an uncertain write ---------------

    /** @param  array<string, mixed>  $draft  the request body, `idempotency_key` included */
    public function createSalesOrderDraft(array $draft): ErpOrderRead;

    public function readSalesOrderDraft(string $idempotencyKey): ErpOrderRead;

    /** @param  array<string, mixed>  $quote */
    public function quoteSalesOrder(array $quote): ErpOrderRead;

    /** @param  array<string, mixed>  $order */
    public function recordSalesOrder(array $order): ErpOrderRead;

    public function readSalesOrder(string $reference): ErpOrderRead;

    public function readSalesOrderByKey(string $idempotencyKey): ErpOrderRead;

    /** @param  array<string, mixed>  $cancellation */
    public function cancelSalesOrder(string $reference, array $cancellation): ErpOrderRead;

    // ---- Reports, audit and liveness ------------------------------------------------------------

    /** @return array<mixed> */
    public function runReport(string $key, array $parameters = [], int $limit = 500): array;

    /**
     * The unmapped envelope for one of a fixed list of read endpoints, for auditing what the ERP sends.
     *
     * @return array{data?: mixed, meta?: array}
     */
    public function rawRead(string $endpoint): array;

    /** Cheap liveness check: can this token see its own business? Never throws. */
    public function probe(): array;
}
