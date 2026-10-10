<?php

use App\Context\Provenance;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Conversation;
use App\Models\FactsOrder;
use App\Models\Outcome;
use App\Models\OutcomeAttribution;
use App\Models\OutcomeResultRef;
use App\Models\Store;
use App\Models\ToolCall;
use App\Models\User;
use App\Models\UserBusinessScope;
use App\Outcomes\ActionType;
use App\Outcomes\AttributionMethod;
use App\Outcomes\AttributionRole;
use App\Outcomes\AttributionSpec;
use App\Outcomes\OutcomeActor;
use App\Outcomes\OutcomeConflict;
use App\Outcomes\OutcomeRecorder;
use App\Outcomes\OutcomeSource;
use App\Outcomes\OutcomeSpec;
use App\Outcomes\OutcomeState;
use App\Outcomes\ResultRefSpec;
use App\Outcomes\ResultType;
use Carbon\CarbonImmutable;
use Database\Seeders\DomainSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 | S3 (docs/107): ONE canonical outcome and attribution model. What must hold:
 |  - an outcome is a business-scoped, typed, append-only fact with three timestamps, and it holds a
 |    REFERENCE to the system of record, never a copy of its numbers;
 |  - recording is idempotent on the caller's key, and the same key with a different meaning is refused;
 |  - a correction or retraction is a successor row, audited, one successor per row;
 |  - attributions are typed, business-bound, method-bounded claims;
 |  - reads are business-scoped through the one BusinessScope rule.
 */

beforeEach(function () {
    $this->seed(DomainSeeder::class);
    $this->laladia = Business::where('slug', 'laladia')->sole();
    $this->bionic = Business::where('slug', 'bionic')->sole();
    $this->store = Store::where('slug', 'laladia-com')->sole();
    $this->recorder = app(OutcomeRecorder::class);
});

function outcomeOrder(int $businessId, int $storeId, string $ref = 'LD-1001'): FactsOrder
{
    return FactsOrder::query()->forceCreate([
        'business_id' => $businessId, 'store_id' => $storeId, 'order_ref' => $ref, 'source' => 'checkout',
        'status' => 'delivered', 'gross' => 1250, 'placed_at' => now()->subDay(),
    ]);
}

function orderSpec(FactsOrder $order, array $overrides = []): OutcomeSpec
{
    $args = array_merge([
        'type' => 'order.delivered',
        'businessId' => (int) $order->business_id,
        'occurredAt' => CarbonImmutable::parse('2026-10-09 10:00:00', 'Asia/Dhaka'),
        'source' => OutcomeSource::ShopWebhook,
        'sourceSystem' => 'shop',
        'provenance' => Provenance::Business,
        'idempotencyKey' => 'order.delivered:shop:'.$order->store_id.':'.$order->order_ref,
        'results' => [new ResultRefSpec(ResultType::ShopOrder, 'shop', 'laladia-com:'.$order->order_ref, (int) $order->id)],
        'storeId' => (int) $order->store_id,
        'sourceRef' => 'evt-1',
        'evidence' => ['event_id' => 'evt-1'],
    ], $overrides);

    return new OutcomeSpec(...$args);
}

function toolCallIn(?int $businessId): ToolCall
{
    return ToolCall::query()->forceCreate(['tool_key' => 'commerce.take_order', 'status' => 'succeeded', 'idempotency_key' => 'k-'.Str::random(8),
        'business_id' => $businessId, 'created_at' => now()]);
}

function attributionTo(ToolCall $call, array $overrides = []): AttributionSpec
{
    return new AttributionSpec(...array_merge([
        'actionType' => ActionType::ToolCall,
        'actionId' => (int) $call->id,
        'actionRef' => 'tool_call:'.$call->tool_key.':'.$call->idempotency_key,
        'role' => AttributionRole::Produced,
        'method' => AttributionMethod::DeterministicKey,
        'methodVersion' => 'test.v1',
        'confidence' => 1.0,
        'idempotencyKey' => 'attr:'.$call->id,
    ], $overrides));
}

it('records a business-scoped outcome with a reference to the system of record and no copied numbers', function () {
    $order = outcomeOrder($this->laladia->id, $this->store->id);
    $outcome = $this->recorder->record(orderSpec($order));

    expect($outcome->wasRecentlyCreated)->toBeTrue()
        ->and($outcome->schema_version)->toBe('outcome.v1')
        ->and($outcome->business_id)->toBe($this->laladia->id)
        ->and($outcome->state)->toBe(OutcomeState::Asserted)
        ->and($outcome->occurred_at->utc()->toDateTimeString())->toBe('2026-10-09 04:00:00')
        ->and($outcome->recorded_at)->not->toBeNull()
        ->and($outcome->content_digest)->toHaveLength(64);

    $ref = $outcome->results()->sole();
    expect($ref->result_type)->toBe(ResultType::ShopOrder)
        ->and($ref->facts_order_id)->toBe($order->id)
        ->and($ref->external_ref)->toBe('laladia-com:LD-1001');

    // D3: the ledger has nowhere to put the order's money.
    foreach (['outcomes', 'outcome_result_refs', 'outcome_attributions'] as $table) {
        foreach (['amount', 'gross', 'revenue', 'currency', 'status', 'lines', 'total'] as $column) {
            expect(Schema::hasColumn($table, $column))->toBeFalse("{$table}.{$column} must not exist");
        }
    }
});

it('is idempotent on the key, and refuses the same key with a different meaning', function () {
    $order = outcomeOrder($this->laladia->id, $this->store->id);
    $first = $this->recorder->record(orderSpec($order));

    // A retry by another worker (another actor, another correlation) is the same observation.
    $again = $this->recorder->record(orderSpec($order, ['actor' => OutcomeActor::agent('executive'), 'correlationId' => 'other']));
    expect($again->id)->toBe($first->id)
        ->and($again->wasRecentlyCreated)->toBeFalse()
        ->and(Outcome::count())->toBe(1)
        ->and(OutcomeResultRef::count())->toBe(1);

    expect(fn () => $this->recorder->record(orderSpec($order, ['occurredAt' => CarbonImmutable::parse('2026-10-08 10:00:00')])))
        ->toThrow(OutcomeConflict::class, 'different meaning');
    expect(Outcome::count())->toBe(1);
});

it('refuses an outcome that is unregistered, mis-sourced, mis-scoped or missing its subject', function (Closure $mutate, string $message) {
    $order = outcomeOrder($this->laladia->id, $this->store->id);
    $bionicStore = Store::where('slug', 'bionic-garden')->sole();

    expect(fn () => $this->recorder->record($mutate($order, $this->bionic, $bionicStore)))->toThrow(InvalidArgumentException::class, $message);
    expect(Outcome::count())->toBe(0);
})->with([
    'unregistered type' => [fn ($o) => orderSpec($o, ['type' => 'order.loved']), 'not a registered outcome type'],
    'source the type does not allow' => [fn ($o) => orderSpec($o, ['source' => OutcomeSource::Operator]), 'may not be learned from'],
    'system provenance' => [fn ($o) => orderSpec($o, ['provenance' => Provenance::System]), 'may not carry provenance'],
    'unknown source system' => [fn ($o) => orderSpec($o, ['sourceSystem' => 'excel']), 'not a known source system'],
    'store of another business' => [fn ($o, $b, $s) => orderSpec($o, ['storeId' => $s->id]), 'store does not belong'],
    'order of another business' => [fn ($o, $b) => orderSpec($o, ['businessId' => $b->id, 'storeId' => null]), 'belongs to another business'],
    'no subject' => [fn ($o) => orderSpec($o, ['results' => [new ResultRefSpec(ResultType::External, 'erp', 'erp:INV-1')]]), 'needs a subject reference'],
    'shop order without its row' => [fn ($o) => orderSpec($o, ['results' => [new ResultRefSpec(ResultType::ShopOrder, 'shop', 'laladia-com:LD-1001')]]), 'must point at its HQ row'],
    'missing row' => [fn ($o) => orderSpec($o, ['results' => [new ResultRefSpec(ResultType::ShopOrder, 'shop', 'x:1', 999999)]]), 'does not exist'],
    'oversized evidence' => [fn ($o) => orderSpec($o, ['evidence' => ['blob' => str_repeat('x', 17000)]]), 'at most 16 KB'],
    'empty key' => [fn ($o) => orderSpec($o, ['idempotencyKey' => ' ']), 'idempotency key is required'],
]);

it('lets an ERP order be referenced by its own number before HQ holds a mirror row, and an external record beside it', function () {
    $outcome = $this->recorder->record(new OutcomeSpec(
        type: 'order.placed', businessId: $this->laladia->id, occurredAt: now(), source: OutcomeSource::ErpReadBack,
        sourceSystem: 'erp', provenance: Provenance::Business, idempotencyKey: 'order.placed:erp:'.$this->laladia->id.':SO-1',
        results: [
            new ResultRefSpec(ResultType::ErpOrder, 'erp', 'erp:SO-1'),
            new ResultRefSpec(ResultType::External, 'erp', 'erp:INV-1', null, ResultRefSpec::ROLE_CONTEXT),
        ],
    ));

    expect($outcome->results()->count())->toBe(2)
        ->and($outcome->results()->where('result_type', 'erp_order')->sole()->facts_order_id)->toBeNull();
});

it('refuses edits and deletes in the model and in the database, on all three ledgers', function () {
    $order = outcomeOrder($this->laladia->id, $this->store->id);
    $outcome = $this->recorder->record(orderSpec($order));
    $attribution = $this->recorder->attribute($outcome, attributionTo(toolCallIn($this->laladia->id)));

    expect(fn () => $outcome->update(['outcome_type' => 'order.cancelled']))->toThrow(LogicException::class, 'append-only');
    expect(fn () => $outcome->delete())->toThrow(LogicException::class, 'append-only');
    expect(fn () => $attribution->update(['confidence' => 0.5]))->toThrow(LogicException::class, 'append-only');

    foreach (['outcomes', 'outcome_result_refs', 'outcome_attributions'] as $table) {
        expect(fn () => DB::transaction(fn () => DB::table($table)->update(['id' => DB::raw('id')])))->toThrow(QueryException::class, 'append-only');
        expect(fn () => DB::transaction(fn () => DB::table($table)->delete()))->toThrow(QueryException::class, 'append-only');
    }

    // RESTRICT: the order an outcome points at cannot be deleted from under it.
    expect(fn () => DB::transaction(fn () => DB::table('facts_orders')->where('id', $order->id)->delete()))->toThrow(QueryException::class);
});

it('enforces typed targets and the business of an attribution in the database itself', function () {
    $order = outcomeOrder($this->laladia->id, $this->store->id);
    $outcome = $this->recorder->record(orderSpec($order));
    $call = toolCallIn($this->laladia->id);
    $base = ['uuid' => (string) Str::uuid(), 'schema_version' => 'attribution.v1', 'outcome_id' => $outcome->id, 'business_id' => $this->laladia->id,
        'action_type' => 'tool_call', 'tool_call_id' => $call->id, 'action_ref' => 'x', 'role' => 'produced', 'method' => 'correlation',
        'method_version' => 'v1', 'confidence' => 1, 'content_digest' => str_repeat('a', 64), 'idempotency_key' => 'raw-'.Str::random(6)];

    // The column must match the declared kind.
    expect(fn () => DB::transaction(fn () => DB::table('outcome_attributions')->insert(array_merge($base, ['action_type' => 'approval']))))
        ->toThrow(QueryException::class, 'outcome_attributions_typed_action');
    // A cross-business row is impossible: the composite key binds it to its outcome's business.
    expect(fn () => DB::transaction(fn () => DB::table('outcome_attributions')->insert(array_merge($base, ['business_id' => $this->bionic->id]))))
        ->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('outcome_attributions')->insert(array_merge($base, ['confidence' => 1.5]))))
        ->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('outcome_result_refs')->insert(['outcome_id' => $outcome->id, 'result_type' => 'conversation',
        'role' => 'subject', 'source_system' => 'hq', 'external_ref' => 'c:1', 'facts_order_id' => $order->id])))
        ->toThrow(QueryException::class, 'outcome_result_refs_typed_target');

    DB::table('outcome_attributions')->insert($base);
    expect(OutcomeAttribution::count())->toBe(1);
});

it('records attributions with method-bounded confidence, and refuses another business\'s action', function () {
    $order = outcomeOrder($this->laladia->id, $this->store->id);
    $outcome = $this->recorder->record(orderSpec($order));

    $row = $this->recorder->attribute($outcome, attributionTo(toolCallIn($this->laladia->id)));
    expect($row->business_id)->toBe($this->laladia->id)
        ->and($row->action_type)->toBe(ActionType::ToolCall)
        ->and($row->actionId())->toBe($row->tool_call_id)
        ->and($row->confidence)->toBe(1.0)
        ->and($row->schema_version)->toBe('attribution.v1');

    // An HQ-scope action (no business of its own) is accepted (docs/107 Q6).
    $this->recorder->attribute($outcome, attributionTo($hqCall = toolCallIn(null), ['idempotencyKey' => 'attr:hq']));
    expect(OutcomeAttribution::where('tool_call_id', $hqCall->id)->exists())->toBeTrue();

    $refusals = [
        [attributionTo(toolCallIn($this->bionic->id), ['idempotencyKey' => 'a1']), 'belongs to another business'],
        [attributionTo(toolCallIn($this->laladia->id), ['idempotencyKey' => 'a2', 'confidence' => 0.9]), 'must be exactly 1'],
        [attributionTo(toolCallIn($this->laladia->id), ['idempotencyKey' => 'a3', 'method' => AttributionMethod::ModelInferred]), 'must be below 1'],
        [attributionTo(toolCallIn($this->laladia->id), ['idempotencyKey' => 'a4', 'method' => AttributionMethod::OperatorAsserted, 'confidence' => 0.8]), 'human actor and a reason'],
        [attributionTo(toolCallIn($this->laladia->id), ['idempotencyKey' => 'a5', 'proposalRef' => '0']), 'one proposal of an agent run'],
        [new AttributionSpec(ActionType::Approval, 999999, 'approval:x', AttributionRole::Approved, AttributionMethod::Correlation, 'v1', 1.0, 'a6'), 'does not exist'],
    ];
    foreach ($refusals as [$spec, $message]) {
        expect(fn () => $this->recorder->attribute($outcome, $spec))->toThrow(InvalidArgumentException::class, $message);
    }
    expect(OutcomeAttribution::count())->toBe(2);

    // Idempotent, and conflicting on a changed meaning.
    expect($this->recorder->attribute($outcome, attributionTo(ToolCall::find($row->tool_call_id)))->id)->toBe($row->id);
    expect(fn () => $this->recorder->attribute($outcome, attributionTo(ToolCall::find($row->tool_call_id), ['role' => AttributionRole::Contributed])))
        ->toThrow(OutcomeConflict::class);

    // A model's guess is allowed in the vocabulary, below certainty, and is never evidence-grade (D13).
    $guess = $this->recorder->attribute($outcome, attributionTo(toolCallIn($this->laladia->id), ['idempotencyKey' => 'guess',
        'method' => AttributionMethod::ModelInferred, 'role' => AttributionRole::Contributed, 'confidence' => 0.4]));
    expect($guess->method->isEvidenceGrade())->toBeFalse();

    // An operator may assert, as a human, with a reason.
    $operator = User::factory()->create();
    $asserted = $this->recorder->attribute($outcome, attributionTo(toolCallIn($this->laladia->id), ['idempotencyKey' => 'op',
        'method' => AttributionMethod::OperatorAsserted, 'role' => AttributionRole::Contributed, 'confidence' => 0.7,
        'actor' => OutcomeActor::human($operator), 'reason' => 'customer said the reply convinced her']));
    expect($asserted->actor_type)->toBe('human')->and($asserted->actor_id)->toBe($operator->id);
});

it('corrects and retracts through successor rows, audited, one successor per row', function () {
    $order = outcomeOrder($this->laladia->id, $this->store->id);
    $outcome = $this->recorder->record(orderSpec($order));
    $operator = User::factory()->create();

    $corrected = $this->recorder->supersede($outcome, orderSpec($order, [
        'idempotencyKey' => 'order.delivered:shop:fix-1', 'occurredAt' => CarbonImmutable::parse('2026-10-09 09:00:00', 'Asia/Dhaka'),
    ]), 'webhook carried the courier scan time, not delivery', OutcomeActor::human($operator));

    expect($corrected->supersedes_outcome_id)->toBe($outcome->id)
        ->and($corrected->reason)->toContain('courier scan')
        ->and(Outcome::query()->current()->pluck('id')->all())->toBe([$corrected->id])
        ->and(AuditLog::where('action', 'outcome.superseded')->sole()->actor_id)->toBe($operator->id);

    // Replaying the same correction is a no-op; a second, different correction of the same row is refused.
    expect($this->recorder->supersede($outcome, orderSpec($order, ['idempotencyKey' => 'order.delivered:shop:fix-1',
        'occurredAt' => CarbonImmutable::parse('2026-10-09 09:00:00', 'Asia/Dhaka')]), 'webhook carried the courier scan time, not delivery', OutcomeActor::human($operator))->id)
        ->toBe($corrected->id);
    expect(fn () => $this->recorder->supersede($outcome, orderSpec($order, ['idempotencyKey' => 'order.delivered:shop:fix-2',
        'occurredAt' => CarbonImmutable::parse('2026-10-09 08:00:00')]), 'another view', OutcomeActor::system()))
        ->toThrow(OutcomeConflict::class, 'already corrected');

    expect(fn () => $this->recorder->supersede($corrected, orderSpec($order, ['type' => 'order.cancelled', 'idempotencyKey' => 'x']), 'r', OutcomeActor::system()))
        ->toThrow(InvalidArgumentException::class, 'keeps the business and the type');
    expect(fn () => $this->recorder->retract($corrected, ' ', OutcomeActor::system()))->toThrow(InvalidArgumentException::class, 'needs a reason');

    $retracted = $this->recorder->retract($corrected, 'test order placed by staff', OutcomeActor::human($operator));
    expect($retracted->state)->toBe(OutcomeState::Retracted)
        ->and($retracted->results()->sole()->facts_order_id)->toBe($order->id)
        ->and(Outcome::query()->current()->count())->toBe(0)
        ->and(Outcome::count())->toBe(3)
        ->and(AuditLog::where('action', 'outcome.retracted')->count())->toBe(1);

    expect(fn () => $this->recorder->retract($retracted, 'again', OutcomeActor::system()))->toThrow(InvalidArgumentException::class, 'final');
});

it('re-judges and retracts attributions through successor rows', function () {
    $order = outcomeOrder($this->laladia->id, $this->store->id);
    $outcome = $this->recorder->record(orderSpec($order));
    $call = toolCallIn($this->laladia->id);
    $first = $this->recorder->attribute($outcome, attributionTo($call, ['method' => AttributionMethod::RuleWindow, 'role' => AttributionRole::Contributed, 'confidence' => 0.6]));

    $operator = User::factory()->create();
    $rejudged = $this->recorder->supersedeAttribution($first, attributionTo($call, ['idempotencyKey' => 'attr:rejudged',
        'method' => AttributionMethod::OperatorAsserted, 'role' => AttributionRole::Contributed, 'confidence' => 0.9]),
        'staff confirmed on the phone', OutcomeActor::human($operator));

    expect($rejudged->supersedes_attribution_id)->toBe($first->id)
        ->and(OutcomeAttribution::query()->current()->pluck('id')->all())->toBe([$rejudged->id]);

    expect(fn () => $this->recorder->supersedeAttribution($rejudged, attributionTo(toolCallIn($this->laladia->id), ['idempotencyKey' => 'z']), 'r', OutcomeActor::system()))
        ->toThrow(InvalidArgumentException::class, 'keeps its action');

    $retracted = $this->recorder->retractAttribution($rejudged, 'wrong customer', OutcomeActor::human($operator));
    expect($retracted->state)->toBe(OutcomeState::Retracted)
        ->and(OutcomeAttribution::query()->current()->count())->toBe(0)
        ->and(AuditLog::whereIn('action', ['outcome_attribution.superseded', 'outcome_attribution.retracted'])->count())->toBe(2);
});

it('reads through the one BusinessScope rule: a restricted user sees only his businesses', function () {
    $laladiaOrder = outcomeOrder($this->laladia->id, $this->store->id);
    $bionicStore = Store::where('slug', 'bionic-garden')->sole();
    $bionicOrder = outcomeOrder($this->bionic->id, $bionicStore->id, 'BN-1');

    $mine = $this->recorder->record(orderSpec($laladiaOrder));
    $theirs = $this->recorder->record(orderSpec($bionicOrder, ['results' => [new ResultRefSpec(ResultType::ShopOrder, 'shop', 'bionic-garden:BN-1', (int) $bionicOrder->id)]]));
    $this->recorder->attribute($mine, attributionTo(toolCallIn($this->laladia->id)));
    $this->recorder->attribute($theirs, attributionTo(toolCallIn($this->bionic->id), ['idempotencyKey' => 'attr:bionic']));

    $owner = User::factory()->create();
    $restricted = User::factory()->create();
    UserBusinessScope::create(['user_id' => $restricted->id, 'business_id' => $this->laladia->id, 'access' => 'full']);

    expect(Outcome::query()->visibleTo($owner)->count())->toBe(2)
        ->and(Outcome::query()->visibleTo($restricted)->pluck('id')->all())->toBe([$mine->id])
        ->and(OutcomeAttribution::query()->visibleTo($restricted)->pluck('outcome_id')->all())->toBe([$mine->id])
        ->and(OutcomeResultRef::query()->visibleTo($restricted)->pluck('outcome_id')->all())->toBe([$mine->id]);
});

it('takes a conversation outcome with the customer\'s provenance, referencing HQ\'s own record', function () {
    $conversation = Conversation::query()->forceCreate(['uuid' => (string) Str::uuid(), 'business_id' => $this->laladia->id, 'channel' => 'messenger', 'status' => 'open']);

    $outcome = $this->recorder->record(new OutcomeSpec(
        type: 'conversation.customer_responded', businessId: $this->laladia->id, occurredAt: now(), source: OutcomeSource::HqInternal,
        sourceSystem: 'hq', provenance: Provenance::Customer, idempotencyKey: 'conversation.customer_responded:'.$conversation->uuid.':m1',
        results: [new ResultRefSpec(ResultType::Conversation, 'hq', 'conversation:'.$conversation->uuid, (int) $conversation->id)],
    ));

    expect($outcome->results()->sole()->conversation_id)->toBe($conversation->id);

    expect(fn () => $this->recorder->record(new OutcomeSpec(
        type: 'conversation.customer_responded', businessId: $this->bionic->id, occurredAt: now(), source: OutcomeSource::HqInternal,
        sourceSystem: 'hq', provenance: Provenance::Customer, idempotencyKey: 'cross',
        results: [new ResultRefSpec(ResultType::Conversation, 'hq', 'conversation:'.$conversation->uuid, (int) $conversation->id)],
    )))->toThrow(InvalidArgumentException::class, 'belongs to another business');
});
