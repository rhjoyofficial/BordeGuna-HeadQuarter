<?php

namespace App\Identity\Conflicts;

use App\Consent\ConsentBasis;
use App\Consent\ConsentLedger;
use App\Consent\ConsentState;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use App\Models\CustomerIdentityConflict;
use App\Models\CustomerIdentityConflictResolution as Resolution;
use App\Models\User;
use App\Support\Audit\Auditor;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The human resolution of one open `customer_identity_conflicts` row (docs/140, ruling docs/131 item 3, docs/138 P3-W1).
 *
 * ⛔ Reached ONLY from the permanently human-only gateway tool `customers.identity_conflicts.resolve`
 *   (`GatewayCoverageStructureTest` pins `->resolveConflict(` to that tool). No agent, command or controller calls it.
 *
 * Three choices, each limited to what the evidence can carry:
 *   - kept_owner: the identity stays with the customer that holds it. A quarantined identity comes back live only when
 *     the rows support it (see `ReleaseEvidence`); otherwise the choice is refused, nothing is written, and the person
 *     is told to mark it not resolvable or to use the reviewed repair.
 *   - moved_to_claimant: only for a refused re-point (`link_refused`), only to the claimant the conflict names, only
 *     when that claimant is a standing customer of the SAME business. A quarantined identity must also pass the release
 *     checks. Grants never follow the identity (the new owner has none until it is given one); standing restrictions
 *     recorded under the old owner on THIS identity are re-recorded on the new owner (broader is safe, narrower never).
 *     Customer-wide restrictions of the old owner are listed for a person to review, never silently dropped.
 *   - not_resolvable: the conflict closes; the identity is untouched (a quarantined one stays held as evidence).
 * Never across a business: the conflict, its identity and every customer named are of the one business the gateway
 * scoped the call to, or nothing happens. Evidence and a reason are required. The conflict row gets `resolved_at`,
 * `resolved_by` and `resolution`; the append-only resolution row and an audit row are written in the same transaction.
 * Idempotent: the same choice on a resolved conflict is a replay of the recorded row; another choice is refused.
 */
final class ConflictResolver
{
    public function __construct(private readonly Auditor $auditor, private readonly ConsentLedger $consent, private readonly ReleaseEvidence $release) {}

    /**
     * @param  list<int>|null  $permittedBusinessIds  the gateway's scope for the person (null: unrestricted)
     * @return array<string, mixed>
     */
    public function resolveConflict(int $conflictId, int $businessId, string $choice, string $evidence, string $reason, User $actor, ?array $permittedBusinessIds, ?string $correlationId): array
    {
        if (! in_array($choice, Resolution::CHOICES, true)) {
            throw new InvalidArgumentException("[{$choice}] is not a resolution; the choices are ".implode(', ', Resolution::CHOICES).'.');
        }
        if (mb_strlen(trim($evidence)) < 10 || mb_strlen(trim($reason)) < 10) {
            throw new InvalidArgumentException('A resolution needs the evidence you relied on and a reason, each at least 10 characters.');
        }
        if ($permittedBusinessIds !== null && ! in_array($businessId, $permittedBusinessIds, true)) {
            throw new InvalidArgumentException("You may not read business {$businessId}; nothing was changed.");
        }

        return DB::transaction(function () use ($conflictId, $businessId, $choice, $evidence, $reason, $actor, $correlationId) {
            $conflict = CustomerIdentityConflict::query()->whereKey($conflictId)->lockForUpdate()->first();
            if ($conflict === null || (int) $conflict->business_id !== $businessId) {
                // The same answer for "no such conflict" and "a conflict of another business": nothing crosses.
                throw new InvalidArgumentException("Conflict #{$conflictId} is not a conflict of this business; nothing was changed.");
            }

            if ($conflict->resolved_at !== null) {
                $recorded = Resolution::query()->where('conflict_id', $conflict->id)->first();
                if ($recorded !== null && $recorded->resolution === $choice) {
                    return $this->report($recorded, replayed: true);
                }
                throw new InvalidArgumentException("Conflict #{$conflict->id} was already resolved ({$conflict->resolution}); a resolution is never changed. Nothing was changed.");
            }

            $identity = CustomerIdentity::query()->whereKey($conflict->identity_id)->lockForUpdate()->firstOrFail();
            if ((int) $identity->business_id !== $businessId) {
                throw new InvalidArgumentException("Identity #{$identity->id} is not an identity of this business; nothing was changed.");
            }
            $ownerBefore = (int) $identity->customer_id;
            if ($choice !== Resolution::NOT_RESOLVABLE && $conflict->holder_customer_id !== null && (int) $conflict->holder_customer_id !== $ownerBefore) {
                throw new InvalidArgumentException("Identity #{$identity->id} changed owner since this conflict was recorded (now customer #{$ownerBefore}); look again. Nothing was changed.");
            }

            $ownerAfter = $ownerBefore;
            $released = false;
            $checked = ['quarantined' => $identity->quarantined_at !== null, 'quarantine_reason' => $identity->quarantine_reason];
            $carried = [];
            $consentReview = [];

            if ($choice === Resolution::MOVE_TO_CLAIMANT) {
                $ownerAfter = $this->claimant($conflict, $businessId, $ownerBefore);
            }
            if ($choice !== Resolution::NOT_RESOLVABLE && $identity->quarantined_at !== null) {
                $blockers = $this->release->blockers($identity, $businessId);
                $checked['release_blockers'] = array_keys($blockers);
                if ($blockers !== []) {
                    throw new InvalidArgumentException('The rows do not support releasing this held identity: '.implode('; ', $blockers)
                        .'. Mark it not resolvable, or use the reviewed repair. Nothing was changed.');
                }
                $released = true;
            }

            if ($released || $ownerAfter !== $ownerBefore) {
                $identity->forceFill(array_merge(
                    $released ? ['quarantined_at' => null, 'quarantine_reason' => null] : [],
                    $ownerAfter !== $ownerBefore ? ['customer_id' => $ownerAfter] : [],
                ))->save();
            }
            if ($ownerAfter !== $ownerBefore) {
                [$carried, $consentReview] = $this->carryRestrictions($identity, $ownerBefore, $ownerAfter, $businessId, $actor, $reason, $conflict->id);
            }

            $now = now();
            $conflict->forceFill(['resolved_at' => $now, 'resolved_by' => $actor->id, 'resolution' => $choice, 'updated_at' => $now])->save();

            $record = Resolution::create([
                'conflict_id' => $conflict->id,
                'business_id' => $businessId,
                'identity_id' => $identity->id,
                'resolution' => $choice,
                'owner_before_customer_id' => $ownerBefore,
                'owner_after_customer_id' => $ownerAfter,
                'released_from_quarantine' => $released,
                'evidence' => ['stated' => trim($evidence), 'checked' => $checked + ['consent_review' => $consentReview]],
                'reason' => trim($reason),
                'carried_restrictions' => $carried,
                'resolved_by' => $actor->id,
                'correlation_id' => $correlationId,
            ]);

            $this->auditor->record('identity.conflict.resolved', $conflict,
                before: ['identity_id' => $identity->id, 'owner_customer_id' => $ownerBefore, 'quarantined' => $checked['quarantined'], 'kind' => $conflict->kind, 'reason' => $conflict->reason],
                after: ['resolution' => $choice, 'resolution_id' => $record->id, 'owner_customer_id' => $ownerAfter, 'released_from_quarantine' => $released,
                    'carried_restrictions' => count($carried), 'business_id' => $businessId],
                reason: trim($reason), actorUserId: $actor->id, actorLabel: $actor->email);

            return $this->report($record, replayed: false);
        });
    }

    /** The claimant the conflict names, a standing customer of the same business, or a refusal. */
    private function claimant(CustomerIdentityConflict $conflict, int $businessId, int $ownerBefore): int
    {
        if ($conflict->kind !== CustomerIdentityConflict::KIND_LINK_REFUSED || $conflict->claimant_customer_id === null) {
            throw new InvalidArgumentException("Conflict #{$conflict->id} names no claimant (a row the migration held), so there is nobody to move it to. Nothing was changed.");
        }
        $claimant = Customer::query()->whereKey($conflict->claimant_customer_id)->lockForUpdate()->first();
        if ($claimant === null || (int) $claimant->business_id !== $businessId) {
            throw new InvalidArgumentException('The claimant is not a customer of this business; an identity never moves across a business. Nothing was changed.');
        }
        if ($claimant->merged_into_id !== null) {
            throw new InvalidArgumentException("The claimant (customer #{$claimant->id}) was merged into customer #{$claimant->merged_into_id}; it no longer stands. Nothing was changed.");
        }
        if ((int) $claimant->id === $ownerBefore) {
            throw new InvalidArgumentException('The claimant already holds this identity; choose keep the owner. Nothing was changed.');
        }

        return (int) $claimant->id;
    }

    /**
     * The old owner's standing restrictions on THIS identity, re-recorded on the new owner (latest per scope, kept when
     * a do-not-contact or a withdrawal). The old owner's customer-wide restrictions are listed for a person, not moved.
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function carryRestrictions(CustomerIdentity $identity, int $from, int $to, int $businessId, User $actor, string $reason, int $conflictId): array
    {
        $latest = [];
        foreach (DB::table('consent_events')->where('customer_id', $from)->where('business_id', $businessId)->orderBy('occurred_at')->orderBy('id')->get() as $e) {
            $latest[($e->brand_id ?? '*').'|'.($e->identity_id ?? '*').'|'.($e->channel ?? '*').'|'.($e->purpose ?? '*')] = $e;
        }
        $restricting = [ConsentState::DoNotContact->value, ConsentState::Withdrawn->value];
        $carried = [];
        $review = [];
        foreach ($latest as $e) {
            if (! in_array($e->state, $restricting, true)) {
                continue;
            }
            if ($e->identity_id === null) {
                $review[] = ['consent_event_id' => (int) $e->id, 'state' => (string) $e->state, 'channel' => $e->channel, 'purpose' => $e->purpose];

                continue;
            }
            if ((int) $e->identity_id !== (int) $identity->id) {
                continue;
            }
            $event = $this->consent->record(
                customerId: $to,
                businessId: $businessId,
                purpose: $e->purpose,
                state: ConsentState::from($e->state),
                basis: ConsentBasis::Operator,
                channel: $e->channel,
                attributes: [
                    'brand_id' => $e->brand_id === null ? null : (int) $e->brand_id,
                    'identity_id' => (int) $identity->id,
                    'evidence_ref' => "consent_events#{$e->id}",
                    'evidence' => ['act' => 'restriction_carried_by_conflict_resolution', 'from_event_id' => (int) $e->id, 'from_customer_id' => $from, 'conflict_id' => $conflictId],
                    'actor_type' => 'human',
                    'actor_id' => $actor->id,
                    'actor_label' => $actor->email,
                    'reason' => "identity conflict #{$conflictId}: restriction preserved from consent event #{$e->id} when the identity moved. ".$reason,
                ],
            );
            $carried[] = ['from_event' => (int) $e->id, 'to_event' => (int) $event->id, 'state' => (string) $e->state];
        }

        return [$carried, $review];
    }

    /** @return array<string, mixed> */
    private function report(Resolution $record, bool $replayed): array
    {
        return [
            'status' => $replayed ? 'already_resolved' : 'resolved',
            'replayed' => $replayed,
            'resolution_id' => (int) $record->id,
            'conflict_id' => (int) $record->conflict_id,
            'resolution' => (string) $record->resolution,
            'owner_before_customer_id' => (int) $record->owner_before_customer_id,
            'owner_after_customer_id' => (int) $record->owner_after_customer_id,
            'released_from_quarantine' => (bool) $record->released_from_quarantine,
            'carried_restrictions' => $record->carried_restrictions ?? [],
            'consent_review' => $record->evidence['checked']['consent_review'] ?? [],
        ];
    }
}
