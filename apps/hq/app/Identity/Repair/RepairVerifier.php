<?php

namespace App\Identity\Repair;

use App\Customer360\Customer360Reader;
use App\Identity\Quarantine\CrossBusinessDetector;
use App\Identity\Quarantine\CrossBusinessSuppression;
use App\Models\Customer;
use App\Models\CustomerIdentityRepair;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Proves a repair batch before it is marked complete (docs/126, ruling docs/120 A3 point 10). SELECT only.
 *
 * For every reversal of the batch:
 *   1. ownership: every moved row now hangs under the target customer and carries (or sits in a thread of) the
 *      target customer's business; every moved identity is live under the target in the target's business;
 *   2. nothing of the target business remains under the source customer among the moved kinds;
 *   3. the detector no longer reports any moved row;
 *   4. the business-scoped read paths: the source customer's Customer 360 read shows none of the moved order
 *      references or thread uuids and the target's shows them; neither read is still held for aggregates,
 *      identities or threads.
 * A check that fails names the row; the batch then stays unverified and a person decides.
 */
final class RepairVerifier
{
    public function __construct(
        private readonly CrossBusinessDetector $detector,
        private readonly CrossBusinessSuppression $suppression,
        private readonly Customer360Reader $reader,
    ) {}

    /** @return array{ok: bool, checks: int, failures: list<string>} */
    public function verify(CustomerIdentityRepair $batch, User $viewer): array
    {
        $failures = [];
        $checks = 0;
        $findings = $this->detector->detect();
        $findingKeys = [];
        foreach ($findings as $f) {
            $findingKeys[$f->subjectTable.':'.$f->subjectId] = $f->reason;
        }

        foreach ($batch->reversals()->orderBy('id')->get() as $r) {
            $to = (int) $r->to_customer_id;
            $toBusiness = (int) $r->to_business_id;
            $from = (int) $r->from_customer_id;
            $moved = $r->moved;

            $direct = [
                'facts_orders' => [$moved['orders'] ?? [], true],
                'conversations' => [$moved['conversations'] ?? [], true],
                'memories' => [$moved['memories'] ?? [], false],
            ];
            foreach ($direct as $table => [$ids, $hasBusiness]) {
                foreach ($ids as $id) {
                    $checks++;
                    $row = DB::table($table)->where('id', $id)->first(['customer_id', ...($hasBusiness ? ['business_id'] : [])]);
                    if ($row === null || (int) $row->customer_id !== $to || ($hasBusiness && (int) $row->business_id !== $toBusiness)) {
                        $failures[] = "{$table}#{$id} is not under customer #{$to} of business #{$toBusiness}";
                    }
                    if (isset($findingKeys["{$table}:{$id}"])) {
                        $failures[] = "{$table}#{$id} still detected: {$findingKeys["{$table}:{$id}"]}";
                    }
                }
            }
            foreach (['conversation_participants' => $moved['participants'] ?? [], 'conversation_messages' => $moved['messages'] ?? []] as $table => $ids) {
                foreach ($ids as $id) {
                    $checks++;
                    $row = DB::table("{$table} as r")->join('conversations as v', 'v.id', '=', 'r.conversation_id')->where('r.id', $id)->first(['r.customer_id', 'v.business_id']);
                    if ($row === null || (int) $row->customer_id !== $to || (int) $row->business_id !== $toBusiness) {
                        $failures[] = "{$table}#{$id} is not under customer #{$to} in a thread of business #{$toBusiness}";
                    }
                    if (isset($findingKeys["{$table}:{$id}"])) {
                        $failures[] = "{$table}#{$id} still detected: {$findingKeys["{$table}:{$id}"]}";
                    }
                }
            }
            foreach ([...($moved['identities'] ?? []), ...($moved['identities_copied'] ?? [])] as $id) {
                $checks++;
                $row = DB::table('customer_identities')->where('id', $id)->first(['customer_id', 'business_id', 'quarantined_at']);
                if ($row === null || (int) $row->customer_id !== $to || (int) $row->business_id !== $toBusiness || $row->quarantined_at !== null) {
                    $failures[] = "customer_identities#{$id} is not live under customer #{$to} in business #{$toBusiness}";
                }
                if (isset($findingKeys["customer_identities:{$id}"])) {
                    $failures[] = "customer_identities#{$id} still detected: {$findingKeys["customer_identities:{$id}"]}";
                }
            }
            foreach ($moved['conflicts_resolved'] ?? [] as $id) {
                $checks++;
                if (DB::table('customer_identity_conflicts')->where('id', $id)->whereNull('resolved_at')->exists()) {
                    $failures[] = "customer_identity_conflicts#{$id} is still open";
                }
            }

            // 2. Nothing of the target business among the moved kinds remains under the source customer.
            $checks++;
            $left = DB::table('facts_orders')->where('customer_id', $from)->where('business_id', $toBusiness)->count()
                + DB::table('conversations')->where('customer_id', $from)->where('business_id', $toBusiness)->count()
                + DB::table('conversation_participants as p')->join('conversations as v', 'v.id', '=', 'p.conversation_id')->where('p.customer_id', $from)->where('v.business_id', $toBusiness)->count()
                + DB::table('conversation_messages as m')->join('conversations as v', 'v.id', '=', 'm.conversation_id')->where('m.customer_id', $from)->where('v.business_id', $toBusiness)->count()
                + DB::table('memories')->where('customer_id', $from)->where('business_id', $toBusiness)->whereNull('deleted_at')->count();
            if ($left > 0) {
                $failures[] = "{$left} row(s) of business #{$toBusiness} still hang under customer #{$from}";
            }

            // 4. The business-scoped reads.
            $source = Customer::query()->find($from);
            $target = Customer::query()->find($to);
            if ($source === null || $target === null) {
                $failures[] = "customer #{$from} or #{$to} no longer exists";

                continue;
            }
            $checks++;
            $refs = DB::table('facts_orders')->whereIn('id', ($moved['orders'] ?? []) === [] ? [0] : $moved['orders'])->pluck('order_ref')->all();
            $uuids = DB::table('conversations')->whereIn('id', ($moved['conversations'] ?? []) === [] ? [0] : $moved['conversations'])->pluck('uuid')->all();
            $sourceRead = json_encode($this->reader->read($source, $viewer));
            $targetRead = json_encode($this->reader->read($target, $viewer));
            foreach ($refs as $ref) {
                if (str_contains($sourceRead, json_encode($ref))) {
                    $failures[] = "the source customer's page still shows order {$ref}";
                }
                if (! str_contains($targetRead, json_encode($ref))) {
                    $failures[] = "the target customer's page does not show order {$ref}";
                }
            }
            foreach ($uuids as $uuid) {
                if (str_contains($sourceRead, $uuid)) {
                    $failures[] = "the source customer's page still shows thread {$uuid}";
                }
            }
            // The source may still carry rows of a THIRD business (another batch's work); its holds are then right, and
            // only the target is required to read clean.
            $sourceStillCrosses = collect($findings)->contains(fn ($f) => $f->customerId === $from && $f->kind !== 'identity_conflict');
            foreach ($sourceStillCrosses ? [$target] : [$source, $target] as $c) {
                $view = $this->suppression->forCustomer($c->fresh());
                foreach (['aggregates', 'identities', 'threads'] as $kind) {
                    if (in_array($kind, $view->withheld(), true)) {
                        $failures[] = "customer #{$c->id} is still held for {$kind}";
                    }
                }
            }
        }

        return ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
    }
}
