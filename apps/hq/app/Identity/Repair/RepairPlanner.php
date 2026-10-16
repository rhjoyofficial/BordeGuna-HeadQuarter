<?php

namespace App\Identity\Repair;

use App\Identity\Quarantine\CrossBusinessDetector;
use App\Identity\Quarantine\Finding;
use App\Models\Business;
use App\Models\CrossBusinessQuarantine;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Proposes the repair of rows that hang under a customer of another business (docs/126, ruling docs/120 A3).
 *
 * ⛔ READ-ONLY. Every statement is a SELECT; the planner persists nothing and the same rows yield the same plan.
 *
 * The rule, in one sentence: a row goes to a customer of ITS OWN business, found by evidence or created, and a
 * row whose business the evidence cannot establish is not moved but reported (and, on apply, quarantined).
 *
 * One split per (source customer, target business) pair. Rows with a certain business of their own: order copies
 * (`facts_orders.business_id`), conversations (`conversations.business_id`), participants and messages (their
 * thread's business), memories with a business or a brand, store-scoped identities (their store's business), and
 * identities a cross-business merge recorded as moved (the absorbed customer's business). The target customer is:
 *   1. the absorbed customer, when a cross-business merge explains the rows (docs/117 §6: no new customer);
 *   2. otherwise the ONE customer of the target business the rows' own keys name (a live shop_order, shop_user or
 *      phone identity of that business, or a customer whose stored phone is the order's), excluding the source;
 *   3. otherwise a new customer of the target business, built from the rows' own evidence (the order's phone; the
 *      source customer's name only when that phone is the source customer's own);
 *   4. when more than one customer of the target business matches: AMBIGUOUS, nothing moved, reported.
 * Business-less memories under the source customer are never moved: they are listed as withheld (A3 point 8).
 * Consent rows are never moved (A3 point 9): the rows of the target business under the source customer are listed
 * for a person to re-record, restrictions first.
 */
final class RepairPlanner
{
    public function __construct(private readonly CrossBusinessDetector $detector) {}

    public function plan(): RepairPlan
    {
        $findings = $this->detector->detect();
        $merges = $this->detector->crossMerges()->keyBy('id');

        $groups = [];
        $ambiguous = [];
        $heldIdentities = [];
        $openConflicts = [];
        $movedIdentityIds = [];

        foreach ($findings as $f) {
            $g = null;
            switch ($f->reason) {
                case 'cross_business_merge':
                    if (($f->detail['reversed_by'] ?? null) !== null) {
                        break; // repaired already: lineage only
                    }
                    $g = &$this->group($groups, $f->customerId, (int) $f->rowBusinessId);
                    $g['merge_id'] = $f->subjectId;
                    $g['subjects'][] = $f->key();
                    break;
                case 'cross_business_merged_into':
                    // The absorbed row: its pointer into another business is cleared by the split of the kept side.
                    $g = &$this->group($groups, (int) $f->detail['merged_into_id'], $f->customerBusinessId);
                    $g['clear_merge_pointer_for'] = $f->subjectId;
                    $g['subjects'][] = $f->key();
                    break;
                case 'identity_moved_by_cross_business_merge':
                case 'identity_store_other_business':
                    $g = &$this->group($groups, $f->customerId, (int) $f->rowBusinessId);
                    $g['identities'][$f->subjectId] = true;
                    $g['subjects'][] = $f->key();
                    $movedIdentityIds[$f->subjectId] = (int) $f->rowBusinessId;
                    break;
                case 'identity_quarantined':
                    // A store-less row a cross-business merge moved names the absorbed business through that merge's lineage.
                    $rowBusiness = $f->rowBusinessId ?? ($f->mergeId !== null && $merges->has($f->mergeId) ? (int) $merges->get($f->mergeId)->absorbed_business_id : null);
                    if ($rowBusiness !== null && $rowBusiness !== $f->customerBusinessId) {
                        $g = &$this->group($groups, $f->customerId, $rowBusiness);
                        $g['identities'][$f->subjectId] = true;
                        $g['subjects'][] = $f->key();
                        $movedIdentityIds[$f->subjectId] = $rowBusiness;
                    } else {
                        $heldIdentities[] = ['identity_id' => $f->subjectId, 'customer_id' => $f->customerId, 'business_id' => $f->customerBusinessId,
                            'channel' => $f->detail['channel'], 'quarantine_reason' => $f->detail['quarantine_reason'],
                            'why' => 'its business cannot be established from the rows without guessing'];
                    }
                    break;
                case 'identity_conflict_quarantined':
                case 'identity_conflict_link_refused':
                    // Resolved by the split that moves the identity; otherwise a person's.
                    $openConflicts[] = ['conflict_id' => $f->subjectId, 'kind' => substr($f->reason, strlen('identity_conflict_')), 'identity_id' => $f->detail['identity_id'],
                        'customer_id' => $f->customerId, 'reason' => $f->detail['conflict_reason'], 'claimant_customer_id' => $f->detail['claimant_customer_id'], 'occurrences' => $f->detail['occurrences']];
                    break;
                case 'order_other_business':
                case 'conversation_other_business':
                case 'participant_other_business':
                case 'message_other_business':
                case 'memory_other_business':
                    $g = &$this->group($groups, $f->customerId, (int) $f->rowBusinessId);
                    $g[$f->subjectTable][] = $f->subjectId;
                    $g['subjects'][] = $f->key();
                    break;
                case 'memory_unscoped_under_cross_business_merge':
                    $m = $merges->get($f->mergeId);
                    $g = &$this->group($groups, $f->customerId, (int) $m->absorbed_business_id);
                    $g['memories_unscoped'][] = $f->subjectId;
                    $g['subjects'][] = $f->key();
                    break;
                case 'consent_other_business':
                    $g = &$this->group($groups, $f->customerId, (int) $f->rowBusinessId);
                    $g['consent'][] = [$f->subjectTable, $f->subjectId];
                    $g['subjects'][] = $f->key();
                    break;
                case 'participant_identity_other_business':
                    $ambiguous[] = $this->ambiguousRow($f, 'the participant carries an identity of another business than its thread; which customer it names is a person\'s call');
                    break;
                default:
                    $ambiguous[] = $this->ambiguousRow($f, 'a finding of a kind the repair does not resolve');
            }
            unset($g);
        }

        // A `quarantined` conflict on an identity a split actually moves back live is resolved by that split; every
        // other conflict (a refused re-point, a held row the split cannot move) stays open for a person.
        $resolvedByMove = [];
        foreach ($openConflicts as $c) {
            if (isset($movedIdentityIds[$c['identity_id']]) && $c['kind'] === 'quarantined') {
                $resolvedByMove[$c['identity_id']][] = $c['conflict_id'];
            }
        }
        // An identity a split carries is not also reported as held.
        $heldIdentities = array_values(array_filter($heldIdentities, fn (array $h) => ! isset($movedIdentityIds[$h['identity_id']])));
        $resolvedBySplits = [];

        $slugs = Business::query()->pluck('slug', 'id');
        $batches = [];
        $human = ['consent_rerecord' => [], 'memories_unscoped' => []];
        foreach ($groups as $pairKey => $group) {
            [$from, $to] = array_map('intval', explode(':', $pairKey));
            $split = $this->split($from, $to, $group, $merges, $resolvedByMove);
            if (isset($split['ambiguous'])) {
                $ambiguous[] = $split['ambiguous'];

                continue;
            }
            foreach ($split['ambiguous_rows'] as $row) {
                $ambiguous[] = $row;
            }
            unset($split['ambiguous_rows']);
            foreach ($split['withheld']['consent_rerecord'] as $c) {
                $human['consent_rerecord'][] = $c + ['customer_id' => $from, 'business_id' => $to];
            }
            foreach ($split['withheld']['memories_unscoped'] as $id) {
                $human['memories_unscoped'][$id] = ['memory_id' => $id, 'customer_id' => $from];
            }
            // A group with nothing to move (consent rows only, or only business-less memories) creates no customer:
            // what it lists is a person's work and nothing else.
            if (RepairPlan::splitRowCount($split) === 0 && $split['merge_id'] === null) {
                continue;
            }
            foreach ($split['rows']['conflicts_resolve'] as $conflictId) {
                $resolvedBySplits[$conflictId] = true;
            }
            $batches[$to] ??= ['key' => '', 'business_id' => $to, 'business_slug' => (string) ($slugs[$to] ?? $to), 'splits' => []];
            $batches[$to]['splits'][] = $split;
        }
        ksort($batches);
        ksort($human['memories_unscoped']);
        $human['memories_unscoped'] = array_values($human['memories_unscoped']);
        $openConflicts = array_values(array_filter($openConflicts, fn (array $c) => ! isset($resolvedBySplits[$c['conflict_id']])));
        foreach ($batches as $to => &$batch) {
            usort($batch['splits'], fn ($a, $b) => $a['from_customer_id'] <=> $b['from_customer_id']);
            // The key names the rows AND their state (each split's fingerprint): the dry run a person read is the only
            // thing the key applies. Any change to a row since then yields another key, refused until looked at again.
            $batch['key'] = hash('sha256', $to.'|'.implode(',', array_map(fn (array $s) => $s['key'].':'.$s['fingerprint'], $batch['splits'])));
        }
        unset($batch);

        return new RepairPlan(now()->toIso8601String(), $batches, $ambiguous, $heldIdentities, $openConflicts, $human);
    }

    /** @param  array<string, array<string, mixed>>  $groups */
    private function &group(array &$groups, int $fromCustomerId, int $toBusinessId): array
    {
        $groups["{$fromCustomerId}:{$toBusinessId}"] ??= [
            'merge_id' => null, 'clear_merge_pointer_for' => null, 'identities' => [], 'facts_orders' => [], 'conversations' => [],
            'conversation_participants' => [], 'conversation_messages' => [], 'memories' => [], 'memories_unscoped' => [], 'consent' => [], 'subjects' => [],
        ];

        return $groups["{$fromCustomerId}:{$toBusinessId}"];
    }

    /** @return array<string, mixed> */
    private function ambiguousRow(Finding $f, string $why): array
    {
        return ['kind' => $f->kind, 'subject_table' => $f->subjectTable, 'subject_id' => $f->subjectId, 'customer_id' => $f->customerId,
            'customer_business_id' => $f->customerBusinessId, 'row_business_id' => $f->rowBusinessId, 'reason' => 'repair_ambiguous_'.$f->kind,
            'finding_reason' => $f->reason, 'why' => $why];
    }

    /**
     * @param  array<string, mixed>  $group
     * @param  array<int, list<int>>  $resolvedByMove
     * @return array<string, mixed>
     */
    private function split(int $from, int $to, array $group, Collection $merges, array $resolvedByMove): array
    {
        $source = Customer::query()->findOrFail($from);
        $fromBusiness = (int) $source->business_id;
        $identityIds = array_map('intval', array_keys($group['identities']));
        sort($identityIds);
        $identities = CustomerIdentity::query()->whereIn('id', $identityIds === [] ? [0] : $identityIds)->orderBy('id')->get();
        $orders = DB::table('facts_orders')->whereIn('id', $group['facts_orders'] === [] ? [0] : $group['facts_orders'])->orderBy('id')->get();

        // 1. The target customer.
        $targetId = null;
        $created = false;
        $mergeId = $group['merge_id'];
        if ($mergeId !== null) {
            $m = $merges->get($mergeId);
            $absorbed = Customer::query()->find($m->absorbed_id);
            if ($absorbed !== null && (int) $absorbed->business_id === $to) {
                $targetId = (int) $absorbed->id;
            }
        }
        if ($targetId === null) {
            $candidates = $this->candidates($to, $from, $identities, $orders);
            if (count($candidates) > 1) {
                return ['ambiguous' => ['kind' => 'split', 'subject_table' => 'customers', 'subject_id' => $from, 'customer_id' => $from,
                    'customer_business_id' => $fromBusiness, 'row_business_id' => $to, 'reason' => 'repair_ambiguous_target',
                    'why' => 'more than one customer of the target business matches the rows\' own keys: '.implode(', ', $candidates),
                    'subjects' => $group['subjects']]];
            }
            $targetId = $candidates[0] ?? null;
            $created = $targetId === null;
        }

        // 2. Identities: a quarantined store-scoped row (or one a merge moved) goes back live under the target, unless the
        //    target already holds that key live (then the held row stays where it is as evidence and its conflict stays
        //    open for a person: two rows claim one key, and which one is right is not the repair's guess).
        //    An identity a thread participant or a consent row of ANOTHER business than the target still uses cannot move
        //    without creating a new crossing: its business is not established by evidence, so it stays where it is,
        //    held, and is reported (A3 last paragraph: quarantine, never guess).
        $move = [];
        $superseded = [];
        $ambiguousRows = [];
        foreach ($identities as $i) {
            $usedElsewhere = $this->identityUsedOutside((int) $i->id, $to);
            if ($usedElsewhere !== []) {
                $ambiguousRows[] = ['kind' => 'identity', 'subject_table' => 'customer_identities', 'subject_id' => (int) $i->id, 'customer_id' => $from,
                    'customer_business_id' => $fromBusiness, 'row_business_id' => $to, 'reason' => 'repair_ambiguous_identity',
                    'finding_reason' => 'identity_used_by_two_businesses',
                    'why' => 'the identity is also used by rows of another business ('.implode(', ', $usedElsewhere).'); moving it would create a new crossing'];

                continue;
            }
            $live = CustomerIdentity::query()->key($to, $i->channel, $i->external_id, $i->source_store_id === null ? null : (int) $i->source_store_id)->first();
            if ($live !== null && (int) $live->id !== (int) $i->id) {
                $superseded[] = (int) $i->id;
            } else {
                $move[] = (int) $i->id;
            }
        }
        $copy = [];
        foreach ($orders->pluck('customer_phone_canonical')->filter()->unique()->sort()->values() as $phone) {
            $existing = CustomerIdentity::query()->key($to, 'phone', (string) $phone, null)->first();
            if ($existing === null) {
                $copy[] = ['channel' => 'phone', 'external_id' => (string) $phone];
            }
        }
        $conflicts = [];
        foreach ($move as $id) {
            foreach ($resolvedByMove[$id] ?? [] as $conflictId) {
                $conflicts[] = (int) $conflictId;
            }
        }
        sort($conflicts);

        // 3. What is withheld: every business-less memory under the source customer (A3 point 8), and the consent rows
        //    of the target business under the source customer, restrictions first (A3 point 9).
        $unscoped = DB::table('memories')->where('customer_id', $from)->whereNull('business_id')->whereNull('brand_id')->whereNull('deleted_at')
            ->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $consent = [];
        foreach ($group['consent'] as [$table, $id]) {
            $row = DB::table($table)->where('id', $id)->first(['id', 'state', 'channel', 'purpose', 'brand_id']);
            if ($row !== null) {
                $consent[] = ['table' => $table, 'id' => (int) $row->id, 'state' => $row->state, 'channel' => $row->channel, 'purpose' => $row->purpose,
                    'brand_id' => $row->brand_id === null ? null : (int) $row->brand_id,
                    'restriction' => in_array($row->state, ['withdrawn', 'do_not_contact'], true)];
            }
        }
        usort($consent, fn ($a, $b) => [$b['restriction'], $a['table'], $a['id']] <=> [$a['restriction'], $b['table'], $b['id']]);

        $rows = [
            'orders' => $this->ints($group['facts_orders']),
            'conversations' => $this->ints($group['conversations']),
            'participants' => $this->ints($group['conversation_participants']),
            'messages' => $this->ints($group['conversation_messages']),
            'memories' => $this->ints($group['memories']),
            'identities_move' => $move,
            'identities_superseded' => $superseded,
            'identities_copy' => $copy,
            'conflicts_resolve' => $conflicts,
        ];
        $subjects = array_values(array_unique($group['subjects']));
        sort($subjects);
        $phone = $orders->pluck('customer_phone_canonical')->filter()->unique()->count() === 1 ? (string) $orders->pluck('customer_phone_canonical')->filter()->first() : null;

        $split = [
            'key' => hash('sha256', "{$from}:{$to}:".implode(',', $subjects)),
            'from_customer_id' => $from,
            'from_business_id' => $fromBusiness,
            'to_business_id' => $to,
            'to_customer_id' => $targetId,
            'to_customer_created' => $created,
            'merge_id' => $mergeId === null ? null : (int) $mergeId,
            'clear_merge_pointer' => $group['clear_merge_pointer_for'] !== null && $targetId !== null && (int) $group['clear_merge_pointer_for'] === $targetId,
            // Built from the rows' own evidence only: the order's phone. Never the source customer's name, e-mail or
            // anything else of the other business (that would be the leak again, in the other direction).
            'new_customer' => $created ? ['phone' => $phone, 'name' => null] : null,
            'rows' => $rows,
            'withheld' => ['memories_unscoped' => array_values(array_unique([...$this->ints($group['memories_unscoped']), ...$unscoped])), 'consent_rerecord' => $consent],
            'subjects' => $subjects,
            'review' => $this->review($subjects),
        ];
        sort($split['withheld']['memories_unscoped']);
        $split['fingerprint'] = $this->fingerprint($split, $source);
        $split['ambiguous_rows'] = $ambiguousRows;

        return $split;
    }

    /**
     * The customers of the target business the rows' own keys name, canonical, source excluded, sorted.
     *
     * @param  Collection<int, CustomerIdentity>  $identities
     * @param  Collection<int, object>  $orders
     * @return list<int>
     */
    private function candidates(int $to, int $from, Collection $identities, Collection $orders): array
    {
        $keys = [];
        foreach ($identities as $i) {
            $keys[] = [$i->channel, $i->external_id, $i->source_store_id === null ? null : (int) $i->source_store_id];
        }
        foreach ($orders as $o) {
            $keys[] = ['shop_order', (string) $o->order_ref, (int) $o->store_id];
            if ($o->customer_phone_canonical) {
                $keys[] = ['phone', (string) $o->customer_phone_canonical, null];
            }
        }
        $found = [];
        foreach ($keys as [$channel, $externalId, $storeId]) {
            $identity = CustomerIdentity::query()->key($to, $channel, $externalId, $storeId)->first();
            $customer = $identity?->customer?->canonical();
            if ($customer !== null && (int) $customer->business_id === $to && (int) $customer->id !== $from) {
                $found[(int) $customer->id] = true;
            }
        }
        foreach ($orders->pluck('customer_phone_canonical')->filter()->unique() as $phone) {
            foreach (Customer::query()->where('business_id', $to)->where('primary_phone_canonical', $phone)->whereNull('merged_into_id')->where('id', '!=', $from)->pluck('id') as $id) {
                $found[(int) $id] = true;
            }
        }
        $ids = array_map('intval', array_keys($found));
        sort($ids);

        return $ids;
    }

    /**
     * The review state of this split's rows (docs/117 §5, ruling docs/120 item 36: a repair executes only after an
     * explicit `needs_repair` review). Counted per subject ROW, whatever reasons the detector flagged it under: a row
     * is disputed when any of its flags' latest decision is `disputed`, reviewed when any has a decision, and
     * unreviewed when none has (flagged or not). Authorized only when every row was reviewed, at least one as
     * `needs_repair`, and none is disputed: nothing moves that a person has not looked at.
     *
     * @param  list<string>  $subjects
     * @return array{needs_repair: int, disputed: int, confirmed_wrong: int, unflagged: int, authorized: bool, unreviewed_rows: list<string>, flag_ids: list<int>}
     */
    private function review(array $subjects): array
    {
        $rows = [];
        foreach ($subjects as $subject) {
            [$table, $id] = explode(':', $subject, 3);
            $rows["{$table}:{$id}"] = [$table, (int) $id];
        }
        ksort($rows);
        $counts = ['needs_repair' => 0, 'disputed' => 0, 'confirmed_wrong' => 0, 'unflagged' => 0];
        $unreviewed = [];
        $flagIds = [];
        foreach ($rows as $key => [$table, $id]) {
            $decisions = [];
            foreach (CrossBusinessQuarantine::query()->with('latestReview')->where('subject_table', $table)->where('subject_id', $id)->orderBy('id')->get() as $flag) {
                $flagIds[] = (int) $flag->id;
                $decision = $flag->latestReview?->decision?->value;
                if ($decision !== null) {
                    $decisions[$decision] = true;
                }
            }
            $state = isset($decisions['disputed']) ? 'disputed' : (isset($decisions['needs_repair']) ? 'needs_repair' : (isset($decisions['confirmed_wrong']) ? 'confirmed_wrong' : null));
            if ($state === null) {
                $counts['unflagged']++;
                $unreviewed[] = $key;
            } else {
                $counts[$state]++;
            }
        }
        sort($flagIds);

        return $counts + [
            'authorized' => $counts['needs_repair'] > 0 && $counts['disputed'] === 0 && $counts['unflagged'] === 0,
            'unreviewed_rows' => $unreviewed,
            'flag_ids' => $flagIds,
        ];
    }

    /**
     * Where, outside the target business, thread participants or consent rows reference this identity. SELECT only.
     *
     * @return list<string>
     */
    private function identityUsedOutside(int $identityId, int $to): array
    {
        $uses = [];
        foreach (DB::table('conversation_participants as p')->join('conversations as v', 'v.id', '=', 'p.conversation_id')
            ->where('p.identity_id', $identityId)->where('v.business_id', '!=', $to)->orderBy('p.id')->pluck('p.id') as $id) {
            $uses[] = "conversation_participants#{$id}";
        }
        foreach (['consent_events', 'consent_state'] as $table) {
            foreach (DB::table($table)->where('identity_id', $identityId)->where('business_id', '!=', $to)->orderBy('id')->pluck('id') as $id) {
                $uses[] = "{$table}#{$id}";
            }
        }

        return $uses;
    }

    /**
     * A digest of every row the split would touch, as it is now: the applier recomputes it under lock and refuses
     * the split when it differs (A3 point 6, protection against concurrent changes).
     *
     * @param  array<string, mixed>  $split
     */
    private function fingerprint(array $split, Customer $source): string
    {
        // The customers by what decides the split (business, merge pointer, the chosen target), not by `updated_at`:
        // an unrelated touch of a customer (a recount, a new order of its own business) is not a change to this split.
        $parts = ['customers:'.$source->id.':'.$source->business_id.':'.($source->merged_into_id ?? '-'), 'target:'.($split['to_customer_id'] ?? 'new')];
        if ($split['to_customer_id'] !== null) {
            $t = DB::table('customers')->where('id', $split['to_customer_id'])->first(['business_id', 'merged_into_id']);
            $parts[] = 'customers:'.$split['to_customer_id'].':'.($t?->business_id ?? '-').':'.($t?->merged_into_id ?? '-');
        }
        $tables = [
            'facts_orders' => [$split['rows']['orders'], ['customer_id', 'business_id', 'updated_at']],
            'conversations' => [$split['rows']['conversations'], ['customer_id', 'business_id', 'updated_at']],
            'conversation_participants' => [$split['rows']['participants'], ['customer_id', 'conversation_id', 'identity_id']],
            'conversation_messages' => [$split['rows']['messages'], ['customer_id', 'conversation_id']],
            'memories' => [[...$split['rows']['memories'], ...$split['withheld']['memories_unscoped']], ['customer_id', 'business_id', 'updated_at']],
            'customer_identities' => [[...$split['rows']['identities_move'], ...$split['rows']['identities_superseded']], ['customer_id', 'business_id', 'quarantined_at', 'updated_at']],
            'customer_identity_conflicts' => [$split['rows']['conflicts_resolve'], ['resolved_at', 'occurrences']],
            'customer_merges' => [$split['merge_id'] === null ? [] : [$split['merge_id']], ['kept_id', 'absorbed_id', 'undone_at']],
        ];
        foreach ($tables as $table => [$ids, $columns]) {
            $ids = array_values(array_unique($ids));
            sort($ids);
            if ($ids === []) {
                continue;
            }
            foreach (DB::table($table)->whereIn('id', $ids)->orderBy('id')->get(['id', ...$columns]) as $row) {
                $parts[] = $table.':'.implode(':', array_map(fn ($c) => (string) ($row->$c ?? '-'), ['id', ...$columns]));
            }
        }

        return hash('sha256', implode("\n", $parts));
    }

    /**
     * @param  list<int|string>  $ids
     * @return list<int>
     */
    private function ints(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        return $ids;
    }
}
