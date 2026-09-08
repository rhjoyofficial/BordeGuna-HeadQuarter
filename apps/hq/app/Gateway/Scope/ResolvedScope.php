<?php

namespace App\Gateway\Scope;

/**
 * The business and store a call actually targets, after resolving the request's context AND the
 * arguments the tool declared as carrying its target.
 *
 * ⚠ This exists because of a defect found while preparing M2, before any code was written. The five
 * live scheduled jobs pass their target in the PAYLOAD (`business_slug`, `store_slug`) and leave the
 * request's business and store null: that is how they were written in Phase 1, long before a gateway
 * existed. M1's scope check only looked at the request, so routing the scheduler through the gateway
 * unchanged would have DENIED four of the six production jobs on their first run.
 *
 * The honest fix is not to loosen the check. A business-scoped tool must still name a business; what
 * changed is the recognition that naming it in `business_slug` **is** naming it. The descriptor now
 * declares where its target comes from, exactly as it already declares where a credential's account
 * reference comes from.
 *
 * A useful side effect: `tool_calls.business_id` and `store_id` are now filled for the ERP polls and
 * the reconciles, which would otherwise have recorded null for every one of them.
 */
final readonly class ResolvedScope
{
    public function __construct(
        public ?int $businessId = null,
        public ?string $businessSlug = null,
        public ?int $storeId = null,
        public ?string $storeSlug = null,
        /** True when the tool needed a target and none could be resolved from either source. */
        public bool $missing = false,
        public ?string $problem = null,
    ) {}

    public static function missing(string $problem): self
    {
        return new self(missing: true, problem: $problem);
    }

    public function named(): bool
    {
        return $this->businessId !== null || $this->storeId !== null;
    }
}
