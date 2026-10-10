<?php

use App\Commerce\Orders\ErpOperationKey;
use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\Business;
use App\Models\FactsOrder;
use App\Models\Outcome;
use App\Models\OutcomeAttribution;
use App\Models\Store;
use App\Models\ToolCall;
use App\Outcomes\ActionType;
use App\Outcomes\AttributionMethod;
use App\Outcomes\AttributionRole;
use App\Outcomes\Recorders\ErpOrderPlacedRecorder;
use App\Outcomes\ResultType;
use App\Tools\Commerce\TakeOrderTool;
use Database\Seeders\DomainSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | docs/107 section 11: the illustrative recorder, inert (nothing calls it outside these tests). An ERP
 | order HQ placed becomes `order.placed`, referenced by the ERP's own order number, credited only where
 | it is proven: the take_order call by recomputing the operation key it sent, the approval by the
 | call's `approval:{uuid}` key, the agent run by the approval's correlation id. No number is copied.
 */

beforeEach(function () {
    $this->seed(DomainSeeder::class);
    $this->business = Business::where('slug', 'laladia')->sole();
    $this->store = Store::where('slug', 'laladia-com')->sole();

    $correlation = (string) Str::uuid();
    $this->run = AgentRun::create(['uuid' => (string) Str::uuid(), 'agent_key' => 'support', 'correlation_id' => $correlation,
        'trigger' => 'conversation', 'question' => 'q', 'autonomy_level' => 'a0', 'status' => 'answered', 'business_id' => $this->business->id]);
    $this->approval = Approval::create(['uuid' => (string) Str::uuid(), 'tool_key' => TakeOrderTool::KEY, 'tool_version' => '1.0.0',
        'payload' => ['x' => 1], 'payload_hash' => str_repeat('a', 64), 'risk_tier' => 'r4', 'action_class' => 'approval_required',
        'status' => 'pending', 'business_id' => $this->business->id, 'correlation_id' => $correlation, 'expires_at' => now()->addDay()]);
    $gatewayKey = 'approval:'.$this->approval->uuid;
    $this->call = ToolCall::query()->forceCreate(['tool_key' => TakeOrderTool::KEY, 'status' => 'succeeded', 'idempotency_key' => $gatewayKey,
        'business_id' => $this->business->id, 'approval_id' => $this->approval->id, 'correlation_id' => (string) Str::uuid(), 'created_at' => now()]);
    DB::table('approvals')->where('id', $this->approval->id)->update(['status' => 'consumed', 'consumed_at' => now(), 'resulting_tool_call_id' => $this->call->id]);

    $this->fact = FactsOrder::query()->forceCreate([
        'business_id' => $this->business->id, 'store_id' => $this->store->id, 'order_ref' => 'HQ-77', 'source' => 'hq_ai', 'status' => 'pending',
        'gross' => 2470.50, 'placed_at' => now()->subHour(), 'source_system' => 'erp', 'provenance' => 'business',
        'erp_order_id' => 501, 'erp_order_number' => 'SO-2026-0042', 'erp_status' => 'open',
        'hq_key' => ErpOperationKey::for($gatewayKey, $this->business->id, TakeOrderTool::ORDER_STEP),
        'correlation_id' => 'corr-read-back', 'read_back_digest' => str_repeat('d', 64), 'read_back_at' => now()->subMinutes(50),
    ]);
});

it('records order.placed against the ERP order and credits the call, the approval and the proposing run, each proven', function () {
    $outcome = app(ErpOrderPlacedRecorder::class)->record($this->fact, $this->call);

    expect($outcome->outcome_type)->toBe('order.placed')
        ->and($outcome->business_id)->toBe($this->business->id)
        ->and($outcome->store_id)->toBe($this->store->id)
        ->and($outcome->source)->toBe('erp_read_back')
        ->and($outcome->idempotency_key)->toBe('order.placed:erp:'.$this->business->id.':SO-2026-0042')
        ->and($outcome->source_as_of->timestamp)->toBe($this->fact->read_back_at->timestamp)
        ->and($outcome->evidence)->toBe(['hq_operation_key' => $this->fact->hq_key]);

    $ref = $outcome->results()->sole();
    expect($ref->result_type)->toBe(ResultType::ErpOrder)
        ->and($ref->external_ref)->toBe('erp:SO-2026-0042')
        ->and($ref->facts_order_id)->toBe($this->fact->id);

    // No number travelled: neither the row nor its evidence carries the order's total.
    expect(json_encode([$outcome->toArray(), $ref->toArray()]))->not->toContain('2470');

    $credits = OutcomeAttribution::where('outcome_id', $outcome->id)->orderBy('id')->get();
    expect($credits->map(fn ($a) => [$a->action_type, $a->role, $a->method])->all())->toBe([
        [ActionType::ToolCall, AttributionRole::Produced, AttributionMethod::DeterministicKey],
        [ActionType::Approval, AttributionRole::Approved, AttributionMethod::DeterministicKey],
        [ActionType::AgentRun, AttributionRole::Proposed, AttributionMethod::Correlation],
    ])
        ->and($credits[0]->tool_call_id)->toBe($this->call->id)
        ->and($credits[1]->approval_id)->toBe($this->approval->id)
        ->and($credits[2]->agent_run_id)->toBe($this->run->id)
        ->and($credits->every(fn ($a) => $a->confidence === 1.0 && $a->method->isEvidenceGrade()))->toBeTrue();
});

it('is idempotent: recording the same order twice writes nothing new', function () {
    $recorder = app(ErpOrderPlacedRecorder::class);
    $first = $recorder->record($this->fact, $this->call);
    $second = $recorder->record($this->fact->fresh(), $this->call->fresh());

    expect($second->id)->toBe($first->id)
        ->and(Outcome::count())->toBe(1)
        ->and(OutcomeAttribution::count())->toBe(3);
});

it('refuses a call that did not place this order, and a fact that is not an ERP read-back', function () {
    $other = ToolCall::query()->forceCreate(['tool_key' => TakeOrderTool::KEY, 'status' => 'succeeded', 'idempotency_key' => 'approval:'.Str::uuid(),
        'business_id' => $this->business->id, 'created_at' => now()]);
    expect(fn () => app(ErpOrderPlacedRecorder::class)->record($this->fact, $other))->toThrow(InvalidArgumentException::class, 'did not place this order');

    $failed = ToolCall::query()->forceCreate(['tool_key' => TakeOrderTool::KEY, 'status' => 'failed', 'idempotency_key' => $this->call->idempotency_key,
        'business_id' => $this->business->id, 'created_at' => now()]);
    expect(fn () => app(ErpOrderPlacedRecorder::class)->record($this->fact, $failed))->toThrow(InvalidArgumentException::class, 'succeeded commerce.take_order');

    $shopFact = FactsOrder::query()->forceCreate(['business_id' => $this->business->id, 'store_id' => $this->store->id, 'order_ref' => 'LD-9',
        'status' => 'pending', 'source' => 'checkout']);
    expect(fn () => app(ErpOrderPlacedRecorder::class)->record($shopFact, $this->call))->toThrow(InvalidArgumentException::class, 'ERP read-back fact');

    expect(Outcome::count())->toBe(0)->and(OutcomeAttribution::count())->toBe(0);
});

it('credits only the call when the order was placed directly, without an approval', function () {
    $directKey = 'direct:'.Str::uuid();
    $call = ToolCall::query()->forceCreate(['tool_key' => TakeOrderTool::KEY, 'status' => 'succeeded', 'idempotency_key' => $directKey,
        'business_id' => $this->business->id, 'created_at' => now()]);
    $fact = FactsOrder::query()->forceCreate([
        'business_id' => $this->business->id, 'store_id' => $this->store->id, 'order_ref' => 'HQ-78', 'source' => 'hq_ai', 'status' => 'pending',
        'source_system' => 'erp', 'erp_order_number' => 'SO-2026-0043', 'hq_key' => ErpOperationKey::for($directKey, $this->business->id, TakeOrderTool::ORDER_STEP),
    ]);

    $outcome = app(ErpOrderPlacedRecorder::class)->record($fact, $call);

    expect(OutcomeAttribution::where('outcome_id', $outcome->id)->pluck('action_type')->all())->toBe([ActionType::ToolCall]);
});

it('is inert: nothing outside the outcome namespace, the one gateway tool and the tests refers to the recorders', function () {
    // docs/115 §4 (ruling Q11): `app/Tools/Outcomes/AttributeOutcomeTool.php` is the ONE reviewed caller of OutcomeRecorder outside
    // app/Outcomes, named here deliberately; OutcomeWritePathStructureTest holds the full write-path pin.
    $permitted = [app_path('Tools'.DIRECTORY_SEPARATOR.'Outcomes'.DIRECTORY_SEPARATOR.'AttributeOutcomeTool.php')];
    $hits = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $file) {
        if ($file->getExtension() !== 'php' || str_starts_with($file->getPathname(), app_path('Outcomes').DIRECTORY_SEPARATOR)) {
            continue;
        }
        if (in_array($file->getPathname(), $permitted, true)) {
            continue;
        }
        $source = file_get_contents($file->getPathname());
        if (preg_match('/use App\\\\Outcomes\\\\(OutcomeRecorder|Recorders\\\\)|OutcomeRecorder::class|ErpOrderPlacedRecorder::class/', $source) === 1) {
            $hits[] = $file->getPathname();
        }
    }

    expect($hits)->toBe([]);
});

it('names the take_order tool only by value, pinned here to the tool\'s own constants', function () {
    expect(ErpOrderPlacedRecorder::TAKE_ORDER_KEY)->toBe(TakeOrderTool::KEY)
        ->and(ErpOrderPlacedRecorder::ORDER_STEP)->toBe(TakeOrderTool::ORDER_STEP);
});
