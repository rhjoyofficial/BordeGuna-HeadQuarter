<?php

namespace App\Commerce\Orders;

use App\Gateway\Approval\CanonicalPayload;
use App\Identity\PhoneCanonical;

/**
 * Does the ERP's draft say what was proposed, and was what was proposed what a person approved?
 * (docs/83 C.3, ADR-031's reconciliation step, docs/79 C.17.)
 *
 * Three records of one order, compared field by field:
 *
 *   proposed order    the arguments `commerce.take_order` executed
 *   approved intent   the approval's payload hash, when an approval ran it
 *   ERP draft         what the ERP holds under the key, read back from the ERP
 *
 * ⛔ IT REPORTS AND NEVER REPAIRS. A discrepancy is returned with both sides named; nothing here
 * writes to HQ or to the ERP, and nothing downstream may "fix" one side to match the other. A draft
 * that disagrees with its proposal is `uncertain` for a person to read, which is exactly the state
 * `FactsIntegrityChecker` puts a wrong order line in, and for the same reason.
 */
final class DraftReconciliation
{
    /**
     * @param  array<string, mixed>  $proposed  the executed arguments of `commerce.take_order`
     * @param  array<string, mixed>  $erpDraft  the ERP's draft body (`data`)
     * @param  string|null  $approvedHash  the approval's payload hash, or null when no approval ran it
     * @return list<array{field: string, proposed: mixed, erp: mixed, note: string}>
     */
    public function compare(array $proposed, array $erpDraft, ?string $approvedHash = null): array
    {
        $out = [];
        $fingerprint = CanonicalPayload::hash($proposed);

        if ($approvedHash !== null && ! hash_equals($approvedHash, $fingerprint)) {
            $out[] = $this->row('approval', $fingerprint, $approvedHash, 'The executed proposal is not the one the approval was given for.');
        }
        if (($erpDraft['hq_proposal_fingerprint'] ?? null) !== $fingerprint) {
            $out[] = $this->row('hq_proposal_fingerprint', $fingerprint, $erpDraft['hq_proposal_fingerprint'] ?? null, 'The ERP holds this draft for a different proposal.');
        }
        if (($erpDraft['status'] ?? null) !== 'draft') {
            $out[] = $this->row('status', 'draft', $erpDraft['status'] ?? null, 'The ERP record is not a draft.');
        }
        if (($erpDraft['store']['key'] ?? null) !== ($proposed['erp_store_key'] ?? null)) {
            $out[] = $this->row('store', $proposed['erp_store_key'] ?? null, $erpDraft['store']['key'] ?? null, 'A different store.');
        }
        if (($erpDraft['payment_method'] ?? null) !== ($proposed['payment_method'] ?? null)) {
            $out[] = $this->row('payment_method', $proposed['payment_method'] ?? null, $erpDraft['payment_method'] ?? null, 'A different payment method.');
        }

        $phone = PhoneCanonical::normalize((string) ($proposed['destination']['phone'] ?? ''));
        if ($phone !== ($erpDraft['recipient']['phone_normalized'] ?? null)) {
            $out[] = $this->row('destination.phone', $phone, $erpDraft['recipient']['phone_normalized'] ?? null, 'A different phone number.');
        }
        foreach (['district', 'thana'] as $part) {
            $mine = mb_strtolower(trim((string) ($proposed['destination'][$part] ?? '')));
            $theirs = mb_strtolower(trim((string) ($erpDraft['destination'][$part] ?? '')));
            if ($mine !== $theirs) {
                $out[] = $this->row("destination.{$part}", $proposed['destination'][$part] ?? null, $erpDraft['destination'][$part] ?? null, "A different {$part}.");
            }
        }

        $erpLines = [];
        foreach ((array) ($erpDraft['lines'] ?? []) as $line) {
            $erpLines[(string) ($line['sku'] ?? '')] = $line;
        }
        $seen = [];
        foreach ((array) ($proposed['lines'] ?? []) as $line) {
            $sku = (string) ($line['sku'] ?? '');
            $seen[$sku] = true;
            $theirs = $erpLines[$sku] ?? null;
            if ($theirs === null) {
                $out[] = $this->row("lines.{$sku}", $line, null, 'Proposed, and not in the ERP draft.');

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
                $out[] = $this->row("lines.{$sku}", null, $line, 'In the ERP draft, and never proposed.');
            }
        }

        foreach (['goods_total', 'delivery_charge', 'agreed_total'] as $total) {
            $mine = (string) ($proposed['proposed'][$total] ?? '');
            $theirs = (string) ($erpDraft[$total] ?? '');
            if (self::scaled($mine, 2) !== self::scaled($theirs, 2)) {
                $out[] = $this->row($total, $mine, $theirs, 'A different total.');
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
