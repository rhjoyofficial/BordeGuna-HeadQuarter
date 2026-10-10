<?php

namespace App\Consent;

use App\Models\ConsentEvent;
use App\Models\Customer;
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
     * @param  array{purpose?: ?string, channel?: ?string, brand_id?: ?int, evidence_ref: string, reason: string, occurred_at?: ?DateTimeInterface, expires_at?: ?DateTimeInterface}  $input
     */
    public function record(User $actor, Customer $customer, string $act, array $input): ConsentEvent
    {
        $evidence = trim((string) ($input['evidence_ref'] ?? ''));
        $reason = trim((string) ($input['reason'] ?? ''));
        if ($evidence === '' || $reason === '') {
            throw new InvalidArgumentException('An operator consent act needs an evidence reference and a reason.');
        }

        $occurredAt = isset($input['occurred_at']) ? Carbon::instance(Carbon::parse($input['occurred_at'])) : now();
        if ($occurredAt->isFuture()) {
            throw new InvalidArgumentException('A consent act cannot be dated in the future.');
        }

        $channel = $input['channel'] ?? null;
        $purpose = isset($input['purpose']) && $input['purpose'] !== null ? ConsentPurpose::from($input['purpose']) : null;
        $brandId = isset($input['brand_id']) ? (int) $input['brand_id'] : null;
        $this->assertBrandBelongs($customer, $brandId);

        [$state, $purpose, $expiresAt] = match ($act) {
            self::ACT_WITHDRAW => [ConsentState::Withdrawn, $purpose, null],
            // Person-level: every purpose, optionally narrowed by brand or channel.
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
            $standing = $this->ledger->latestAtScope($customer->id, $customer->business_id, $brandId, $channel, $purpose?->value);
            if ($standing === null || ! $standing->state->isRestriction()) {
                throw new InvalidArgumentException('There is no withdrawal or do-not-contact standing at exactly this scope to lift.');
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
                'expires_at' => $expiresAt,
                'evidence_ref' => mb_substr($evidence, 0, 191),
                'evidence' => ['act' => $act, 'recorded_via' => 'operator_screen'],
                'actor_type' => 'human',
                'actor_id' => $actor->id,
                'actor_label' => $actor->email,
                'reason' => $reason,
            ],
        );

        // The ledger already wrote `consent.recorded`; this names the act and the person, so the audit
        // screen reads "Rahim recorded a do-not-contact" rather than a state transition.
        $this->auditor->record('consent.operator_recorded', $event, after: [
            'act' => $act, 'customer_id' => $customer->id, 'business_id' => $customer->business_id,
            'brand_id' => $brandId, 'channel' => $channel, 'purpose' => $purpose?->value, 'state' => $state->value,
            'evidence_ref' => $event->evidence_ref,
        ], reason: $reason, actorUserId: $actor->id, actorLabel: $actor->email);

        return $event;
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
}
