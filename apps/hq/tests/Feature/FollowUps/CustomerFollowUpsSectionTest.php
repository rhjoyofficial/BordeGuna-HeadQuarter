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
use App\FollowUps\OpportunityCloseReason;
use App\FollowUps\OpportunityKind;
use App\FollowUps\OpportunityProposal;
use App\FollowUps\Proposer;
use App\Gateway\Descriptor\Channel;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\FactsOrder;
use App\Models\FollowUp;
use App\Models\Store;
use App\Models\User;
use App\Models\UserBusinessScope;
use App\Outcomes\ActionType;
use App\Outcomes\AttributionMethod;
use App\Outcomes\AttributionRole;
use App\Outcomes\AttributionSpec;
use App\Outcomes\OutcomeActor;
use App\Outcomes\OutcomeRecorder;
use App\Outcomes\OutcomeSource;
use App\Outcomes\OutcomeSpec;
use App\Outcomes\ResultRefSpec;
use App\Outcomes\ResultType;
use Database\Seeders\DomainSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
 | docs/120 item 14, docs/123 section 2: Customer 360 shows this customer's follow-ups, promises and
 | opportunities, READ-ONLY and BUSINESS-SCOPED (the customer's business AND each row's own), to a viewer
 | holding hq.followups.view. No write control, no write on read, nothing of another business.
 */

beforeEach(function () {
    $this->seed(DomainSeeder::class);
    $this->lld = Brand::where('code', 'LLD')->sole();
    $this->bnc = Brand::where('code', 'BNC')->sole();
    $this->owner = $this->hqUser('owner');
    $this->customer = Customer::create(['business_id' => $this->lld->business_id, 'name' => 'Section Customer', 'primary_phone_canonical' => '01711000991']);
    $this->service = app(FollowUpProposalService::class);
    app(ConsentLedger::class)->record($this->customer->id, $this->customer->business_id, ConsentPurpose::Service, ConsentState::Granted,
        ConsentBasis::Operator, 'whatsapp', now()->subMinute(), ['evidence_ref' => 'test', 'actor_type' => 'human', 'actor_label' => 'test']);
    $this->props = fn (User $viewer): array => $this->actingAsHq($viewer)->get('/customers/'.$this->customer->id)->assertOk()->viewData('page')['props'];
});

function cfsPropose(object $t, array $over = []): FollowUp
{
    return $t->service->proposeFollowUp(Proposer::person($t->owner), new FollowUpProposal(...array_replace([
        'customer' => $t->customer, 'kind' => FollowUpKind::Service, 'channel' => Channel::Whatsapp,
        'dueAt' => now()->addDay(), 'intent' => 'Call back about the jar size', 'origin' => FollowUpOrigin::Promise,
    ], $over)));
}

it('shows follow-ups, promises, opportunities and ledger credits for this customer, read-only', function () {
    $promise = cfsPropose($this);
    $opportunity = $this->service->proposeOpportunity(Proposer::person($this->owner), new OpportunityProposal(
        $this->customer, OpportunityKind::Reorder, 'Wants the large jar', now()->addMonth(), $this->lld->id));
    $this->service->openOpportunity($opportunity, $this->owner);
    $done = $this->service->completeFollowUp($this->service->approveFollowUp(cfsPropose($this, ['origin' => FollowUpOrigin::Opportunity, 'opportunity' => $opportunity->fresh()]), $this->owner), $this->owner, 'Called, she ordered');
    $this->service->closeOpportunity($opportunity->fresh(), $this->owner, OpportunityCloseReason::Converted);

    $store = Store::where('slug', 'laladia-com')->sole();
    $order = FactsOrder::query()->forceCreate(['business_id' => $this->lld->business_id, 'store_id' => $store->id, 'order_ref' => 'LD-900', 'source' => 'checkout', 'status' => 'placed', 'gross' => 1350]);
    $recorder = app(OutcomeRecorder::class);
    $outcome = $recorder->record(new OutcomeSpec(type: 'order.placed', businessId: $this->lld->business_id, occurredAt: now(), source: OutcomeSource::ShopWebhook,
        sourceSystem: 'shop', provenance: Provenance::Business, idempotencyKey: 'order.placed:shop:'.$store->id.':LD-900',
        results: [new ResultRefSpec(ResultType::ShopOrder, 'shop', 'laladia-com:LD-900', (int) $order->id)], storeId: $store->id));
    $recorder->attribute($outcome, new AttributionSpec(ActionType::FollowUp, $done->id, 'follow_up:'.$done->uuid, AttributionRole::Contributed,
        AttributionMethod::OperatorAsserted, 'operator_asserted.v1', 0.6, 'attr:test:'.Str::random(6), null, null, OutcomeActor::human($this->owner), null, 'she said so'));

    $section = ($this->props)($this->owner)['follow_ups'];

    expect($section['counts'])->toBe(['open' => 1, 'open_promises' => 1, 'due' => 0, 'active_opportunities' => 0])
        ->and(collect($section['follow_ups'])->pluck('uuid')->all())->toBe([$promise->uuid, $done->uuid])
        ->and($section['follow_ups'][0]['promise'])->toBeTrue()
        ->and($section['follow_ups'][1]['ledger_credits'][0]['outcome_type'])->toBe('order.placed')
        ->and($section['follow_ups'][1]['ledger_credits'][0]['method'])->toBe('operator_asserted')
        ->and($section['opportunities'][0]['close_reason'])->toBe('converted')
        ->and($section['opportunities'][0]['conversion_supported'])->toBeTrue()
        // No amount from the order copy, no write control, no decision permission handed to the page.
        ->and(json_encode($section))->not->toContain('1350')->not->toContain('"can"')->not->toContain('hq.followups.decide')->not->toContain('/approve');

    // The page's only route stays GET; the section adds no route under /customers.
    $customerRoutes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'customers/{customer}'));
    foreach ($customerRoutes as $route) {
        expect(str_contains($route->uri(), 'follow'))->toBeFalse();
    }
});

it('binds every row to the customer\'s business and the row\'s own: a row of another business carrying this id is not shown', function () {
    $own = cfsPropose($this);
    // A row the service would never write (another business, this customer's id), planted directly.
    $foreign = FollowUp::query()->forceCreate(['uuid' => (string) Str::uuid(), 'business_id' => $this->bnc->business_id, 'customer_id' => $this->customer->id,
        'origin' => 'promise', 'kind' => 'service', 'channel' => 'whatsapp', 'due_at' => now()->addDay(), 'intent' => 'FOREIGN ROW',
        'state' => 'proposed', 'proposer_type' => 'person', 'proposed_by_user_id' => $this->owner->id]);

    $section = ($this->props)($this->owner)['follow_ups'];
    expect(collect($section['follow_ups'])->pluck('uuid')->all())->toBe([$own->uuid])
        ->and(json_encode($section))->not->toContain('FOREIGN ROW')
        ->and($section['counts']['open'])->toBe(1);

    // A viewer restricted to Bionic cannot open this LaLaDia customer at all (404, docs/109).
    $restricted = $this->hqUser('operator');
    UserBusinessScope::create(['user_id' => $restricted->id, 'business_id' => $this->bnc->business_id]);
    $this->actingAsHq($restricted->fresh())->get('/customers/'.$this->customer->id)->assertNotFound();
    expect($foreign->exists)->toBeTrue();
});

it('is null without hq.followups.view, and rendering it writes nothing', function () {
    cfsPropose($this);
    $viewer = $this->hqUser('finance_viewer');
    $viewer->givePermissionTo('hq.customers.view');
    expect(($this->props)($viewer->fresh())['follow_ups'])->toBeNull();

    $tables = ['follow_ups', 'opportunities', 'outcomes', 'outcome_attributions', 'audit_logs', 'workflow_instances', 'consent_events', 'events'];
    $snapshot = fn () => collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count().':'.DB::table($t)->max('id')])->all()
        + ['follow_up_rows' => DB::table('follow_ups')->orderBy('id')->get()->toJson()];
    $before = $snapshot();
    ($this->props)($this->owner);
    ($this->props)($this->hqUser('brand_manager'));
    expect($snapshot())->toBe($before);
});
