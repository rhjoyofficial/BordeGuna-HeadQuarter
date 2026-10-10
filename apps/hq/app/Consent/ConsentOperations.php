<?php

namespace App\Consent;

use App\Gateway\Descriptor\Channel;
use App\Models\ConsentEvent;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use App\Models\User;
use App\Support\Audit\Auditor;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * The operator acts on consent (docs/108 §5, S7): withdraw, do-not-contact, marketing opt-in,
 * operator-recorded consent, and lifting a restriction.
 *
 * ⭐ Every act is an EVENT in the existing ledger (`ConsentLedger::record`), never an edit, so the
 * runtime check at the gateway sees it the moment it is written and a historical question can still
 * be answered afterwards. Basis is `operator`, the actor is the signed-in person, and an evidence
 * reference and a reason are required: "who said we could, or could not, and where is it written".
 *
 * Two kinds of act, kept apart because they carry different risk (docs/108 §5):
 *   - RESTRICTING (withdraw, do-not-contact): can only ever stop contact; `hq.consent.restrict`;
 *   - WIDENING (opt-in, operator-recorded consent, lift): can start contact; `hq.consent.grant`.
 *
 * Every act may name ONE IDENTITY of the customer (docs/114 §2, ruling (i)): the phone or PSID the
 * person spoke about, or none for the whole customer. An identity must belong to the customer, and
 * a channel named beside it must be the identity's own channel.
 *
 * ⛔ Lifting a DO-NOT-CONTACT is not an act here (docs/114 §3, ruling (k)). `record()` refuses it on
 * this single-person path; the only writer is `liftDoNotContact()`, reached from the gateway tool
 * `consent.lift_restriction` after a second person approved (`GatewayCoverageStructureTest` pins
 * that the method is called from the tool and nowhere else). Lifting a plain withdrawal keeps the
 * single-person rule under `hq.consent.grant`.
 */
final readonly class ConsentOperations
{
    public const ACT_WITHDRAW = 'withdraw';

    public const ACT_DO_NOT_CONTACT = 'do_not_contact';

    public const ACT_MARKETING_OPT_IN = 'marketing_opt_in';

    public const ACT_OPERATOR_CONSENT = 'operator_consent';

    public const ACT_LIFT = 'lift';

    public const RESTRICTING = [self::ACT_WITHDRAW, self::ACT_DO_NOT_CONTACT];

    public const WIDENING = [self::ACT_MARKETING_OPT_IN, self::ACT_OPERATOR_CONSENT, self::ACT_LIFT];

    /** The permission of whoever asks for, and whoever approves, the lift of a do-not-contact (docs/114 §3). */
    public const PERMISSION_LIFT_RESTRICTION = 'hq.consent.lift_restriction';

    public function __construct(private ConsentLedger $ledger, private Auditor $auditor) {}

    /** The permission an act requires. */
    public static function permissionFor(string $act): string
    {
        return match (true) {
            in_array($act, self::RESTRICTING, true) => 'hq.consent.restrict',
            in_array($act, self::WIDENING, true) => 'hq.consent.grant',
            default => throw new InvalidArgumentException("Unknown consent act [{$act}]."),
        };
    }

    /**
     * Record one act. Scope and evidence are validated here, not only in the request, so a console or
     * a later caller cannot write what the screen would refuse.
     *
     * @param  array{purpose?: ?string, channel?: ?string, brand_id?: ?int, identity_id?: ?int, evidence_ref: string, reason: string, occurred_at?: ?DateTimeInterface, expires_at?: ?DateTimeInterface}  $input
     */
    public function record(User $actor, Customer $customer, string $act, array $input): ConsentEvent
    {
        [$evidence, $reason] = $this->evidenceAndReason($input);

        $occurredAt = isset($input['occurred_at']) ? Carbon::instance(Carbon::parse($input['occurred_at'])) : now();
        if ($occurredAt->isFuture()) {
            throw new InvalidArgumentException('A consent act cannot be dated in the future.');
        }

        $channel = $input['channel'] ?? null;
        $purpose = isset($input['purpose']) && $input['purpose'] !== null ? ConsentPurpose::from($input['purpose']) : null;
        $brandId = isset($input['brand_id']) ? (int) $input['brand_id'] : null;
        $identityId = isset($input['identity_id']) ? (int) $input['identity_id'] : null;
        $this->assertBrandBelongs($customer, $brandId);
        $this->assertIdentityBelongs($customer, $identityId, $channel);

        [$state, $purpose, $expiresAt] = match ($act) {
            self::ACT_WITHDRAW => [ConsentState::Withdrawn, $purpose, null],
            // Person-level: every purpose, optionally narrowed by brand, identity or channel.
            self::ACT_DO_NOT_CONTACT => [ConsentState::DoNotContact, null, null],
            self::ACT_MARKETING_OPT_IN => [ConsentState::Granted, ConsentPurpose::Marketing, $input['expires_at'] ?? null],
            self::ACT_OPERATOR_CONSENT => [ConsentState::Granted, ConsentPurpose::Service, $input['expires_at'] ?? null],
            self::ACT_LIFT => [ConsentState::Lifted, $purpose, null],
            default => throw new InvalidArgumentException("Unknown consent act [{$act}]."),
        };

        if (in_array($act, [self::ACT_MARKETING_OPT_IN, self::ACT_OPERATOR_CONSENT], true) && $channel === null) {
            throw new InvalidArgumentException('A grant is recorded per channel: name the channel the person agreed to.');
        }
        if ($act === self::ACT_MARKETING_OPT_IN && $brandId === null && $customer->business()->first()?->brands()->exists()) {
            throw new InvalidArgumentException('A marketing opt-in is per brand: name the brand the person agreed to hear from.');
        }
        if ($act === self::ACT_LIFT) {
            $standing = $this->ledger->latestAtScope($customer->id, $customer->business_id, $brandId, $channel, $purpose?->value, $identityId);
            if ($standing === null || ! $standing->state->isRestriction()) {
                throw new InvalidArgumentException('There is no withdrawal or do-not-contact standing at exactly this scope to lift.');
            }
            // docs/114 §3 (ruling (k)): a do-not-contact is lifted by two people or not at all. This
            // path is one person; the tool `consent.lift_restriction` parks the request for a second.
            if ($standing->state === ConsentState::DoNotContact) {
                throw new InvalidArgumentException(
                    'A do-not-contact is lifted only through the two-person lift (consent.lift_restriction): '
                    .'the request waits for a second, independent approver. One person cannot lift it here.'
                );
            }
        }

        $event = $this->ledger->record(
            customerId: $customer->id,
            businessId: $customer->business_id,
            purpose: $purpose,
            state: $state,
            basis: ConsentBasis::Operator,
            channel: $channel,
            occurredAt: $occurredAt,
            attributes: [
                'brand_id' => $brandId,
                'identity_id' => $identityId,
                'expires_at' => $expiresAt,
                'evidence_ref' => $evidence,
                'evidence' => ['act' => $act, 'recorded_via' => 'operator_screen'],
                'actor_type' => 'human',
                'actor_id' => $actor->id,
                'actor_label' => $actor->email,
                'reason' => $reason,
            ],
        );

        $this->auditAct($actor, $event, $act, $reason);

        return $event;
    }

    /**
     * The ONLY writer of a lifted do-not-contact (docs/114 §3, ruling (k)). Called by the gateway tool
     * `consent.lift_restriction` once a second person has approved it; the tool runs under the APPROVER,
     * whose permission the gateway has re-checked, and hands over who asked and who decided.
     *
     * Fail closed in every branch: no two distinct people, no standing do-not-contact at exactly this
     * scope, or an approver without the lift permission, and nothing is written.
     *
     * @param  array{brand_id?: ?int, identity_id?: ?int, channel?: ?string}  $scope
     * @param  array{approval_uuid: string, requested_by_user_id: ?int, approved_by_user_id: ?int}  $twoPerson
     */
    public function liftDoNotContact(User $approver, Customer $customer, array $scope, string $evidenceRef, string $reason, array $twoPerson): ConsentEvent
    {
        [$evidence, $reason] = $this->evidenceAndReason(['evidence_ref' => $evidenceRef, 'reason' => $reason]);

        $requestedBy = $twoPerson['requested_by_user_id'] ?? null;
        $approvedBy = $twoPerson['approved_by_user_id'] ?? null;
        $approvalUuid = (string) ($twoPerson['approval_uuid'] ?? '');
        if ($approvalUuid === '' || $requestedBy === null || $approvedBy === null || (int) $requestedBy === (int) $approvedBy || (int) $approvedBy !== (int) $approver->id) {
            throw new InvalidArgumentException('A do-not-contact is lifted by two different people under one approval: the person who asked and the person who approved. That is not what was handed over.');
        }
        if (! $approver->can(self::PERMISSION_LIFT_RESTRICTION)) {
            throw new InvalidArgumentException('The approver does not hold ['.self::PERMISSION_LIFT_RESTRICTION.'].');
        }

        $channel = $scope['channel'] ?? null;
        $brandId = isset($scope['brand_id']) ? (int) $scope['brand_id'] : null;
        $identityId = isset($scope['identity_id']) ? (int) $scope['identity_id'] : null;
        $this->assertBrandBelongs($customer, $brandId);
        $this->assertIdentityBelongs($customer, $identityId, $channel);

        // Re-read now, not when the request was parked: the restriction may have changed while the
        // approval waited, and a lift that no longer matches what stands lifts nothing.
        $standing = $this->ledger->latestAtScope($customer->id, $customer->business_id, $brandId, $channel, null, $identityId);
        if ($standing === null || $standing->state !== ConsentState::DoNotContact) {
            throw new InvalidArgumentException('There is no do-not-contact standing at exactly this scope now; nothing was lifted.');
        }

        $event = $this->ledger->record(
            customerId: $customer->id,
            businessId: $customer->business_id,
            purpose: null,
            state: ConsentState::Lifted,
            basis: ConsentBasis::Operator,
            channel: $channel,
            occurredAt: now(),
            attributes: [
                'brand_id' => $brandId,
                'identity_id' => $identityId,
                'evidence_ref' => $evidence,
                'evidence' => [
                    'act' => 'lift_do_not_contact',
                    'recorded_via' => 'consent.lift_restriction',
                    'lifted_event_id' => $standing->id,
                    'approval_uuid' => $approvalUuid,
                    'requested_by_user_id' => (int) $requestedBy,
                    'approved_by_user_id' => (int) $approvedBy,
                ],
                'actor_type' => 'human',
                'actor_id' => $approver->id,
                'actor_label' => $approver->email,
                'reason' => $reason,
            ],
        );

        $this->auditAct($approver, $event, 'lift_do_not_contact', $reason, [
            'lifted_event_id' => $standing->id,
            'approval_uuid' => $approvalUuid,
            'requested_by_user_id' => (int) $requestedBy,
            'approved_by_user_id' => (int) $approvedBy,
        ]);

        return $event;
    }

    /** @return array{0: string, 1: string} */
    private function evidenceAndReason(array $input): array
    {
        $evidence = trim((string) ($input['evidence_ref'] ?? ''));
        $reason = trim((string) ($input['reason'] ?? ''));
        if ($evidence === '' || $reason === '') {
            throw new InvalidArgumentException('An operator consent act needs an evidence reference and a reason.');
        }

        return [mb_substr($evidence, 0, 191), $reason];
    }

    /**
     * The ledger already wrote `consent.recorded`; this names the act and the person, so the audit
     * screen reads "Rahim recorded a do-not-contact" rather than a state transition.
     */
    private function auditAct(User $actor, ConsentEvent $event, string $act, string $reason, array $extra = []): void
    {
        $this->auditor->record($act === 'lift_do_not_contact' ? 'consent.restriction_lifted' : 'consent.operator_recorded', $event, after: [
            'act' => $act, 'customer_id' => $event->customer_id, 'business_id' => $event->business_id,
            'brand_id' => $event->brand_id, 'identity_id' => $event->identity_id, 'channel' => $event->channel,
            'purpose' => $event->purpose?->value, 'state' => $event->state->value,
            'evidence_ref' => $event->evidence_ref,
        ] + $extra, reason: $reason, actorUserId: $actor->id, actorLabel: $actor->email);
    }

    private function assertBrandBelongs(Customer $customer, ?int $brandId): void
    {
        if ($brandId === null) {
            return;
        }
        $belongs = $customer->business()->first()?->brands()->whereKey($brandId)->exists() ?? false;
        if (! $belongs) {
            throw new InvalidArgumentException("Brand {$brandId} is not a brand of this customer's business.");
        }
    }

    /**
     * docs/114 §2: an identity is the customer's own row, never another person's (no cross-business or
     * cross-person expansion), and a channel named beside it is the channel that identity lives on.
     */
    private function assertIdentityBelongs(Customer $customer, ?int $identityId, ?string $channel): void
    {
        if ($identityId === null) {
            return;
        }
        $identity = CustomerIdentity::query()->whereKey($identityId)->where('customer_id', $customer->id)->first(['id', 'channel']);
        if ($identity === null) {
            throw new InvalidArgumentException("Identity {$identityId} is not an identity of this customer.");
        }
        if ($channel !== null && Channel::tryFrom($channel)?->identityChannel() !== $identity->channel) {
            throw new InvalidArgumentException("Identity {$identityId} is a [{$identity->channel}] identity; it cannot be scoped to channel [{$channel}].");
        }
    }
}
