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
use App\FollowUps\Outcomes\FollowUpOutcomeLink;
use App\FollowUps\Proposer;
use App\Gateway\CallerKind;
use App\Gateway\Descriptor\Channel;
use App\Gateway\Identity\ActingIdentity;
use App\Gateway\ToolGateway;
use App\Gateway\ToolRequest;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\FactsOrder;
use App\Models\FollowUp;
use App\Models\Outcome;
use App\Models\OutcomeAttribution;
use App\Models\Store;
use App\Models\User;
use App\Models\UserBusinessScope;
use App\Outcomes\ActionType;
use App\Outcomes\AttributionMethod;
use App\Outcomes\AttributionRole;
use App\Outcomes\AttributionSpec;
use App\Outcomes\FollowUps\LedgerFollowUpOutcomeLink;
use App\Outcomes\OutcomeActor;
use App\Outcomes\OutcomeRecorder;
use App\Outcomes\OutcomeSource;
use App\Outcomes\OutcomeSpec;
use App\Outcomes\ResultRefSpec;
use App\Outcomes\ResultType;
use App\Tools\Outcomes\AttributeOutcomeTool;
use Database\Seeders\DomainSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | docs/123 section 1 (docs/120 Stage 4, items 1, 7, 15): the S4 outcome-dependent part on the ruled S3
 | contract. A follow-up is one more S3 action type (`follow_up`, one typed RESTRICT column, D7); it is
 | credited only when done, only within its business, only by a person's assertion through the
 | human-only `outcomes.attribute` tool; completion records nothing by itself (Q3, C7); reads are the
 | current view (D17), business-scoped twice (Q7); `converted` is checked against the ledger, never
 | replaced by it (F4).
 */

beforeEach(function () {
    Cache::flush();
    $this->seed(DomainSeeder::class);
    $this->lld = Brand::where('code', 'LLD')->sole();
    $this->bnc = Brand::where('code', 'BNC')->sole();
    $this->owner = $this->hqUser('owner');
    $this->store = Store::where('slug', 'laladia-com')->sole();
    $this->customer = Customer::create(['business_id' => $this->lld->business_id, 'name' => 'Ledger Customer', 'primary_phone_canonical' => '01711000881']);
});

function s4Consent(Customer $c): void
{
    app(ConsentLedger::class)->record($c->id, $c->business_id, ConsentPurpose::Service, ConsentState::Granted, ConsentBasis::Operator, 'whatsapp',
        now()->subMinute(), ['evidence_ref' => 'test', 'actor_type' => 'human', 'actor_label' => 'test']);
}

function s4FollowUp(Customer $c, User $by, bool $complete = true, array $over = []): FollowUp
{
    s4Consent($c);
    $service = app(FollowUpProposalService::class);
    $f = $service->proposeFollowUp(Proposer::person($by), new FollowUpProposal(...array_replace([
        'customer' => $c, 'kind' => FollowUpKind::Service, 'channel' => Channel::Whatsapp,
        'dueAt' => now()->addDay(), 'intent' => 'Ask whether the order should be repeated', 'origin' => FollowUpOrigin::Promise,
    ], $over)));
    $f = $service->approveFollowUp($f, $by);

    return $complete ? $service->completeFollowUp($f, $by, 'Called; she ordered again.') : $f;
}

function s4Order(int $businessId, int $storeId, string $ref, ?int $customerId = null): Outcome
{
    $order = FactsOrder::query()->forceCreate(['business_id' => $businessId, 'store_id' => $storeId, 'order_ref' => $ref, 'customer_id' => $customerId,
        'source' => 'checkout', 'status' => 'placed', 'placed_at' => now()]);

    return app(OutcomeRecorder::class)->record(new OutcomeSpec(
        type: 'order.placed', businessId: $businessId, occurredAt: now(), source: OutcomeSource::ShopWebhook, sourceSystem: 'shop',
        provenance: Provenance::Business, idempotencyKey: "order.placed:shop:{$storeId}:{$ref}",
        results: [new ResultRefSpec(ResultType::ShopOrder, 'shop', "store-{$storeId}:{$ref}", (int) $order->id)], storeId: $storeId,
    ));
}

function s4Attribute(User $user, string $slug, Outcome $outcome, FollowUp $f, array $over = [])
{
    return app(ToolGateway::class)->execute(new ToolRequest(
        toolKey: AttributeOutcomeTool::KEY, caller: CallerKind::HumanUi, identity: ActingIdentity::human($user), correlationId: (string) Str::uuid(),
        args: array_replace(['business_slug' => $slug, 'outcome_uuid' => $outcome->uuid, 'action_type' => 'follow_up', 'action_id' => $f->id,
            'role' => 'contributed', 'confidence' => 0.7, 'reason' => 'she told me on the call that the follow-up made her reorder',
            'evidence_ref' => 'call-note:'.$f->uuid], $over),
    ));
}

it('adds follow_up as one typed, RESTRICT action column and keeps exactly one action column set', function () {
    expect(ActionType::FollowUp->column())->toBe('follow_up_id')->and(ActionType::FollowUp->table())->toBe('follow_ups');

    $fk = DB::selectOne("select confdeltype, confrelid::regclass::text as target from pg_constraint
        where conrelid = 'outcome_attributions'::regclass and contype = 'f'
        and conkey = array[(select attnum from pg_attribute where attrelid = 'outcome_attributions'::regclass and attname = 'follow_up_id')]::smallint[]");
    expect($fk->confdeltype)->toBe('r')->and($fk->target)->toBe('follow_ups');

    $check = DB::selectOne("select pg_get_constraintdef(oid) as def from pg_constraint where conname = 'outcome_attributions_typed_action'")->def;
    expect($check)->toContain("'follow_up'")->toContain('follow_up_id')->toContain('num_nonnulls');
    foreach (ActionType::cases() as $type) {
        expect($check)->toContain("'{$type->value}'");
    }
});

it('records a person\'s credit of a done follow-up through outcomes.attribute only, typed, operator_asserted, in the follow-up\'s business', function () {
    $f = s4FollowUp($this->customer, $this->owner);
    $outcome = s4Order($this->lld->business_id, $this->store->id, 'LD-501', $this->customer->id);

    // Completing the follow-up wrote nothing to the ledger by itself (Q3: no rule infers the credit).
    expect(OutcomeAttribution::count())->toBe(0);

    $result = s4Attribute($this->owner, 'laladia', $outcome, $f);
    expect($result->status->value)->toBe('succeeded', (string) $result->error);

    $row = OutcomeAttribution::sole();
    expect($row->action_type)->toBe(ActionType::FollowUp)
        ->and((int) $row->follow_up_id)->toBe($f->id)
        ->and($row->actionId())->toBe($f->id)
        ->and($row->action_ref)->toBe('follow_up:'.$f->uuid)
        ->and($row->method->value)->toBe('operator_asserted')
        ->and($row->actor_type)->toBe('human')
        ->and((int) $row->actor_id)->toBe($this->owner->id)
        ->and((int) $row->business_id)->toBe($this->lld->business_id);

    foreach (array_diff(ActionType::columns(), ['follow_up_id']) as $column) {
        expect($row->{$column})->toBeNull();
    }
});

it('refuses to credit a follow-up that is not done, or of another business; nothing written', function () {
    $scheduled = s4FollowUp($this->customer, $this->owner, complete: false);
    $outcome = s4Order($this->lld->business_id, $this->store->id, 'LD-502');

    $notDone = s4Attribute($this->owner, 'laladia', $outcome, $scheduled);
    expect($notDone->status->value)->toBe('failed')->and($notDone->error)->toContain('not done');

    $bionicCustomer = Customer::create(['business_id' => $this->bnc->business_id, 'name' => 'Other Business', 'primary_phone_canonical' => '01711000881']);
    $foreign = s4FollowUp($bionicCustomer, $this->owner);
    $crossBusiness = s4Attribute($this->owner, 'laladia', $outcome, $foreign);
    expect($crossBusiness->status->value)->toBe('failed')->and($crossBusiness->error)->toContain('another business');

    // The recorder itself refuses the same, so no future recorder can skip the rule.
    expect(fn () => app(OutcomeRecorder::class)->attribute($outcome, new AttributionSpec(
        ActionType::FollowUp, $scheduled->id, 'follow_up:'.$scheduled->uuid, AttributionRole::Contributed,
        AttributionMethod::OperatorAsserted, 'operator_asserted.v1', 0.5, 'k-'.Str::random(6), null, null,
        OutcomeActor::human($this->owner), null, 'a reason',
    )))->toThrow(InvalidArgumentException::class, 'not done');

    expect(OutcomeAttribution::count())->toBe(0);
});

it('reads credits in the current view only, business-scoped twice, and checks a converted close against the ledger without replacing it', function () {
    $service = app(FollowUpProposalService::class);
    $opportunity = $service->proposeOpportunity(Proposer::person($this->owner), new OpportunityProposal(
        $this->customer, OpportunityKind::Reorder, 'Wants the honey again', now()->addMonth(), $this->lld->id));
    $service->openOpportunity($opportunity, $this->owner);
    $f = s4FollowUp($this->customer, $this->owner, over: ['opportunity' => $opportunity->fresh(), 'origin' => FollowUpOrigin::Opportunity]);
    $service->closeOpportunity($opportunity->fresh(), $this->owner, OpportunityCloseReason::Converted, 'She ordered');

    $link = app(FollowUpOutcomeLink::class);
    expect($link)->toBeInstanceOf(LedgerFollowUpOutcomeLink::class)
        // The person's word alone: closed converted, not supported by the ledger.
        ->and($link->supportsConversion($opportunity->fresh(), $this->owner))->toBeFalse();

    $outcome = s4Order($this->lld->business_id, $this->store->id, 'LD-503', $this->customer->id);
    s4Attribute($this->owner, 'laladia', $outcome, $f);

    $credits = $link->creditsFor($this->lld->business_id, [$f->id], $this->owner);
    expect($credits[$f->id])->toHaveCount(1)
        ->and($credits[$f->id][0])->toMatchArray(['outcome_uuid' => $outcome->uuid, 'outcome_type' => 'order.placed', 'role' => 'contributed', 'method' => 'operator_asserted'])
        ->and(json_encode($credits))->not->toContain('gross')
        ->and($link->outcomesFor($opportunity->fresh(), $this->owner))->toHaveCount(1)
        ->and($link->supportsConversion($opportunity->fresh(), $this->owner))->toBeTrue()
        ->and($opportunity->fresh()->close_reason)->toBe(OpportunityCloseReason::Converted);

    // Asked under another business id, nothing (the row's own business binds the read).
    expect($link->creditsFor($this->bnc->business_id, [$f->id], $this->owner))->toBe([]);

    // A viewer restricted to Bionic sees nothing of LaLaDia's ledger, whatever is asked.
    $restricted = $this->hqUser('operator');
    UserBusinessScope::create(['user_id' => $restricted->id, 'business_id' => $this->bnc->business_id]);
    expect($link->creditsFor($this->lld->business_id, [$f->id], $restricted->fresh()))->toBe([])
        ->and($link->supportsConversion($opportunity->fresh(), $restricted->fresh()))->toBeFalse();

    // A retracted credit leaves the current view (D17); history stays in the ledger.
    app(OutcomeRecorder::class)->retractAttribution(OutcomeAttribution::sole(), 'credited by mistake', OutcomeActor::human($this->owner));
    expect($link->creditsFor($this->lld->business_id, [$f->id], $this->owner))->toBe([])
        ->and($link->supportsConversion($opportunity->fresh(), $this->owner))->toBeFalse()
        ->and(OutcomeAttribution::count())->toBe(2);
});

it('shows credits and the conversion check on the follow-ups page, and the database refuses to delete a credited follow-up (RESTRICT)', function () {
    $f = s4FollowUp($this->customer, $this->owner);
    $outcome = s4Order($this->lld->business_id, $this->store->id, 'LD-504');
    s4Attribute($this->owner, 'laladia', $outcome, $f);

    $this->actingAsHq($this->owner)->get('/follow-ups?state=done')->assertOk()
        ->assertInertia(fn ($page) => $page->component('follow-ups/index')
            ->where('followUps.0.uuid', $f->uuid)
            ->where('followUps.0.ledger_credits.0.outcome_type', 'order.placed')
            ->where('followUps.0.self_decided', true));

    expect(fn () => DB::transaction(fn () => DB::table('follow_ups')->where('id', $f->id)->delete()))->toThrow(QueryException::class);
    expect(FollowUp::whereKey($f->id)->exists())->toBeTrue();
});
