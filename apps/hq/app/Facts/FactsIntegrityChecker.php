<?php

namespace App\Facts;

use App\Models\FactsOrder;
use App\Models\Store;
use Carbon\CarbonImmutable;

/**
 * Reads order facts back and asks whether they add up.
 *
 * The LaLaDia defect was invisible for two days because nothing ever threw: the mapper read a key
 * that was not there, stored 0.00, and 0.00 is a perfectly valid price. No exception, no failed
 * job, no red test. What it could never have survived is arithmetic - the lines said 0 and the
 * order said 999, and those two numbers came from the same payload.
 *
 * So this checks the relationships a correct order fact must satisfy, and only those. It is
 * deliberately not a schema validator: a fact can be missing anything and still be honest about
 * money, and money is what a wrong integration silently damages first.
 *
 * ⚠ It reports and never repairs. Money that disagrees with itself needs a person to decide which
 * side is right - HQ knowing that a number is wrong is not the same as HQ knowing what it should
 * be, and a checker that quietly "fixes" the difference destroys the only evidence of the bug.
 */
final class FactsIntegrityChecker
{
    /** Money is stored to two decimals; anything within half a paisa is the same number. */
    private const EPSILON = 0.005;

    public const NO_LINES = 'no_line_detail';

    public const LINES_NOT_SUBTOTAL = 'line_total_sum_ne_subtotal';

    public const LINE_ARITHMETIC = 'line_qty_x_unit_price_ne_line_total';

    public const ZERO_PRICED_LINE = 'zero_priced_line_on_paid_order';

    public const TOTALS = 'subtotal_plus_shipping_minus_discount_ne_gross';

    /**
     * @return array{
     *     checked: int, ok: int, without_lines: int, discrepancies: int,
     *     findings: list<array{order_ref: string, store: string, code: string, detail: string}>
     * }
     */
    public function check(?Store $store, CarbonImmutable $since, int $limit = 1000): array
    {
        $facts = FactsOrder::query()
            ->when($store, fn ($q) => $q->where('store_id', $store->id))
            ->where(fn ($q) => $q->where('placed_at', '>=', $since)->orWhereNull('placed_at'))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $findings = [];
        $withoutLines = 0;
        $clean = 0;

        foreach ($facts as $fact) {
            $storeSlug = $fact->store?->slug ?? (string) $fact->store_id;
            $before = count($findings);

            // Totals hold for every order, with lines or without.
            $expected = (float) $fact->subtotal + (float) $fact->shipping_cost - (float) $fact->discount_total;
            if (abs($expected - (float) $fact->gross) > self::EPSILON) {
                $findings[] = $this->finding($fact, $storeSlug, self::TOTALS,
                    sprintf('subtotal %.2f + shipping %.2f - discount %.2f = %.2f, but gross is %.2f',
                        $fact->subtotal, $fact->shipping_cost, $fact->discount_total, $expected, $fact->gross));
            }

            $lines = is_array($fact->lines) ? $fact->lines : null;
            if ($lines === null || $lines === []) {
                // Not a discrepancy: reconciliation inserts orders the admin list endpoint gives no
                // items for. Counted separately so the absence stays visible instead of passing as ok.
                $withoutLines++;
                $clean += count($findings) === $before ? 1 : 0;

                continue;
            }

            $sum = 0.0;
            foreach ($lines as $i => $line) {
                $qty = (int) ($line['qty'] ?? 0);
                $unitPrice = (float) ($line['unit_price'] ?? 0);
                $total = (float) ($line['total'] ?? 0);
                $sum += $total;

                if (abs($qty * $unitPrice - $total) > self::EPSILON) {
                    $findings[] = $this->finding($fact, $storeSlug, self::LINE_ARITHMETIC,
                        sprintf('line %d (%s): qty %d x unit price %.2f = %.2f, but line total is %.2f',
                            $i, $line['sku'] ?? '?', $qty, $unitPrice, $qty * $unitPrice, $total));
                }

                // The shape of the defect this checker was built for: an order that charged money
                // whose lines claim to be free.
                if ($unitPrice <= 0.0 && (float) $fact->subtotal > 0.0) {
                    $findings[] = $this->finding($fact, $storeSlug, self::ZERO_PRICED_LINE,
                        sprintf('line %d (%s) has unit price 0.00 on an order with subtotal %.2f',
                            $i, $line['sku'] ?? '?', $fact->subtotal));
                }
            }

            if (abs($sum - (float) $fact->subtotal) > self::EPSILON) {
                $findings[] = $this->finding($fact, $storeSlug, self::LINES_NOT_SUBTOTAL,
                    sprintf('line totals sum to %.2f, but subtotal is %.2f', $sum, $fact->subtotal));
            }

            $clean += count($findings) === $before ? 1 : 0;
        }

        return [
            'checked' => $facts->count(),
            'ok' => $clean,
            'without_lines' => $withoutLines,
            'discrepancies' => count($findings),
            'findings' => $findings,
        ];
    }

    private function finding(FactsOrder $fact, string $storeSlug, string $code, string $detail): array
    {
        return ['order_ref' => $fact->order_ref, 'store' => $storeSlug, 'code' => $code, 'detail' => $detail];
    }
}
