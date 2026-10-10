<?php

namespace App\FollowUps;

use App\Gateway\Descriptor\Channel;
use App\Models\Customer;
use App\Models\Opportunity;
use DateTimeInterface;

/**
 * What a proposer says when proposing a follow-up (docs/113 section 9.2). A plan: an intent in words,
 * a kind (= consent purpose), a channel (required, F9), a due time. Never a message body (F1).
 *
 * @param  array<string, mixed>  $evidence  identifiers only (message uuids, order numbers)
 */
final readonly class FollowUpProposal
{
    public function __construct(
        public Customer $customer,
        public FollowUpKind $kind,
        public Channel $channel,
        public DateTimeInterface $dueAt,
        public string $intent,
        public FollowUpOrigin $origin = FollowUpOrigin::Operator,
        public ?int $brandId = null,
        public ?Opportunity $opportunity = null,
        public ?int $conversationId = null,
        public array $evidence = [],
    ) {}
}
