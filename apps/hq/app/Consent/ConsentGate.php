<?php

namespace App\Consent;

use App\Gateway\Descriptor\ConsentRequirement;
use App\Gateway\Descriptor\ToolDescriptor;
use App\Gateway\ToolRequest;
use App\Models\Conversation;

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

    /** @return ConsentDecision permitted, or refused with the reason, the refusal class and the deciding event */
    public function check(ToolDescriptor $descriptor, ToolRequest $request, array $args, ?int $businessId): ConsentDecision
    {
        $requirement = $descriptor->consentRequired;

        if ($requirement === null) {
            return ConsentDecision::notRequired();
        }

        if ($businessId === null) {
            return ConsentDecision::unrecorded(
                "[{$descriptor->key}] requires consent but the call resolved to no business, so there is nothing to check consent against",
                ConsentRefusal::SubjectUnresolved,
            );
        }

        $subject = $this->subject($requirement, $args);
        if ($subject === null) {
            return ConsentDecision::unrecorded(
                "[{$descriptor->key}] requires consent but [{$requirement->subjectFrom}] resolved to no customer",
                ConsentRefusal::SubjectUnresolved,
            );
        }
        [$customerId, $threadBrandId] = $subject;

        $channel = ($requirement->channel ?? $descriptor->channel)->value;

        // docs/108 §4: the brand the send is made under. The thread's own brand when the subject came
        // from a thread (never an id the caller typed), else the request's brand. Unknown brand is
        // fail-closed in the ledger: a brand's denial applies, a brand's grant does not.
        $brandId = $threadBrandId ?? $request->brandId;

        return $this->ledger->permits($customerId, $businessId, $channel, $requirement->purpose, $brandId);
    }

    /** @return array{0: int, 1: ?int}|null the customer id and, for a thread subject, the thread's brand */
    private function subject(ConsentRequirement $requirement, array $args): ?array
    {
        $path = $requirement->subjectFrom;

        // `conversation:args.conversation_uuid`: the thread knows who it is about and the caller
        // need not (docs/72). A reply tool names the thread; the person is read from the row, never
        // from an id the model could have typed.
        if (str_starts_with($path, 'conversation:args.')) {
            $uuid = data_get($args, substr($path, 18));
            if (! is_string($uuid) || $uuid === '') {
                return null;
            }
            $row = Conversation::query()->where('uuid', $uuid)->first(['customer_id', 'brand_id']);
            if ($row === null || $row->customer_id === null) {
                return null;
            }

            return [(int) $row->customer_id, $row->brand_id === null ? null : (int) $row->brand_id];
        }

        if (! str_starts_with($path, 'args.')) {
            return null;
        }

        $value = data_get($args, substr($path, 5));

        return is_numeric($value) ? [(int) $value, null] : null;
    }
}
