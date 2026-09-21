<?php

namespace App\Tools\Commerce;

use App\Commerce\Orders\TakeOrderShape;
use App\Gateway\Descriptor\ToolDescriptor;
use App\Gateway\Identity\ActingIdentity;
use App\Gateway\Policy\PolicyDecision;
use App\Gateway\Policy\ToolPolicy;
use App\Gateway\ToolRequest;
use App\Models\Store;

/**
 * `commerce.take_order`'s own policy (docs/79 D: "may only make the decision stricter").
 *
 * It refuses, before an approval is ever parked, an order whose inner shape is wrong
 * (`TakeOrderShape`) or whose store does not belong to the business it names. It can never loosen
 * anything: the baseline for an R4 write is approval, and the engine ignores any attempt to turn
 * that into an allow.
 */
final class TakeOrderPolicy implements ToolPolicy
{
    public function evaluate(ToolDescriptor $descriptor, ToolRequest $request, array $args, ActingIdentity $identity, PolicyDecision $baseline): PolicyDecision
    {
        $problems = TakeOrderShape::problems($args);

        $store = Store::query()->with('business')->where('slug', (string) ($args['store_slug'] ?? ''))->first();
        if ($store !== null && $store->business?->slug !== ($args['business_slug'] ?? null)) {
            $problems[] = "[store_slug] {$store->slug} belongs to another business than [business_slug] ".($args['business_slug'] ?? 'none').'.';
        }

        if ($problems !== []) {
            return PolicyDecision::deny('commerce.take_order.shape', 'The order cannot be proposed as it stands: '.implode(' ', $problems));
        }

        return $baseline;
    }
}
