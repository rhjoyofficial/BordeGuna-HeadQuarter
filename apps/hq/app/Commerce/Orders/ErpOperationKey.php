<?php

namespace App\Commerce\Orders;

/**
 * The key HQ sends the ERP for one operation of one gateway call (docs/83 C.3, docs/88 A point 3).
 *
 * The gateway's idempotency key (for an approved call, `approval:{uuid}`) names the logical
 * operation; the business is folded in so two businesses can never collide on one key; and a STEP
 * names which ERP write of a multi-step tool the key is for, so the draft and the order that
 * `take_order` writes from it are two keys the ERP tells apart, each replayable on its own.
 *
 * ⚠ Deterministic on purpose: `reconcile()` must find, by the same arithmetic, exactly what an
 * uncertain call sent. The draft key has no step, so every draft the earlier stage wrote keeps its key.
 */
final class ErpOperationKey
{
    public const PREFIX = 'hq:';

    public static function for(string $gatewayKey, int $businessId, string $step = ''): string
    {
        $material = $gatewayKey.'|business:'.$businessId.($step === '' ? '' : '|'.$step);

        return self::PREFIX.hash('sha256', $material);
    }

    /** The approval an `approval:{uuid}` gateway key was run for, or null for a direct call. */
    public static function approvalUuid(string $gatewayKey): ?string
    {
        return str_starts_with($gatewayKey, 'approval:') ? substr($gatewayKey, strlen('approval:')) : null;
    }
}
