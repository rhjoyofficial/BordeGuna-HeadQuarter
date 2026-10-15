<?php

use App\Consent\ConsentBasis;
use App\Consent\ConsentLedger;
use App\Consent\ConsentPurpose;
use App\Consent\ConsentState;
use App\Context\Provenance;
use App\FollowUps\FollowUpKind;
use App\FollowUps\FollowUpOrigin;
use App\FollowUps\FollowUpProposal;
use App\FollowUps\FollowUpProposalService;
use App\FollowUps\Proposer;
use App\Gateway\Descriptor\Channel;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\FactsOrder;
use App\Models\FollowUp;
use App\Models\Opportunity;
use App\Models\RetentionRun;
use App\Models\Store;
use App\Models\WorkflowInstance;
use App\Observe\Retention\RetentionPolicy;
use App\Observe\Retention\RetentionRunner;
use App\Outcomes\OutcomeRecorder;
use App\Outcomes\OutcomeSource;
use App\Outcomes\OutcomeSpec;
use App\Outcomes\ResultRefSpec;
use App\Outcomes\ResultType;
use Carbon\CarbonImmutable;
use Database\Seeders\DomainSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | docs/120 item 13 (S4 Q6) and item 7, docs/123 section 4: refused and declined follow-ups and declined
 | opportunities are pruned 365 days after the decision; done, cancelled and closed rows are kept; open
 | rows never go; keep-clauses (an outcome attribution, a follow-up still serving an opportunity) win;
 | audit rows stay. The WorkflowEngine rule (terminal and reconciled, 180 days) is separate and unchanged.
 */

beforeEach(function () {
    Cache::flush();
    $this->seed(DomainSeeder::class);
    $this->lld = Brand::where('code', 'LLD')->sole();
    $this->owner = $this->hqUser('owner');
    $this->customer = Customer::create(['business_id' => $this->lld->business_id, 'name' => 'Retention Customer', 'primary_phone_canonical' => '01711000555']);
});

function retFu(object $t, string $state, int $daysAgo, array $over = []): FollowUp
{
    $at = CarbonImmutable::now()->subDays($daysAgo);

    return FollowUp::query()->forceCreate(array_replace([
        'uuid' => (string) Str::uuid(), 'business_id' => $t->lld->business_id, 'customer_id' => $t->customer->id,
        'origin' => 'operator', 'kind' => 'service', 'channel' => 'whatsapp', 'due_at' => $at, 'intent' => "{$state} {$daysAgo}",
        'state' => $state, 'proposer_type' => 'person', 'proposed_by_user_id' => $t->owner->id,
        'decided_at' => in_array($state, ['proposed', 'refused'], true) ? null : $at, 'consent_checked_at' => $at,
        'created_at' => $at, 'updated_at' => $at,
    ], $over));
}

function retOpp(object $t, string $state, int $daysAgo): Opportunity
{
    $at = CarbonImmutable::now()->subDays($daysAgo);

    return Opportunity::query()->forceCreate([
        'uuid' => (string) Str::uuid(), 'business_id' => $t->lld->business_id, 'customer_id' => $t->customer->id, 'kind' => 'reorder',
        'subject' => "{$state} {$daysAgo}", 'state' => $state, 'proposer_type' => 'person', 'proposed_by_user_id' => $t->owner->id,
        'decided_at' => $state === 'proposed' ? null : $at, 'close_reason' => $state === 'closed' ? 'lost' : null,
        'closed_at' => $state === 'closed' ? $at : null, 'created_at' => $at, 'updated_at' => $at,
    ]);
}

it('pins the rule: 365 days, refused and declined only, follow-ups and opportunities only, one guarded DELETE site', function () {
    $policy = app(RetentionPolicy::class);
    expect(config('hq.retention.followup_refused_keep_days'))->toBe(365)
        ->and($policy->followUpKeepDays())->toBe(365)
        ->and(RetentionPolicy::FOLLOW_UP_PRUNABLE_STATES)->toBe(['refused', 'declined'])
        ->and(RetentionPolicy::OPPORTUNITY_PRUNABLE_STATES)->toBe(['declined'])
        ->and(array_intersect(RetentionPolicy::FOLLOW_UP_TABLES, RetentionPolicy::neverTargeted()))->toBe([])
        ->and($policy->snapshot()['followup_refused_keep_days'])->toBe(365);

    $source = file_get_contents(app_path('Observe/Retention/FollowUpRetention.php'));
    expect(substr_count($source, '->delete()'))->toBe(1)
        ->and($source)->toContain("DB::table(\$table)->whereIn('id', \$ids)->delete()")
        ->and($source)->toContain("foreach (['follow_ups' => 'follow_ups_removed', 'opportunities' => 'opportunities_removed'] as \$table => \$counter)")
        ->and(preg_match_all('/DELETE\s+FROM|UPDATE\s+\w+\s+SET|->update\(/i', $source))->toBe(0);
});

it('prunes refused and declined rows past the window and keeps everything else', function () {
    $refusedOld = retFu($this, 'refused', 400);
    $declinedOld = retFu($this, 'declined', 400);
    $refusedYoung = retFu($this, 'refused', 300);
    $kept = [
        retFu($this, 'done', 400), retFu($this, 'cancelled', 400), retFu($this, 'scheduled', 400),
        retFu($this, 'proposed', 400), retFu($this, 'approved', 400, ['decided_at' => now()->subDays(400)]),
    ];

    // Item 7: a keep-clause wins. An attribution naming a refused follow-up (planted: the recorder itself would refuse it) keeps the row.
    $store = Store::where('slug', 'laladia-com')->sole();
    $order = FactsOrder::query()->forceCreate(['business_id' => $this->lld->business_id, 'store_id' => $store->id, 'order_ref' => 'LD-77', 'source' => 'checkout', 'status' => 'placed']);
    $outcome = app(OutcomeRecorder::class)->record(new OutcomeSpec(type: 'order.placed', businessId: $this->lld->business_id, occurredAt: now(),
        source: OutcomeSource::ShopWebhook, sourceSystem: 'shop', provenance: Provenance::Business, idempotencyKey: 'order.placed:shop:x:LD-77',
        results: [new ResultRefSpec(ResultType::ShopOrder, 'shop', 'laladia-com:LD-77', (int) $order->id)]));
    $credited = retFu($this, 'refused', 400);
    DB::table('outcome_attributions')->insert(['uuid' => (string) Str::uuid(), 'schema_version' => 'attribution.v1', 'outcome_id' => $outcome->id,
        'business_id' => $this->lld->business_id, 'action_type' => 'follow_up', 'follow_up_id' => $credited->id, 'action_ref' => 'follow_up:'.$credited->uuid,
        'role' => 'contributed', 'method' => 'operator_asserted', 'method_version' => 'v1', 'confidence' => 0.5, 'state' => 'asserted',
        'content_digest' => str_repeat('b', 64), 'actor_type' => 'human', 'idempotency_key' => 'planted', 'recorded_at' => now()]);

    // Opportunities: one declined alone (goes), one declined still served by a kept follow-up (stays), one served
    // only by a follow-up this pass removes (goes in the same pass), closed and open (stay).
    $oppAlone = retOpp($this, 'declined', 400);
    $oppServed = retOpp($this, 'declined', 400);
    retFu($this, 'done', 400, ['opportunity_id' => $oppServed->id]);
    $oppFreed = retOpp($this, 'declined', 400);
    $freeing = retFu($this, 'declined', 400, ['opportunity_id' => $oppFreed->id]);
    $oppKept = [retOpp($this, 'closed', 400), retOpp($this, 'open', 400), retOpp($this, 'declined', 100)];

    AuditLog::create(['action' => 'follow_up.refused', 'target_type' => FollowUp::class, 'target_id' => $refusedOld->id, 'actor_type' => 'user', 'actor_id' => $this->owner->id]);
    $auditBefore = AuditLog::count();

    $plan = app(RetentionRunner::class)->plan();
    expect($plan['follow_ups'])->toMatchArray(['eligible_follow_ups' => 3, 'kept_credited_follow_ups' => 1, 'eligible_opportunities' => 1, 'kept_served_opportunities' => 2]);

    $run = app(RetentionRunner::class)->run();

    expect($run->status)->toBe(RetentionRun::SUCCEEDED)
        ->and($run->summary['follow_ups'])->toMatchArray(['follow_ups_removed' => 3, 'opportunities_removed' => 2, 'stopped_by' => null])
        ->and(FollowUp::whereIn('id', [$refusedOld->id, $declinedOld->id, $freeing->id])->count())->toBe(0)
        ->and(FollowUp::whereKey($refusedYoung->id)->exists())->toBeTrue()
        ->and(FollowUp::whereKey($credited->id)->exists())->toBeTrue()
        ->and(FollowUp::whereIn('id', collect($kept)->pluck('id'))->count())->toBe(count($kept))
        ->and(Opportunity::whereIn('id', [$oppAlone->id, $oppFreed->id])->count())->toBe(0)
        ->and(Opportunity::whereKey($oppServed->id)->exists())->toBeTrue()
        ->and(Opportunity::whereIn('id', collect($oppKept)->pluck('id'))->count())->toBe(3)
        // The history stays: the pruned row's audit entry is permanent (the pass adds its own record).
        ->and(AuditLog::where('target_id', $refusedOld->id)->where('action', 'follow_up.refused')->exists())->toBeTrue()
        ->and(AuditLog::count())->toBeGreaterThanOrEqual($auditBefore);

    // A second pass finds nothing more: idempotent.
    expect(app(RetentionRunner::class)->run()->summary['follow_ups'])->toMatchArray(['follow_ups_removed' => 0, 'opportunities_removed' => 0]);
});

it('leaves the follow-up row in place when the WorkflowEngine rule prunes its finished, reconciled run 180 days later', function () {
    app(ConsentLedger::class)->record($this->customer->id, $this->customer->business_id, ConsentPurpose::Service, ConsentState::Granted,
        ConsentBasis::Operator, 'whatsapp', now()->subMinute(), ['evidence_ref' => 'test', 'actor_type' => 'human', 'actor_label' => 'test']);
    $service = app(FollowUpProposalService::class);
    $f = $service->proposeFollowUp(Proposer::person($this->owner), new FollowUpProposal(customer: $this->customer, kind: FollowUpKind::Service,
        channel: Channel::Whatsapp, dueAt: now()->subMinute(), intent: 'Ring back', origin: FollowUpOrigin::Promise));
    $f = $service->completeFollowUp($service->approveFollowUp($f, $this->owner), $this->owner, 'Done by phone');
    $run = WorkflowInstance::query()->where('instance_key', FollowUpProposalService::instanceKey($f))->sole();
    expect($run->status)->toBe(WorkflowInstance::COMPLETED);

    $this->travel(100)->days();
    app(RetentionRunner::class)->run();
    expect(WorkflowInstance::whereKey($run->id)->exists())->toBeTrue();

    $this->travel(90)->days();
    app(RetentionRunner::class)->run();
    expect(WorkflowInstance::whereKey($run->id)->exists())->toBeFalse()
        ->and($f->fresh()->state->value)->toBe('done')
        ->and($f->fresh()->workflow_instance_key)->toBe(FollowUpProposalService::instanceKey($f));
});
