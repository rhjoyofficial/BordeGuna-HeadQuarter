<?php

namespace App\Observe\Alerts\Rules;

use App\Models\ToolCall;
use App\Observe\Alerts\AlertCondition;
use App\Observe\Alerts\AlertRule;
use Carbon\CarbonImmutable;

/**
 * The latest order-fact integrity check found a discrepancy (docs/48 N.5, docs/44).
 *
 * Reads the result of the daily `commerce.check_fact_integrity` job rather than repeating the check:
 * the job is the record, and a failing job is already on the scheduler's failed-runs card.
 */
final class FactIntegrityDiscrepancy implements AlertRule
{
    public const TOOL = 'commerce.check_fact_integrity';

    public function key(): string
    {
        return 'facts.integrity_discrepancy';
    }

    public function pagedBy(): ?string
    {
        return null;
    }

    public function evaluate(CarbonImmutable $now): ?array
    {
        $latest = ToolCall::query()->where('tool_key', self::TOOL)->where('status', 'succeeded')->latest('id')->first();

        if ($latest === null) {
            return [];
        }

        $found = (int) ($latest->output['discrepancies'] ?? 0);

        if ($found === 0) {
            return [];
        }

        return [new AlertCondition('latest', 'warning', "The order-fact check found {$found} discrepancy(ies)",
            'Checked '.(int) ($latest->output['checked'] ?? 0).' fact(s) for '.($latest->output['store'] ?? 'all stores')
            .'. Reported, not repaired: run php artisan hq:facts:check to see them, and decide which side is right.',
            ['tool_call_id' => $latest->id, 'discrepancies' => $found, 'checked' => (int) ($latest->output['checked'] ?? 0)])];
    }
}
