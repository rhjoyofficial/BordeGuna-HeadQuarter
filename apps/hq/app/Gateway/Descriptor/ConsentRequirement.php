<?php

namespace App\Gateway\Descriptor;

use App\Consent\ConsentPurpose;

/**
 * The consent class the gateway must check before this tool runs (ADR-021, ADR-026).
 *
 * ⛔ The check belongs at the gateway, not inside the tool. A check written into each tool's body is
 * a check that will eventually be forgotten in one of them, and the one it is forgotten in will be
 * the newest channel, written by whoever is in the biggest hurry.
 *
 * `subjectFrom` resolves WHO the consent is about, the same way `CredentialRequirement` resolves
 * which account a credential is for: an argument path, never free text invented at the call site.
 */
final readonly class ConsentRequirement
{
    /**
     * @param  ConsentPurpose  $purpose  which grant must be live
     * @param  Channel|null  $channel  null means "the descriptor's own channel"
     * @param  string  $subjectFrom  argument path to the customer id, e.g. `args.customer_id`
     */
    public function __construct(
        public ConsentPurpose $purpose,
        public ?Channel $channel = null,
        public string $subjectFrom = 'args.customer_id',
    ) {}

    public function toArray(): array
    {
        return [
            'purpose' => $this->purpose->value,
            'channel' => $this->channel?->value,
            'subject_from' => $this->subjectFrom,
        ];
    }
}
