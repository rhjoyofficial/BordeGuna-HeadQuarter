<?php

namespace App\Consent;

use App\Gateway\Descriptor\ConsentRequirement;
use App\Gateway\Descriptor\ToolDescriptor;
use App\Gateway\ToolRequest;

/**
 * The gateway's consent check (ADR-021).
 *
 * ⛔ **Here, not inside each tool.** A check written into each tool's body is a check that will
 * eventually be forgotten in one of them, and the one it is forgotten in will be the newest
 * channel, written by whoever is in the biggest hurry. The descriptor declares which consent class
 * applies (`ConsentRequirement`); this reads the declaration and consults the ledger.
 *
 * The subject is resolved from the ARGUMENTS by the declared path, never from free text invented at
 * the call site, exactly as `CredentialResolver` resolves an account reference.
 *
 * Fail-closed in every branch: a tool that declares a consent requirement and whose subject cannot
 * be resolved is refused, because "we could not tell who this is about" and "they agreed" must
 * never produce the same outcome.
 */
final readonly class ConsentGate
{
    public function __construct(private ConsentLedger $ledger) {}

    /** @return ConsentDecision permitted, or refused with the reason and the deciding event */
    public function check(ToolDescriptor $descriptor, ToolRequest $request, array $args, ?int $businessId): ConsentDecision
    {
        $requirement = $descriptor->consentRequired;

        if ($requirement === null) {
            return ConsentDecision::notRequired();
        }

        if ($businessId === null) {
            return ConsentDecision::unrecorded(
                "[{$descriptor->key}] requires consent but the call resolved to no business, so there is nothing to check consent against"
            );
        }

        $customerId = $this->subject($requirement, $args);
        if ($customerId === null) {
            return ConsentDecision::unrecorded(
                "[{$descriptor->key}] requires consent but [{$requirement->subjectFrom}] resolved to no customer"
            );
        }

        $channel = ($requirement->channel ?? $descriptor->channel)->value;

        return $this->ledger->permits($customerId, $businessId, $channel, $requirement->purpose);
    }

    private function subject(ConsentRequirement $requirement, array $args): ?int
    {
        $path = $requirement->subjectFrom;
        if (! str_starts_with($path, 'args.')) {
            return null;
        }

        $value = data_get($args, substr($path, 5));

        return is_numeric($value) ? (int) $value : null;
    }
}
