<?php

namespace App\Tools\Commerce;

use App\Gateway\Descriptor\ToolDescriptor;
use App\Gateway\Identity\ActingIdentity;
use App\Gateway\Policy\PolicyDecision;
use App\Gateway\Policy\ToolPolicy;
use App\Gateway\ToolRequest;
use App\Models\Store;

/**
 * `commerce.cancel_sales_order`'s own policy (docs/79 D: "may only make the decision stricter").
 *
 * It refuses, before an approval is ever parked, a cancellation whose store does not belong to the
 * business it names, or whose reason is not a sentence a person could read in the ERP. It can never
 * loosen anything: the baseline for an R4 write is approval, and the engine ignores any attempt to
 * turn that into an allow.
 */
final class CancelSalesOrderPolicy implements ToolPolicy
{
    public function evaluate(ToolDescriptor $descriptor, ToolRequest $request, array $args, ActingIdentity $identity, PolicyDecision $baseline): PolicyDecision
    {
        $problems = [];

        $store = Store::query()->with('business')->where('slug', (string) ($args['store_slug'] ?? ''))->first();
        if ($store !== null && $store->business?->slug !== ($args['business_slug'] ?? null)) {
            $problems[] = "[store_slug] {$store->slug} belongs to another business than [business_slug] ".($args['business_slug'] ?? 'none').'.';
        }

        $reason = $args['reason'] ?? null;
        if (! is_string($reason) || mb_strlen(trim($reason)) < 3) {
            $problems[] = '[reason] must say, in a sentence, why the order is cancelled; the ERP records it.';
        }

        if ($problems !== []) {
            return PolicyDecision::deny('commerce.cancel_sales_order.shape', 'The cancellation cannot be proposed as it stands: '.implode(' ', $problems));
        }

        return $baseline;
    }
}
