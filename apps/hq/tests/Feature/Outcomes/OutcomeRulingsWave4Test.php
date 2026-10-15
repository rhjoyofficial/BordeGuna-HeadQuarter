<?php

use App\Context\Provenance;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\FactsOrder;
use App\Models\Outcome;
use App\Models\OutcomeAttribution;
use App\Models\OutcomeResultRef;
use App\Models\Store;
use App\Models\ToolCall;
use App\Models\User;
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
use App\Outcomes\OutcomeTypeDefinition;
use App\Outcomes\OutcomeTypes;
use App\Outcomes\ResultRefSpec;
use App\Outcomes\ResultType;
use App\Tools\ToolRegistry;
use Carbon\CarbonImmutable;
use Database\Seeders\DomainSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 | docs/120 section B, S3 items 1-7, 30-32 and 35, as ruled on 15 Oct (docs/125). Each test names its item.
 | The ledger's own behaviour is in OutcomeLedgerTest; these pin the ruled properties that test did not yet pin.
 */

beforeEach(function () {
    $this->seed(DomainSeeder::class);
    $this->laladia = Business::where('slug', 'laladia')->sole();
    $this->store = Store::where('slug', 'laladia-com')->sole();
    $this->recorder = app(OutcomeRecorder::class);
});

afterEach(function () {
    // Item 4 swaps the registry for one test; the next test reads the real one again.
    (new ReflectionProperty(OutcomeTypes::class, 'definitions'))->setValue(null, null);
});

function w4Order(int $businessId, int $storeId, string $ref = 'LD-2001', array $extra = []): FactsOrder
{
    return FactsOrder::query()->forceCreate(array_merge([
        'business_id' => $businessId, 'store_id' => $storeId, 'order_ref' => $ref, 'source' => 'checkout',
        'status' => 'delivered', 'gross' => 900, 'placed_at' => now()->subDay(),
    ], $extra));
}

function w4Spec(FactsOrder $order, array $overrides = []): OutcomeSpec
{
    return new OutcomeSpec(...array_merge([
        'type' => 'order.delivered',
        'businessId' => (int) $order->business_id,
        'occurredAt' => CarbonImmutable::parse('2026-10-09 10:00:00', 'UTC'),
        'source' => OutcomeSource::ShopWebhook,
        'sourceSystem' => 'shop',
        'provenance' => Provenance::Business,
        'idempotencyKey' => 'order.delivered:shop:'.$order->store_id.':'.$order->order_ref,
        'results' => [new ResultRefSpec(ResultType::ShopOrder, 'shop', 'laladia-com:'.$order->order_ref, (int) $order->id)],
        'storeId' => (int) $order->store_id,
    ], $overrides));
}

function w4Call(int $businessId): ToolCall
{
    return ToolCall::query()->forceCreate(['tool_key' => 'commerce.take_order', 'status' => 'succeeded', 'idempotency_key' => 'k-'.Str::random(8),
        'business_id' => $businessId, 'created_at' => now()]);
}

function w4Attribution(ToolCall $call, string $key = 'attr:w4'): AttributionSpec
{
    return new AttributionSpec(ActionType::ToolCall, (int) $call->id, 'tool_call:'.$call->tool_key.':'.$call->idempotency_key,
        AttributionRole::Produced, AttributionMethod::DeterministicKey, 'test.v1', 1.0, $key);
}

it('item 1 (D4): requires the typed foreign key for an ERP order HQ already mirrors, and allows the number alone only while it does not', function () {
    $erpSpec = fn (string $number, ?int $rowId = null) => new OutcomeSpec(
        type: 'order.placed', businessId: $this->laladia->id, occurredAt: now(), source: OutcomeSource::ErpReadBack, sourceSystem: 'erp',
        provenance: Provenance::Business, idempotencyKey: 'order.placed:erp:'.$this->laladia->id.':'.$number,
        results: [new ResultRefSpec(ResultType::ErpOrder, 'erp', 'erp:'.$number, $rowId)],
    );

    // No mirror yet: the ERP's own number is the reference (and the idempotency key's source).
    expect($this->recorder->record($erpSpec('SO-77'))->results()->sole()->facts_order_id)->toBeNull();

    // A mirror exists: leaving the typed key out is refused, nothing is written; pointing at it is accepted.
    $mirror = w4Order($this->laladia->id, $this->store->id, 'SO-78', ['source_system' => 'erp', 'erp_order_number' => 'SO-78']);
    expect(fn () => $this->recorder->record($erpSpec('SO-78')))->toThrow(InvalidArgumentException::class, 'typed foreign key is required');
    expect(Outcome::count())->toBe(1);
    expect($this->recorder->record($erpSpec('SO-78', (int) $mirror->id))->results()->sole()->facts_order_id)->toBe($mirror->id);
});

it('item 1 (D4): the database refuses an internal result row without its typed key, and the natural key is mandatory', function () {
    $order = w4Order($this->laladia->id, $this->store->id);
    $outcome = $this->recorder->record(w4Spec($order));

    foreach (['shop_order', 'conversation', 'conversation_message', 'content_publication'] as $type) {
        expect(fn () => DB::transaction(fn () => DB::table('outcome_result_refs')->insert(['outcome_id' => $outcome->id, 'result_type' => $type,
            'role' => 'context', 'source_system' => 'hq', 'external_ref' => 'raw:'.$type])))->toThrow(QueryException::class, 'outcome_result_refs_typed_target');
    }
    // The natural key cannot be left out, at the database or at the recorder.
    expect(fn () => DB::transaction(fn () => DB::table('outcome_result_refs')->insert(['outcome_id' => $outcome->id, 'result_type' => 'shop_order',
        'role' => 'context', 'source_system' => 'shop', 'external_ref' => null, 'facts_order_id' => $order->id])))->toThrow(QueryException::class);
    expect(fn () => $this->recorder->record(w4Spec($order, ['idempotencyKey' => 'other',
        'results' => [new ResultRefSpec(ResultType::ShopOrder, 'shop', ' ', (int) $order->id)]])))->toThrow(InvalidArgumentException::class, "system of record's own key");
    expect(OutcomeResultRef::count())->toBe(1);
});

it('item 2 (D7): a proposal is an agent run plus proposal_ref; there is no proposal entity', function () {
    expect(Schema::hasTable('proposals'))->toBeFalse()
        ->and(Schema::hasTable('outcome_proposals'))->toBeFalse()
        ->and(array_map(fn (ActionType $t) => $t->value, ActionType::cases()))->not->toContain('proposal')
        ->and(Schema::hasColumn('outcome_attributions', 'proposal_ref'))->toBeTrue();
});

it('item 3 (D10): every outcome type names a business event, never a tool or a provider', function () {
    $tools = app(ToolRegistry::class)->keys();
    foreach (OutcomeTypes::all() as $type => $definition) {
        expect($type)->toMatch('/^(order|conversation|publication)\.[a-z_]+$/')
            ->and($tools)->not->toContain($type)
            ->and($type)->not->toMatch('/anthropic|openai|gemini|meta|whatsapp|messenger|telegram|send|reply|call|tool/');
    }
});

it('item 4 (D11): every row carries its schema version, and a changed meaning is a new version that never reinterprets an old row', function () {
    $order = w4Order($this->laladia->id, $this->store->id);
    $v1 = $this->recorder->record(w4Spec($order));
    expect($v1->schema_version)->toBe('outcome.v1');

    // Suppose `order.delivered` were redefined as v2: the v1 row's key does not quietly take the new meaning.
    $all = OutcomeTypes::all();
    $old = $all['order.delivered'];
    $all['order.delivered'] = new OutcomeTypeDefinition($old->type, $old->domain, $old->subjects, $old->sources, $old->provenances, 'outcome.v2');
    (new ReflectionProperty(OutcomeTypes::class, 'definitions'))->setValue(null, $all);

    expect(fn () => $this->recorder->record(w4Spec($order)))->toThrow(OutcomeConflict::class, 'different meaning');
    expect($v1->fresh()->schema_version)->toBe('outcome.v1');

    $v2 = $this->recorder->record(w4Spec($order, ['idempotencyKey' => 'order.delivered:v2:shop:'.$order->store_id.':'.$order->order_ref]));
    expect($v2->schema_version)->toBe('outcome.v2')->and(Outcome::pluck('schema_version')->sort()->values()->all())->toBe(['outcome.v1', 'outcome.v2']);
});

it('item 5 (D12): the key is the caller\'s, composed from the source identity; a correction or retraction derives its key from it, never a random one', function () {
    $order = w4Order($this->laladia->id, $this->store->id);
    $outcome = $this->recorder->record(w4Spec($order));
    expect($outcome->idempotency_key)->toBe('order.delivered:shop:'.$this->store->id.':LD-2001');

    $operator = User::factory()->create();
    $retracted = $this->recorder->retract($outcome, 'test order', OutcomeActor::human($operator));
    expect($retracted->idempotency_key)->toBe('retract:'.$outcome->idempotency_key);

    // A replay of the same retraction is the same row, not a second one: replay protection holds.
    expect($this->recorder->retract($outcome, 'test order', OutcomeActor::human($operator))->id)->toBe($retracted->id)
        ->and(Outcome::count())->toBe(2);

    // The recorder never mints a key itself.
    $code = (string) file_get_contents(app_path('Outcomes/OutcomeRecorder.php'));
    expect($code)->toContain("'idempotency_key' => \$spec->idempotencyKey")
        ->not->toMatch('/idempotency_?[kK]ey[\'"]?\s*=>\s*\(?string\)?\s*Str::/');
});

it('items 6 and 35: corrections and retractions are append-only successors; the current view is asserted rows with no successor', function () {
    $order = w4Order($this->laladia->id, $this->store->id);
    $first = $this->recorder->record(w4Spec($order));
    $attribution = $this->recorder->attribute($first, w4Attribution(w4Call($this->laladia->id)));
    $operator = OutcomeActor::human(User::factory()->create());

    $second = $this->recorder->supersede($first, w4Spec($order, ['idempotencyKey' => 'fix-1', 'occurredAt' => CarbonImmutable::parse('2026-10-09 09:00', 'UTC')]),
        'the courier scan time was not the delivery time', $operator);
    expect(Outcome::query()->current()->pluck('id')->all())->toBe([$second->id]);

    // The superseded row is no longer current: retracting or correcting it again forks nothing.
    expect(fn () => $this->recorder->retract($first, 'late second opinion', $operator))->toThrow(OutcomeConflict::class);
    expect(fn () => $this->recorder->supersede($first, w4Spec($order, ['idempotencyKey' => 'fix-2']), 'another view', $operator))->toThrow(OutcomeConflict::class);

    // Retracting the current row: a successor in state retracted, with the references; the first row and its
    // attribution are untouched (no update, no delete), and nothing is current any more.
    $before = DB::table('outcomes')->where('id', $first->id)->first();
    $retracted = $this->recorder->retract($second, 'a staff test order', $operator);
    expect($retracted->state)->toBe(OutcomeState::Retracted)
        ->and($retracted->supersedes_outcome_id)->toBe($second->id)
        ->and($retracted->results()->sole()->facts_order_id)->toBe($order->id)
        ->and(Outcome::query()->current()->count())->toBe(0)
        ->and(DB::table('outcomes')->where('id', $first->id)->first())->toEqual($before)
        ->and(OutcomeAttribution::query()->whereKey($attribution->id)->exists())->toBeTrue()
        ->and(Outcome::count())->toBe(3)
        ->and(AuditLog::whereIn('action', ['outcome.superseded', 'outcome.retracted'])->count())->toBe(2);

    // A retraction is final; the same retraction with another reason is a conflict, not a second row.
    expect(fn () => $this->recorder->retract($retracted, 'again', $operator))->toThrow(InvalidArgumentException::class, 'final');
    expect(fn () => $this->recorder->retract($second, 'a different reason', $operator))->toThrow(OutcomeConflict::class);
    expect(Outcome::count())->toBe(3);
});

it('item 35: no callable retract or supersede capability exists; the semantics live only in the recorder', function () {
    $keys = app(ToolRegistry::class)->keys();
    expect(array_values(array_filter($keys, fn (string $k) => preg_match('/retract|supersede|correct/i', $k) === 1)))->toBe([]);

    // Nothing outside app/Outcomes names the correction methods.
    $hits = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        $path = str_replace('\\', '/', $file->getPathname());
        if (! str_ends_with($path, '.php') || str_contains($path, '/app/Outcomes/')) {
            continue;
        }
        if (preg_match('/->(retract|supersede|retractAttribution|supersedeAttribution)\(/', (string) file_get_contents($path)) === 1) {
            $hits[] = $path;
        }
    }
    expect($hits)->toBe([]);
});

it('item 30 (D14): a first record or attribution writes no audit row of its own; the ledger row is the record', function () {
    $order = w4Order($this->laladia->id, $this->store->id);
    $outcome = $this->recorder->record(w4Spec($order));
    $this->recorder->attribute($outcome, w4Attribution(w4Call($this->laladia->id)));

    expect(AuditLog::where('action', 'like', 'outcome%')->count())->toBe(0)
        ->and(Outcome::count())->toBe(1)
        ->and(OutcomeAttribution::count())->toBe(1);
});

it('item 31: conversation.resolved stays an S3 business outcome; the ledger carries no resolution category of its own', function () {
    $definition = OutcomeTypes::get('conversation.resolved');
    expect($definition->domain)->toBe('conversation')
        ->and($definition->subjects)->toBe([ResultType::Conversation]);
    foreach (['outcomes', 'outcome_result_refs', 'outcome_attributions'] as $table) {
        foreach (['category', 'resolution', 'resolution_category'] as $column) {
            expect(Schema::hasColumn($table, $column))->toBeFalse("{$table}.{$column} belongs to the Conversation domain");
        }
    }
});
