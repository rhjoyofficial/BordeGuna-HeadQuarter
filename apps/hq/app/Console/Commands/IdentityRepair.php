<?php

namespace App\Console\Commands;

use App\Gateway\OutcomeStatus;
use App\Gateway\ToolGateway;
use App\Gateway\ToolRequest;
use App\Identity\Repair\RepairPlan;
use App\Identity\Repair\RepairPlanner;
use App\Identity\Repair\RepairRecount;
use App\Models\Business;
use App\Models\User;
use App\Tools\Identity\QuarantineRepairTool;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * docs/126 (ruling docs/120 A3, items 36 and 37): the dry run of the cross-business repair. Prints the proposed
 * customer splits, row counts, lineage, the review state of each split, the ambiguous cases, what stays held and
 * what only a person may do, and the batch key per target business. Writes nothing.
 *
 * `--apply` does NOT bypass anything: it asks the Tool Gateway for the human-only tool `customers.quarantine.repair`
 * as the person named by `--by`, with their permission, scope and audit, exactly as the quarantine screen does. The
 * tool still refuses a changed batch, an unreviewed split and an ambiguous row. On production the dry run comes
 * first, Joy reads it, reviews the flags as needs_repair, and only then authorizes `--apply`.
 */
final class IdentityRepair extends Command
{
    protected $signature = 'hq:identity:repair
        {--recount : also print the business-scoped recount, verified against the source rows (item 37)}
        {--json : print the plan as JSON}
        {--apply : apply ONE reviewed batch through the Tool Gateway (needs --key, --by and --reason)}
        {--key= : the batch key the dry run printed}
        {--by= : email of the HQ user whose decision this is}
        {--reason= : why, recorded on the batch, every reversal and every audit row}';

    protected $description = 'Dry run of the cross-business identity repair (ruling A3): proposed splits, counts, lineage, ambiguous cases; --apply goes through the human-only gateway tool.';

    public function handle(RepairPlanner $planner, RepairRecount $recount, ToolGateway $gateway): int
    {
        $plan = $planner->plan();

        if ($this->option('json')) {
            $this->line(json_encode($plan->toArray() + ['recount' => $this->option('recount') ? $recount->recount(Business::query()->pluck('id')->map(fn ($id) => (int) $id)->all()) : null], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->printPlan($plan);
            if ($this->option('recount')) {
                $this->printRecount($recount->recount(Business::query()->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all(), $this->affectedCustomers($plan)));
            }
        }

        if (! $this->option('apply')) {
            $this->comment('Dry run: nothing written. Review the flags as needs_repair on /customers/quarantine, then apply ONE batch with: '
                .'php artisan hq:identity:repair --apply --key=<key> --by=<email> --reason="<why>" (or press Repair on that screen).');

            return self::SUCCESS;
        }

        return $this->apply($plan, $gateway);
    }

    private function printPlan(RepairPlan $plan): void
    {
        $businesses = Business::query()->pluck('slug', 'id');
        $this->line('Generated '.$plan->generatedAt);

        if ($plan->isEmpty()) {
            $this->info('No split to propose: no row of one business hangs under a customer of another, or every such row is ambiguous (below).');
        }
        foreach ($plan->batches as $batch) {
            $this->newLine();
            $this->info("Batch for business [{$batch['business_slug']}] (#{$batch['business_id']}), key {$batch['key']}: ".count($batch['splits']).' split(s)');
            foreach ($batch['splits'] as $s) {
                $target = $s['to_customer_id'] === null ? 'a NEW customer' : "existing customer #{$s['to_customer_id']}";
                $from = (string) ($businesses[$s['from_business_id']] ?? $s['from_business_id']);
                $this->line("  split {$s['key']}");
                $this->line("    from customer #{$s['from_customer_id']} ({$from}) to {$target} of [{$batch['business_slug']}]");
                $this->line('    lineage: '.($s['merge_id'] === null ? 'no merge lineage (an unscoped lookup attached the rows)' : "reverses cross-business merge #{$s['merge_id']} (the merge row stays)"));
                $r = $s['rows'];
                $this->line(sprintf('    rows: %d order(s) %s, %d conversation(s) %s, %d participant(s), %d message(s), %d memory(ies) with a business',
                    count($r['orders']), $this->ids($r['orders']), count($r['conversations']), $this->ids($r['conversations']), count($r['participants']), count($r['messages']), count($r['memories'])));
                $this->line(sprintf('    identities: %d back live under the target %s, %d superseded by a live row (stay held, conflict stays open), %d copied (%s), %d conflict(s) resolved',
                    count($r['identities_move']), $this->ids($r['identities_move']), count($r['identities_superseded']), count($r['identities_copy']),
                    implode(', ', array_map(fn ($c) => $c['channel'], $r['identities_copy'])) ?: '-', count($r['conflicts_resolve'])));
                if ($s['new_customer'] !== null) {
                    $this->line('    new customer: phone '.($s['new_customer']['phone'] === null ? 'none' : 'from the order').', nothing else (no name or e-mail of the other business is copied)');
                }
                $this->line(sprintf('    withheld for a person: %d business-less memory(ies) %s, %d consent row(s) to re-record (%d restriction(s))',
                    count($s['withheld']['memories_unscoped']), $this->ids($s['withheld']['memories_unscoped']), count($s['withheld']['consent_rerecord']),
                    count(array_filter($s['withheld']['consent_rerecord'], fn ($c) => $c['restriction']))));
                $rv = $s['review'];
                $this->line(sprintf('    review: %s (rows: needs_repair %d, confirmed_wrong %d, disputed %d, not reviewed %d; flags %s)',
                    $rv['authorized'] ? 'AUTHORIZED' : 'NOT YET (every row needs a review, at least one needs_repair, none disputed)',
                    $rv['needs_repair'], $rv['confirmed_wrong'], $rv['disputed'], $rv['unflagged'], $rv['flag_ids'] === [] ? 'none yet (run hq:identity:cross-business --flag)' : $this->ids($rv['flag_ids'])));
                if ($rv['unreviewed_rows'] !== []) {
                    $this->line('    not reviewed: '.implode(', ', $rv['unreviewed_rows']));
                }
                $this->line('    fingerprint '.substr($s['fingerprint'], 0, 16).'...');
            }
        }

        $this->newLine();
        $this->line(count($plan->ambiguous).' ambiguous case(s) (not moved; quarantined on apply):');
        foreach ($plan->ambiguous as $a) {
            $this->line("  {$a['subject_table']}#{$a['subject_id']} under customer #{$a['customer_id']}: {$a['why']}");
        }
        $this->line(count($plan->heldIdentities).' identity(ies) stay quarantined:');
        foreach ($plan->heldIdentities as $h) {
            $this->line("  customer_identities#{$h['identity_id']} ({$h['channel']}, {$h['quarantine_reason']}) under customer #{$h['customer_id']}: {$h['why']}");
        }
        $this->line(count($plan->openConflicts).' conflict(s) stay open for a person:');
        foreach ($plan->openConflicts as $c) {
            $this->line("  customer_identity_conflicts#{$c['conflict_id']} ({$c['kind']}, {$c['reason']}) on identity #{$c['identity_id']}, claimant ".($c['claimant_customer_id'] ?? '-').", seen {$c['occurrences']}x");
        }
        $this->line(count($plan->humanLists['consent_rerecord']).' consent row(s) for a person to re-record in the right scope (never moved, never converted):');
        foreach ($plan->humanLists['consent_rerecord'] as $c) {
            $this->line("  {$c['table']}#{$c['id']} state {$c['state']}".($c['restriction'] ? ' RESTRICTION' : '')." ({$c['channel']}, {$c['purpose']}) under customer #{$c['customer_id']} for business #{$c['business_id']}");
        }
        $this->line(count($plan->humanLists['memories_unscoped']).' business-less memory(ies) withheld from Customer 360 until a person assigns a business.');
    }

    /** @param  array<string, mixed>  $recount */
    private function printRecount(array $recount): void
    {
        $this->newLine();
        $this->info('Recount by business (counted by the row\'s own business, verified against the source rows):');
        $this->table(['business', 'customers', 'orders', 'under own customer', 'no customer', 'conversations', 'participants', 'messages', 'live ids', 'held ids'],
            array_map(fn ($b) => [$b['business_id'], $b['customers'], $b['orders'], $b['orders_under_own_customer'], $b['orders_without_customer'], $b['conversations'], $b['participants'], $b['messages'], $b['live_identities'], $b['quarantined_identities']], $recount['businesses']));
        if ($recount['customers'] !== []) {
            $this->table(['customer', 'business', 'orders stored', 'orders from rows', 'value stored', 'value from rows', 'foreign rows still under'],
                array_map(fn ($c) => [$c['customer_id'], $c['business_id'], $c['orders_count']['stored'], $c['orders_count']['from_rows'], $c['lifetime_value']['stored'], $c['lifetime_value']['from_rows'], $c['foreign_rows_still_under']], $recount['customers']));
            $this->line($recount['verified'] ? 'Stored figures match the rows.' : 'MISMATCH: a stored figure differs from the rows (see the table).');
        }
        $this->line('Excluded: still crossing '.json_encode($recount['excluded']['still_crossing']).'; quarantined identities '.json_encode($recount['excluded']['quarantined_identities_by_reason'])
            .'; open conflicts '.$recount['excluded']['open_conflicts']);
    }

    private function apply(RepairPlan $plan, ToolGateway $gateway): int
    {
        $key = (string) $this->option('key');
        $email = (string) $this->option('by');
        $reason = (string) $this->option('reason');
        if ($key === '' || $email === '' || $reason === '') {
            $this->error('--apply needs --key=<batch key>, --by=<email of the person> and --reason="<why>".');

            return self::INVALID;
        }
        $actor = User::query()->where('email', $email)->first();
        if ($actor === null) {
            $this->error("No HQ user [{$email}].");

            return self::INVALID;
        }
        $batch = $plan->batchByKey($key);
        $businessId = $batch['business_id'] ?? DB::table('customer_identity_repairs')->where('key', $key)->value('business_id');
        if ($businessId === null) {
            $this->error("No proposed or started batch carries the key [{$key}]. Run the dry run again and use the key it prints.");

            return self::FAILURE;
        }
        $slug = Business::query()->whereKey($businessId)->value('slug');

        $outcome = $gateway->execute(ToolRequest::forConsole(QuarantineRepairTool::KEY, ['business_slug' => $slug, 'repair_key' => $key, 'reason' => $reason], $actor, (int) $businessId));
        if ($outcome->status !== OutcomeStatus::Succeeded) {
            $this->error('Refused ('.$outcome->status->value.'): '.($outcome->error ?? $outcome->policyReason ?? 'no reason given'));

            return self::FAILURE;
        }
        $out = $outcome->output;
        $this->info("Batch {$key}: {$out['status']} (repair #{$out['repair_id']})".($out['replayed'] ? ', replayed' : ''));
        foreach ($out['splits'] as $s) {
            $this->line("  split {$s['split']} from customer #{$s['from_customer_id']}: {$s['status']}".(isset($s['why']) ? " ({$s['why']})" : '')
                .(isset($s['to_customer_id']) ? " to customer #{$s['to_customer_id']}".($s['to_customer_created'] ? ' (created)' : '') : '')
                .(isset($s['reversal_id']) ? ", reversal #{$s['reversal_id']}" : ''));
        }
        if (($out['verification'] ?? null) !== null) {
            $v = $out['verification'];
            $this->line('verification: '.($v['ok'] ? "ok ({$v['checks']} checks)" : 'FAILED: '.implode('; ', $v['failures'])));
        }
        if (($out['recount'] ?? null) !== null) {
            $this->printRecount($out['recount']);
        }
        $this->line(($out['ambiguous_flagged'] ?? 0).' ambiguous row(s) quarantined.');

        return $out['status'] === 'verified' || $out['status'] === 'already_verified' ? self::SUCCESS : self::FAILURE;
    }

    /** @return list<int> */
    private function affectedCustomers(RepairPlan $plan): array
    {
        $ids = [];
        foreach ($plan->batches as $batch) {
            foreach ($batch['splits'] as $s) {
                $ids[] = (int) $s['from_customer_id'];
                if ($s['to_customer_id'] !== null) {
                    $ids[] = (int) $s['to_customer_id'];
                }
            }
        }
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    /** @param  list<int>  $ids */
    private function ids(array $ids): string
    {
        return $ids === [] ? '' : '(#'.implode(', #', array_slice($ids, 0, 20)).(count($ids) > 20 ? ', ...' : '').')';
    }
}
