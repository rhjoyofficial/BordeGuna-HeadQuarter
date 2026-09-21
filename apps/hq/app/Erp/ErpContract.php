<?php

namespace App\Erp;

use App\Commerce\Exceptions\Unsupported;

/**
 * The few assertions that separate "the ERP said nothing" from "the ERP said nothing was there".
 *
 * `$response['data'] ?? []` reads the same for both, and they are opposite facts: an empty list is
 * the ERP reporting zero low-stock products, while a missing `data` key is the ERP no longer
 * speaking the language HQ was written against. The first is news worth storing; the second is a
 * broken integration that would otherwise be stored as good news.
 *
 * This is deliberately four small functions and not a schema framework. Only fields whose absence
 * changes the meaning of what gets persisted are checked - everything genuinely optional keeps its
 * `?? null`, because making an integration brittle is not the same as making it observable.
 *
 * A violation raises Unsupported rather than Unavailable, because retrying will not help: the
 * payload is the wrong shape, and it will still be the wrong shape in sixty seconds. The scheduler
 * therefore records a failure and stops, which is the outcome that gets a person to look.
 */
final class ErpContract
{
    /**
     * The response envelope must carry `data`. Every ERP read endpoint returns `{data, meta}`;
     * without `data` there is nothing to persist, and persisting the empty default would record a
     * confident, wrong answer.
     */
    public static function requireData(array $response, string $operation): mixed
    {
        if (! array_key_exists('data', $response)) {
            throw new Unsupported("ERP {$operation}: the response has no `data` key, so it is not the contract HQ reads. Keys present: ".self::keys($response));
        }

        return $response['data'];
    }

    /** @return array<mixed> */
    public static function requireDataArray(array $response, string $operation): array
    {
        $data = self::requireData($response, $operation);
        if (! is_array($data)) {
            throw new Unsupported("ERP {$operation}: `data` is ".get_debug_type($data).', not an array.');
        }

        return $data;
    }

    /**
     * The overview's sections are nullable on purpose - a null section means the token lacks that
     * ability, which is a permission boundary rather than a fault. What is not optional is that the
     * sections HQ knows about are the sections the ERP sends: a section that quietly disappears
     * from the payload would be indistinguishable from one the token cannot see.
     *
     * @param  list<string>  $sections
     */
    public static function requireSections(array $data, array $sections, string $operation): void
    {
        $missing = array_values(array_filter($sections, fn (string $s) => ! array_key_exists($s, $data)));
        if ($missing !== []) {
            throw new Unsupported("ERP {$operation}: the response is missing section(s) [".implode(', ', $missing).'], which HQ cannot tell apart from a permission boundary. Keys present: '.self::keys($data));
        }
    }

    /**
     * The ERP reports how many rows it meant to send. When it does, a row count that disagrees with
     * it means something was lost between the two systems, and an empty list that the ERP says
     * should have had rows in it is exactly the shape of a silent data loss.
     */
    public static function requireRowCount(array $rows, array $meta, string $key, string $operation): void
    {
        if (! array_key_exists($key, $meta) || ! is_int($meta[$key])) {
            return; // the ERP did not claim a count, so there is nothing to disagree with
        }
        if (count($rows) !== $meta[$key]) {
            throw new Unsupported("ERP {$operation}: meta.{$key} says {$meta[$key]} row(s) but the response carried ".count($rows).'.');
        }
    }

    /**
     * An order-read body must say which contract it speaks and which business it answers for.
     *
     * A body from another contract version is a changed interface, and one for another business is
     * a scoping fault at the ERP; either read as an answer would put another shape, or another
     * business's product and price, into a reply about this one. Both refuse, loudly.
     */
    public static function requireOrderReadsContract(array $meta, string $contract, int $erpBusinessId, string $operation): void
    {
        $spoken = $meta['contract'] ?? null;
        if ($spoken !== $contract) {
            throw new Unsupported("ERP {$operation}: the response speaks contract [".(is_scalar($spoken) ? (string) $spoken : 'none')."], and HQ reads [{$contract}].");
        }
        if (array_key_exists('business_id', $meta) && (int) $meta['business_id'] !== $erpBusinessId) {
            throw new Unsupported("ERP {$operation}: the response is for business {$meta['business_id']}, and this connector asked for business {$erpBusinessId}.");
        }
    }

    private static function keys(array $a): string
    {
        return $a === [] ? '(none)' : implode(', ', array_keys($a));
    }
}
