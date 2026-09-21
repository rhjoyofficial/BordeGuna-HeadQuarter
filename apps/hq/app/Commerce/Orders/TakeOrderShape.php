<?php

namespace App\Commerce\Orders;

use App\Identity\PhoneCanonical;

/**
 * The inner shape of a `commerce.take_order` proposal (docs/83 C.3, docs/79 C.0 "StructuredOrder").
 *
 * The gateway's `ArgumentValidator` checks the top level only (it refuses a nested schema keyword
 * rather than ignoring one), so the destination, the lines and the proposed totals are checked here,
 * by `TakeOrderPolicy` BEFORE an approval is ever parked, and again by the tool before anything is
 * sent. A malformed order is a sentence for the operator; it never reaches a person's approval
 * inbox, and it never reaches the ERP.
 *
 * ⚠ The rules are the ERP's own rules for a draft, restated so HQ can refuse early; the ERP checks
 * them again and is the authority. Nothing here prices, rounds or completes anything.
 */
final class TakeOrderShape
{
    public const DESTINATION_KEYS = ['name', 'phone', 'district', 'thana', 'area', 'address_line', 'landmark'];

    public const DESTINATION_REQUIRED = ['name', 'phone', 'district', 'thana', 'address_line'];

    public const LINE_KEYS = ['sku', 'quantity', 'unit_price'];

    public const TOTAL_KEYS = ['goods_total', 'delivery_charge', 'agreed_total'];

    /** The website's ceiling per line, which the ERP's draft keeps (`CartRules::MAX_QUANTITY`). */
    public const MAX_QUANTITY = 99;

    public const MAX_LINES = 20;

    private const PRICE = '/^\d{1,11}(\.\d{1,4})?$/';

    private const AMOUNT = '/^\d{1,13}(\.\d{1,2})?$/';

    /**
     * @param  array<string, mixed>  $args  the validated top-level arguments
     * @return list<string> what is wrong, in sentences; empty when the shape is sound
     */
    public static function problems(array $args): array
    {
        $out = [];

        $destination = $args['destination'] ?? null;
        if (! is_array($destination) || array_is_list($destination)) {
            $out[] = '[destination] must be an object with name, phone, district, thana and address_line.';
        } else {
            foreach (array_diff(array_keys($destination), self::DESTINATION_KEYS) as $extra) {
                $out[] = "[destination.{$extra}] is not taken on an order.";
            }
            foreach (self::DESTINATION_REQUIRED as $key) {
                if (! is_string($destination[$key] ?? null) || trim($destination[$key]) === '') {
                    $out[] = "[destination.{$key}] is required.";
                }
            }
            foreach (self::DESTINATION_KEYS as $key) {
                if (isset($destination[$key]) && (! is_string($destination[$key]) || mb_strlen($destination[$key]) > 255)) {
                    $out[] = "[destination.{$key}] must be text of at most 255 characters.";
                }
            }
            if (is_string($destination['phone'] ?? null) && ! PhoneCanonical::isValid($destination['phone'])) {
                $out[] = '[destination.phone] is not a Bangladesh mobile number (eleven digits starting 013 to 019).';
            }
        }

        $lines = $args['lines'] ?? null;
        if (! is_array($lines) || ! array_is_list($lines) || $lines === []) {
            $out[] = '[lines] must be a list of at least one line.';
        } elseif (count($lines) > self::MAX_LINES) {
            $out[] = '[lines] may hold at most '.self::MAX_LINES.' lines.';
        } else {
            $skus = [];
            foreach ($lines as $i => $line) {
                if (! is_array($line) || array_is_list($line)) {
                    $out[] = "[lines.{$i}] must be an object with sku, quantity and unit_price.";

                    continue;
                }
                foreach (array_diff(array_keys($line), self::LINE_KEYS) as $extra) {
                    $out[] = "[lines.{$i}.{$extra}] is not taken on an order line.";
                }
                $sku = $line['sku'] ?? null;
                if (! is_string($sku) || $sku === '' || mb_strlen($sku) > 64) {
                    $out[] = "[lines.{$i}.sku] must be a SKU.";
                } elseif (isset($skus[$sku])) {
                    $out[] = "[lines.{$i}.sku] {$sku} appears twice; name each product once with its whole quantity.";
                } else {
                    $skus[$sku] = true;
                }
                $quantity = $line['quantity'] ?? null;
                if (! is_int($quantity) || $quantity < 1 || $quantity > self::MAX_QUANTITY) {
                    $out[] = "[lines.{$i}.quantity] must be a whole number from 1 to ".self::MAX_QUANTITY.'.';
                }
                if (! is_string($line['unit_price'] ?? null) || preg_match(self::PRICE, $line['unit_price']) !== 1) {
                    $out[] = "[lines.{$i}.unit_price] must be the ERP's quoted price as a decimal string.";
                }
            }
        }

        $proposed = $args['proposed'] ?? null;
        if (! is_array($proposed) || array_is_list($proposed)) {
            $out[] = '[proposed] must be an object with goods_total, delivery_charge and agreed_total.';
        } else {
            foreach (array_diff(array_keys($proposed), self::TOTAL_KEYS) as $extra) {
                $out[] = "[proposed.{$extra}] is not a total an order carries.";
            }
            foreach (self::TOTAL_KEYS as $key) {
                if (! is_string($proposed[$key] ?? null) || preg_match(self::AMOUNT, $proposed[$key]) !== 1) {
                    $out[] = "[proposed.{$key}] must be the ERP's quoted amount as a decimal string.";
                }
            }
        }

        return $out;
    }
}
