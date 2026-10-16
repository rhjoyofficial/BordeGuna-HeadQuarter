<?php

namespace App\Identity\Repair;

use App\Consent\ConsentBasis;
use App\Consent\ConsentLedger;
use App\Consent\ConsentState;
use App\Identity\Quarantine\Finding;
use App\Identity\Quarantine\QuarantineLedger;
use App\Models\Customer;
use App\Models\CustomerIdentityRepair;
use App\Models\CustomerMergeReversal;
use App\Models\User;
use App\Support\Audit\Auditor;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * The ONLY writer of a repair (docs/126, ruling docs/120 A3 and item 36). Reached from the human-only Tool Gateway
 * capability `customers.quarantine.repair` and from nowhere else (`GatewayCoverageStructureTest` pins
 * `->applyRepair(` to that tool).
 *
 * What one call does, for one batch key (one target business):
 *   - finds the batch row by key (a resume) or creates it from the plan the key names (a first run); a key the live
 *     plan does not produce and no batch carries is refused: the data changed since the dry run, review it again;
 *   - applies each split in its own transaction, with the source and target customer rows locked, after recomputing
 *     the split from the live rows and comparing its fingerprint with the planned one (a difference refuses the
 *     split: a concurrent change), after checking the person may read both businesses, and after checking the
 *     split's flags carry a `needs_repair` review and no `disputed` one (docs/117 §5);
 *   - a split already recorded in `customer_merge_reversals` is skipped (idempotent, restartable);
 *   - never deletes a row, never touches a `customer_merges` row, never moves or edits a consent row, never moves a
 *     business-less memory (withheld, listed), never guesses: a row the planner marked ambiguous is quarantined
 *     (an append-only flag, `repair_ambiguous_*`) and reported;
 *   - preserves outstanding restrictions (A3 point 9): a do-not-contact or withdrawal that is still the latest word at
 *     its scope, recorded for the target business under the source customer, is APPENDED to the target customer's
 *     ledger as the same restriction (a new event naming the original as evidence). A grant is never carried: the
 *     target's ledger then refuses by silence. Both lists still go to a person to re-record in the correct scope;
 *   - runs one at a time: a PostgreSQL advisory lock refuses a second repair while one is applying;
 *   - when every split is done, verifies ownership and the business-scoped reads (RepairVerifier) and only then
 *     marks the batch `verified`; otherwise the batch stays `partial` and the report says why, per split.
 */
final class RepairApplier
{
    /** The advisory lock every repair takes for its whole run (one repair at a time). */
    private const LOCK = 126_000_126;

    /** The quarantine kind of a flag on a row of each table (the CHECK in 2026_10_15_600000). */
    private const KIND_BY_TABLE = [
        'customer_merges' => 'merge', 'customers' => 'merge_pointer', 'customer_identities' => 'identity', 'facts_orders' => 'order',
        'conversations' => 'conversation', 'conversation_participants' => 'participant', 'conversation_messages' => 'message', 'memories' => 'memory',
        'consent_events' => 'consent_event', 'consent_state' => 'consent_state', 'customer_identity_conflicts' => 'identity_conflict',
    ];

    public function __construct(
        private readonly RepairPlanner $planner,
        private readonly RepairVerifier $verifier,
        private readonly RepairRecount $recount,
        private readonly QuarantineLedger $ledger,
        private readonly Auditor $auditor,
        private readonly ConsentLedger $consent,
    ) {}

    /**
     * @param  list<int>|null  $permittedBusinessIds  null = every business
     * @return array<string, mixed> the report (also stored on the batch row)
     */
    public function applyRepair(string $key, int $businessId, User $actor, string $reason, ?string $correlationId = null, ?array $permittedBusinessIds = null): array
    {
        $reason = trim($reason);
        if (strlen($reason) < 10) {
            throw new InvalidArgumentException('A repair needs a reason of at least ten characters.');
        }

        if (! (bool) DB::selectOne('SELECT pg_try_advisory_lock(?) AS ok', [self::LOCK])->ok) {
            throw new InvalidArgumentException('Another identity repair is applying right now; nothing was changed. Try again when it has finished.');
        }
        try {
            return $this->applyLocked($key, $businessId, $actor, $reason, $correlationId, $permittedBusinessIds);
        } finally {
            DB::selectOne('SELECT pg_advisory_unlock(?) AS ok', [self::LOCK]);
        }
    }

    /**
     * @param  list<int>|null  $permittedBusinessIds
     * @return array<string, mixed>
     */
    private function applyLocked(string $key, int $businessId, User $actor, string $reason, ?string $correlationId, ?array $permittedBusinessIds): array
    {
        $batch = CustomerIdentityRepair::query()->where('key', $key)->first();
        if ($batch !== null && (int) $batch->business_id !== $businessId) {
            throw new InvalidArgumentException("Batch [{$key}] belongs to another business than the one named.");
        }
        if ($batch !== null && in_array($batch->status, [CustomerIdentityRepair::STATUS_VERIFIED], true)) {
            return ['status' => 'already_verified', 'replayed' => true, 'repair_id' => $batch->id, 'key' => $key, 'splits' => [], 'report' => $batch->report];
        }

        $live = $this->planner->plan();
        if ($batch === null) {
            $planned = $live->batchByKey($key);
            if ($planned === null || (int) $planned['business_id'] !== $businessId) {
                throw new InvalidArgumentException("No proposed batch carries the key [{$key}] for this business: the data changed since the dry run, or the key is not this business's. Run the dry run again and review it.");
            }
            // Item 36: only after an explicit needs_repair review. A first run writes nothing, not even the batch row,
            // until every split of the batch is authorized (each split is checked again under lock below).
            $unreviewed = array_values(array_filter($planned['splits'], fn (array $s) => ! $s['review']['authorized']));
            if ($unreviewed !== []) {
                throw new InvalidArgumentException(count($unreviewed).' split(s) of this batch are not reviewed: every row needs a review on the cross-business review screen, '
                    .'at least one as needs_repair and none disputed. Nothing was changed.');
            }
            $batch = CustomerIdentityRepair::query()->create([
                'key' => $key, 'business_id' => $businessId, 'status' => CustomerIdentityRepair::STATUS_APPLYING,
                'plan' => ['batch' => $planned, 'ambiguous' => $live->ambiguous, 'held_identities' => $live->heldIdentities, 'open_conflicts' => $live->openConflicts, 'human_lists' => $live->humanLists],
                'reason' => $reason, 'applied_by' => $actor->id, 'correlation_id' => $correlationId, 'started_at' => now(),
            ]);
            $this->auditor->record('customer.repair.started', $batch, after: ['key' => $key, 'business_id' => $businessId, 'splits' => count($planned['splits'])], reason: $reason, actorUserId: $actor->id);
        } else {
            $batch->forceFill(['status' => CustomerIdentityRepair::STATUS_APPLYING])->save();
            $this->auditor->record('customer.repair.resumed', $batch, after: ['key' => $key, 'business_id' => $businessId], reason: $reason, actorUserId: $actor->id);
        }

        $results = [];
        $done = CustomerMergeReversal::query()->where('repair_id', $batch->id)->pluck('id', 'split_key')->all();
        foreach ($batch->plan['batch']['splits'] as $planned) {
            $splitKey = $planned['key'];
            if (isset($done[$splitKey])) {
                $results[] = ['split' => $splitKey, 'status' => 'already_applied', 'reversal_id' => (int) $done[$splitKey], 'from_customer_id' => $planned['from_customer_id']];

                continue;
            }
            $results[] = $this->applySplit($batch, $planned, $live, $actor, $reason, $permittedBusinessIds);
        }

        // Ambiguous rows of this business: quarantined (append-only flag per row, audited as the person) and reported,
        // never moved (A3 last paragraph). An ambiguous split flags every row it would have moved, each under its own kind.
        $flagged = $this->ledger->flag($this->ambiguousFindings($live->ambiguous, $businessId), $actor->id);

        $allDone = collect($results)->every(fn ($r) => in_array($r['status'], ['applied', 'already_applied'], true));
        $verification = null;
        $recount = null;
        if ($allDone) {
            $batch->forceFill(['status' => CustomerIdentityRepair::STATUS_APPLIED, 'applied_at' => $batch->applied_at ?? now()])->save();
            $verification = $this->verifier->verify($batch->fresh(), $actor);
            // Item 37: the affected customers' stored figures against their own business's rows. A mismatch fails the batch.
            $recount = $this->recount->recount($this->affectedBusinesses($batch), $this->affectedCustomers($batch));
            if (! $recount['verified']) {
                $verification['ok'] = false;
                foreach ($recount['customers'] as $c) {
                    if (! $c['orders_count']['ok'] || ! $c['lifetime_value']['ok']) {
                        $verification['failures'][] = "customer #{$c['customer_id']}: stored figures differ from its own business's rows";
                    }
                }
            }
            $batch->forceFill([
                'status' => $verification['ok'] ? CustomerIdentityRepair::STATUS_VERIFIED : CustomerIdentityRepair::STATUS_VERIFICATION_FAILED,
                'verified_at' => $verification['ok'] ? now() : null,
            ])->save();
            $this->auditor->record($verification['ok'] ? 'customer.repair.verified' : 'customer.repair.verification_failed', $batch,
                after: ['key' => $key, 'checks' => $verification['checks'], 'failures' => $verification['failures']], reason: $reason, actorUserId: $actor->id);
        } else {
            $batch->forceFill(['status' => CustomerIdentityRepair::STATUS_PARTIAL])->save();
        }

        $report = [
            'status' => $batch->status,
            'replayed' => false,
            'repair_id' => $batch->id,
            'key' => $key,
            'splits' => $results,
            'ambiguous_flagged' => count($flagged),
            'ambiguous' => $live->ambiguous,
            'held_identities' => $live->heldIdentities,
            'open_conflicts' => $live->openConflicts,
            'human_lists' => $live->humanLists,
            'verification' => $verification,
            'recount' => $recount,
        ];
        $batch->forceFill(['report' => $report])->save();

        return $report;
    }

    /**
     * @param  array<string, mixed>  $planned
     * @param  list<int>|null  $permitted
     * @return array<string, mixed>
     */
    private function applySplit(CustomerIdentityRepair $batch, array $planned, RepairPlan $live, User $actor, string $reason, ?array $permitted): array
    {
        $base = ['split' => $planned['key'], 'from_customer_id' => $planned['from_customer_id'], 'to_business_id' => $planned['to_business_id']];
        if ($permitted !== null && (! in_array((int) $planned['from_business_id'], $permitted, true) || ! in_array((int) $planned['to_business_id'], $permitted, true))) {
            return $base + ['status' => 'refused', 'why' => 'you may not read both businesses this split joins'];
        }

        try {
            return DB::transaction(function () use ($batch, $planned, $actor, $reason, $base) {
                // Lock the customer rows first, then look again: the split is applied only to what the person reviewed.
                $source = Customer::query()->whereKey($planned['from_customer_id'])->lockForUpdate()->first();
                if ($source === null) {
                    return $base + ['status' => 'refused', 'why' => 'the source customer no longer exists'];
                }
                if ($planned['to_customer_id'] !== null) {
                    Customer::query()->whereKey($planned['to_customer_id'])->lockForUpdate()->first();
                }
                $current = $this->planner->plan()->split($planned['key']);
                if ($current === null) {
                    return $base + ['status' => 'refused', 'why' => 'the rows no longer cross a business boundary as reviewed (changed since the dry run)'];
                }
                if ($current['fingerprint'] !== $planned['fingerprint']) {
                    return $base + ['status' => 'refused', 'why' => 'concurrent change detected: a row of this split changed since the dry run; run the dry run again'];
                }
                if (! $current['review']['authorized']) {
                    return $base + ['status' => 'refused', 'why' => 'not reviewed: at least one flag of this split needs a needs_repair review and none may be disputed', 'review' => $current['review']];
                }

                $split = $current;
                $to = (int) $split['to_business_id'];
                $created = false;
                if ($split['to_customer_id'] === null) {
                    $orders = DB::table('facts_orders')->whereIn('id', $split['rows']['orders'] === [] ? [0] : $split['rows']['orders']);
                    $target = Customer::create([
                        'business_id' => $to,
                        'primary_phone_canonical' => $split['new_customer']['phone'] ?? null,
                        'name' => null,
                        'email' => null,
                        'first_seen_at' => (clone $orders)->min('placed_at') ?? now(),
                        'last_seen_at' => (clone $orders)->max('placed_at') ?? now(),
                        'attributes' => ['created_by_repair' => $batch->key],
                    ]);
                    $created = true;
                    $this->auditor->record('customer.created', $target, after: ['business_id' => $to, 'has_phone' => $target->primary_phone_canonical !== null, 'repair_key' => $batch->key],
                        reason: 'identity repair: a customer of the rows\' own business', actorUserId: $actor->id);
                } else {
                    $target = Customer::query()->findOrFail($split['to_customer_id']);
                }
                $targetId = (int) $target->id;
                $now = now();
                $rows = $split['rows'];
                $moved = [];

                // Identities: back live under the target, in the target's business (the composite keys hold it there).
                $moved['identities'] = $this->update('customer_identities', $rows['identities_move'], fn ($q) => $q->where('customer_id', $source->id),
                    ['customer_id' => $targetId, 'business_id' => $to, 'quarantined_at' => null, 'quarantine_reason' => null, 'updated_at' => $now]);
                $moved['identities_copied'] = [];
                foreach ($rows['identities_copy'] as $copy) {
                    DB::table('customer_identities')->insertOrIgnore([
                        'business_id' => $to, 'customer_id' => $targetId, 'channel' => $copy['channel'], 'external_id' => $copy['external_id'], 'source_store_id' => null,
                        'confidence' => 1.0, 'last_seen_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                    $id = DB::table('customer_identities')->where('business_id', $to)->where('customer_id', $targetId)->where('channel', $copy['channel'])->where('external_id', $copy['external_id'])->whereNull('source_store_id')->value('id');
                    if ($id !== null) {
                        $moved['identities_copied'][] = (int) $id;
                    }
                }
                $moved['identities_superseded'] = $rows['identities_superseded'];

                // Rows of the target business only, and only those under the source customer.
                $moved['orders'] = $this->update('facts_orders', $rows['orders'], fn ($q) => $q->where('customer_id', $source->id)->where('business_id', $to), ['customer_id' => $targetId, 'updated_at' => $now]);
                $moved['conversations'] = $this->update('conversations', $rows['conversations'], fn ($q) => $q->where('customer_id', $source->id)->where('business_id', $to), ['customer_id' => $targetId, 'updated_at' => $now]);
                $moved['participants'] = $this->update('conversation_participants', $rows['participants'],
                    fn ($q) => $q->where('customer_id', $source->id)->whereIn('conversation_id', DB::table('conversations')->where('business_id', $to)->select('id')), ['customer_id' => $targetId, 'updated_at' => $now]);
                $moved['messages'] = $this->update('conversation_messages', $rows['messages'],
                    fn ($q) => $q->where('customer_id', $source->id)->whereIn('conversation_id', DB::table('conversations')->where('business_id', $to)->select('id')), ['customer_id' => $targetId, 'updated_at' => $now]);
                $moved['memories'] = $this->update('memories', $rows['memories'], fn ($q) => $q->where('customer_id', $source->id), ['customer_id' => $targetId, 'updated_at' => $now]);
                $moved['conflicts_resolved'] = $this->update('customer_identity_conflicts', $rows['conflicts_resolve'], fn ($q) => $q->whereNull('resolved_at'),
                    ['resolved_at' => $now, 'resolved_by' => $actor->id, 'resolution' => 'repaired:'.substr($batch->key, 0, 12), 'updated_at' => $now]);

                // Outstanding restrictions of the target business under the source customer are preserved on the target
                // (appended, never moved, never a grant). The source's consent rows are not touched.
                $moved['restrictions_carried'] = $this->carryRestrictions((int) $source->id, $targetId, $to, $moved['identities'], $actor, $reason, $batch->key);

                // A merge explained the rows: the absorbed row's pointer into the other business is cleared. The merge row itself is untouched.
                if ($split['clear_merge_pointer']) {
                    DB::table('customers')->where('id', $targetId)->where('merged_into_id', $source->id)->update(['merged_into_id' => null, 'updated_at' => $now]);
                }

                // Figures from each customer's own business's copies only (docs/122).
                foreach ([$source, $target] as $c) {
                    $own = DB::table('facts_orders')->where('customer_id', $c->id)->where('business_id', $c->business_id);
                    DB::table('customers')->where('id', $c->id)->update([
                        'orders_count' => (clone $own)->count(),
                        'lifetime_value' => (clone $own)->where('status', '!=', 'cancelled')->sum('gross'),
                        'updated_at' => $now,
                    ]);
                }

                $reversal = CustomerMergeReversal::query()->create([
                    'repair_id' => $batch->id,
                    'split_key' => $split['key'],
                    'merge_id' => $split['merge_id'],
                    'from_customer_id' => (int) $source->id,
                    'from_business_id' => (int) $source->business_id,
                    'to_customer_id' => $targetId,
                    'to_business_id' => $to,
                    'to_customer_created' => $created,
                    'moved' => $moved,
                    'withheld' => $split['withheld'],
                    'fingerprint' => $split['fingerprint'],
                    'reason' => $reason,
                    'created_by' => $actor->id,
                ]);
                $this->auditor->record('customer.repair.applied', $target,
                    before: ['from_customer_id' => (int) $source->id, 'from_business_id' => (int) $source->business_id],
                    after: ['reversal_id' => $reversal->id, 'repair_key' => $batch->key, 'merge_id' => $split['merge_id'], 'to_customer_created' => $created,
                        'moved' => array_map(fn ($v) => count((array) $v), $moved),
                        'withheld' => ['memories_unscoped' => count($split['withheld']['memories_unscoped']), 'consent_rerecord' => count($split['withheld']['consent_rerecord'])]],
                    reason: $reason, actorUserId: $actor->id);

                return $base + ['status' => 'applied', 'reversal_id' => $reversal->id, 'to_customer_id' => $targetId, 'to_customer_created' => $created,
                    'merge_id' => $split['merge_id'], 'moved' => $moved, 'withheld' => $split['withheld']];
            });
        } catch (Throwable $e) {
            report($e);

            return $base + ['status' => 'failed', 'why' => get_class($e).': '.$e->getMessage()];
        }
    }

    /**
     * The outstanding restrictions of business `$to` recorded under the source customer: per scope (brand, identity,
     * channel, purpose) the latest event, kept when it is a do-not-contact or a withdrawal. Each is appended to the
     * target's ledger as the same restriction; an identity scope is kept only when that identity now lives under the
     * target (else the restriction is widened to every identity: broader, never narrower).
     *
     * @param  list<int>  $movedIdentities
     * @return list<array{from_event: int, to_event: int, state: string}>
     */
    private function carryRestrictions(int $from, int $targetId, int $to, array $movedIdentities, User $actor, string $reason, string $repairKey): array
    {
        $latest = [];
        foreach (DB::table('consent_events')->where('customer_id', $from)->where('business_id', $to)->orderBy('occurred_at')->orderBy('id')->get() as $e) {
            $latest[($e->brand_id ?? '*').'|'.($e->identity_id ?? '*').'|'.($e->channel ?? '*').'|'.($e->purpose ?? '*')] = $e;
        }
        $carried = [];
        foreach ($latest as $e) {
            if (! in_array($e->state, [ConsentState::DoNotContact->value, ConsentState::Withdrawn->value], true)) {
                continue;
            }
            $identityId = $e->identity_id !== null && in_array((int) $e->identity_id, $movedIdentities, true) ? (int) $e->identity_id : null;
            $event = $this->consent->record(
                customerId: $targetId,
                businessId: $to,
                purpose: $e->purpose,
                state: ConsentState::from($e->state),
                basis: ConsentBasis::Operator,
                channel: $e->channel,
                attributes: [
                    'brand_id' => $e->brand_id === null ? null : (int) $e->brand_id,
                    'identity_id' => $identityId,
                    'evidence_ref' => "consent_events#{$e->id}",
                    'evidence' => ['act' => 'restriction_carried_by_identity_repair', 'from_event_id' => (int) $e->id, 'from_customer_id' => $from, 'repair_key' => $repairKey],
                    'actor_type' => 'human',
                    'actor_id' => $actor->id,
                    'actor_label' => $actor->email,
                    'reason' => 'identity repair: restriction preserved from consent event #'.$e->id.' (recorded under another customer); re-record in the correct scope. '.$reason,
                ],
            );
            $carried[] = ['from_event' => (int) $e->id, 'to_event' => (int) $event->id, 'state' => (string) $e->state];
        }

        return $carried;
    }

    /**
     * The ambiguous rows of this business as quarantine findings, one per row (an ambiguous split names every row
     * it would have moved).
     *
     * @param  list<array<string, mixed>>  $ambiguous
     * @return list<Finding>
     */
    private function ambiguousFindings(array $ambiguous, int $businessId): array
    {
        $out = [];
        foreach ($ambiguous as $a) {
            $rowBusiness = $a['row_business_id'] === null ? null : (int) $a['row_business_id'];
            if ($rowBusiness !== $businessId && (int) $a['customer_business_id'] !== $businessId) {
                continue;
            }
            $detail = ['why' => $a['why'], 'finding_reason' => $a['finding_reason'] ?? null];
            if ($a['kind'] !== 'split') {
                $out[] = new Finding($a['kind'], $a['subject_table'], (int) $a['subject_id'], (int) $a['customer_id'], (int) $a['customer_business_id'],
                    $rowBusiness, null, $a['reason'], $detail);

                continue;
            }
            $rows = [];
            foreach ($a['subjects'] as $subject) {
                [$table, $id] = explode(':', $subject, 3);
                $rows["{$table}:{$id}"] = [$table, (int) $id];
            }
            ksort($rows);
            foreach ($rows as [$table, $id]) {
                $out[] = new Finding(self::KIND_BY_TABLE[$table] ?? 'merge_pointer', $table, $id, (int) $a['customer_id'], (int) $a['customer_business_id'],
                    $rowBusiness, null, $a['reason'], $detail);
            }
        }

        return $out;
    }

    /**
     * Updates the listed rows that still match the guard, returning the ids actually changed.
     *
     * @param  list<int>  $ids
     * @param  callable(Builder): Builder  $guard
     * @param  array<string, mixed>  $values
     * @return list<int>
     */
    private function update(string $table, array $ids, callable $guard, array $values): array
    {
        if ($ids === []) {
            return [];
        }
        $matching = $guard(DB::table($table)->whereIn('id', $ids))->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($matching !== []) {
            DB::table($table)->whereIn('id', $matching)->update($values);
        }

        return $matching;
    }

    /** @return list<int> */
    private function affectedBusinesses(CustomerIdentityRepair $batch): array
    {
        $ids = [];
        foreach ($batch->reversals()->get() as $r) {
            $ids[] = (int) $r->from_business_id;
            $ids[] = (int) $r->to_business_id;
        }
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    /** @return list<int> */
    private function affectedCustomers(CustomerIdentityRepair $batch): array
    {
        $ids = [];
        foreach ($batch->reversals()->get() as $r) {
            $ids[] = (int) $r->from_customer_id;
            $ids[] = (int) $r->to_customer_id;
        }
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }
}
