<?php

namespace App\Commerce\Orders;

/**
 * Does the ERP's order say what the approved draft said, and is it WAITING? (docs/88 A point 3,
 * docs/79 C.16 and C.17, ADR-031's postcondition.)
 *
 * Read back by HQ's own key after `POST sales-orders`, the order must be the draft to the paisa,
 * must name the draft it came from and the proposal a person approved, must be `open` with its ERP
 * approval `pending`, and must state that HQ approved nothing and that no stock, journal, payment
 * or courier followed. A 201 proves none of that; the read-back does.
 *
 * ⛔ IT REPORTS AND NEVER REPAIRS, exactly as `DraftReconciliation` does: a disagreement is returned
 * with both sides named and the call is `uncertain` for a person. Nothing here writes anywhere.
 */
final class OrderReconciliation
{
    /**
     * @param  array<string, mixed>  $proposed  the executed arguments of `commerce.take_order`
     * @param  array<string, mixed>  $order  the ERP's order body (`data`), read back
     * @param  string  $draftKey  the ERP key of the draft the order was made from
     * @param  string  $fingerprint  the proposal fingerprint the draft was written for
     * @return list<array{field: string, proposed: mixed, erp: mixed, note: string}>
     */
    public function compare(array $proposed, array $order, string $draftKey, string $fingerprint): array
    {
        $out = [];

        if (($order['status'] ?? null) !== 'open') {
            $out[] = $this->row('status', 'open', $order['status'] ?? null, 'The order is not open.');
        }
        if (($order['approval_status'] ?? null) !== 'pending') {
            $out[] = $this->row('approval_status', 'pending', $order['approval_status'] ?? null, 'The order is not waiting for the ERP\'s approval.');
        }
        if (($order['deliverable'] ?? null) !== false) {
            $out[] = $this->row('deliverable', false, $order['deliverable'] ?? null, 'The ERP calls the order deliverable before a person approved it.');
        }
        if (($order['hq']['draft_key'] ?? null) !== $draftKey) {
            $out[] = $this->row('hq.draft_key', $draftKey, $order['hq']['draft_key'] ?? null, 'The order was made from a different draft.');
        }
        if (($order['hq']['proposal_fingerprint'] ?? null) !== $fingerprint) {
            $out[] = $this->row('hq.proposal_fingerprint', $fingerprint, $order['hq']['proposal_fingerprint'] ?? null, 'The order is for a different proposal.');
        }
        if (($order['store']['key'] ?? null) !== ($proposed['erp_store_key'] ?? null)) {
            $out[] = $this->row('store', $proposed['erp_store_key'] ?? null, $order['store']['key'] ?? null, 'A different store.');
        }
        if (($order['payment_method'] ?? null) !== ($proposed['payment_method'] ?? null)) {
            $out[] = $this->row('payment_method', $proposed['payment_method'] ?? null, $order['payment_method'] ?? null, 'A different payment method.');
        }

        $erpLines = [];
        foreach ((array) ($order['lines'] ?? []) as $line) {
            $erpLines[(string) ($line['sku'] ?? '')] = $line;
        }
        $seen = [];
        foreach ((array) ($proposed['lines'] ?? []) as $line) {
            $sku = (string) ($line['sku'] ?? '');
            $seen[$sku] = true;
            $theirs = $erpLines[$sku] ?? null;
            if ($theirs === null) {
                $out[] = $this->row("lines.{$sku}", $line, null, 'Proposed, and not on the ERP order.');

                continue;
            }
            if ((int) ($line['quantity'] ?? 0) !== (int) ($theirs['quantity'] ?? -1)) {
                $out[] = $this->row("lines.{$sku}.quantity", $line['quantity'] ?? null, $theirs['quantity'] ?? null, 'A different quantity.');
            }
            if (self::scaled((string) ($line['unit_price'] ?? ''), 4) !== self::scaled((string) ($theirs['unit_price'] ?? ''), 4)) {
                $out[] = $this->row("lines.{$sku}.unit_price", $line['unit_price'] ?? null, $theirs['unit_price'] ?? null, 'A different price.');
            }
        }
        foreach ($erpLines as $sku => $line) {
            if (! isset($seen[$sku])) {
                $out[] = $this->row("lines.{$sku}", null, $line, 'On the ERP order, and never proposed.');
            }
        }

        foreach (['goods_total', 'delivery_charge', 'agreed_total'] as $total) {
            $mine = (string) ($proposed['proposed'][$total] ?? '');
            $theirs = (string) ($order[$total] ?? '');
            if (self::scaled($mine, 2) !== self::scaled($theirs, 2)) {
                $out[] = $this->row($total, $mine, $theirs, 'A different total.');
            }
        }

        $effects = (array) ($order['effects'] ?? []);
        foreach (['approved_by_hq', 'stock_moved', 'journal_posted', 'payment_taken', 'courier_booked'] as $effect) {
            if (($effects[$effect] ?? null) !== false) {
                $out[] = $this->row("effects.{$effect}", false, $effects[$effect] ?? null, 'The ERP reports an effect an order made from a draft must not have.');
            }
        }

        return $out;
    }

    /** @return array{field: string, proposed: mixed, erp: mixed, note: string} */
    private function row(string $field, mixed $proposed, mixed $erp, string $note): array
    {
        return ['field' => $field, 'proposed' => $proposed, 'erp' => $erp, 'note' => $note];
    }

    /** A decimal as a fixed-place string, compared as text: no floating point anywhere near money. */
    private static function scaled(string $decimal, int $places): string
    {
        [$whole, $fraction] = array_pad(explode('.', trim($decimal), 2), 2, '');
        $whole = ltrim($whole, '0');

        return ($whole === '' ? '0' : $whole).'.'.str_pad(substr($fraction, 0, $places), $places, '0');
    }
}
