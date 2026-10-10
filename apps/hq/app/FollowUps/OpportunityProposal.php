<?php

namespace App\FollowUps;

use App\Models\Customer;
use DateTimeInterface;

/**
 * What a proposer says when proposing an opportunity (docs/113 section 9.1): a kind, a subject in
 * words, an expected date. No amount, price or quantity (F2).
 *
 * @param  array<string, mixed>  $evidence  identifiers only
 */
final readonly class OpportunityProposal
{
    public function __construct(
        public Customer $customer,
        public OpportunityKind $kind,
        public string $subject,
        public ?DateTimeInterface $expectedAt = null,
        public ?int $brandId = null,
        public ?int $conversationId = null,
        public array $evidence = [],
    ) {}
}
