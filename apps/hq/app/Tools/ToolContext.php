<?php

namespace App\Tools;

final readonly class ToolContext
{
    public function __construct(
        public string $idempotencyKey,
        public string $correlationId,
        public ?int $businessId = null,
        public ?int $brandId = null,
        public ?int $jobRunId = null,
        public ?int $actorUserId = null,
        public int $attempt = 1,
        public string $environment = 'production',
        /**
         * The businesses this caller may read, or null for group-wide (M5).
         *
         * ⛔ **Computed by the gateway, never by a tool.** `BusinessScope`'s own docblock says that
         * an HQ-scoped tool reading across businesses must restrict itself - which is correct, and
         * is also a rule that will eventually be forgotten in one tool, and the one it is forgotten
         * in will be the newest. The gateway cannot apply the filter (only the tool knows its own
         * query), but it CAN make forgetting to look it up impossible, so it resolves the list once
         * and hands it over.
         *
         * Null means unrestricted and is the shipped state: HQ has one operator and he holds no
         * scope rows (V.11). This exists so the first restricted user is not also the first test of
         * whether every tool remembered.
         */
        public ?array $permittedBusinessIds = null,
    ) {}

    /** Applies the caller's business restriction to a query, or leaves it alone when unrestricted. */
    public function restrictBusinesses(mixed $query, string $column = 'business_id'): mixed
    {
        return $this->permittedBusinessIds === null
            ? $query
            : $query->whereIn($column, $this->permittedBusinessIds);
    }
}
