<?php

use App\Context\Provenance;
use App\Gateway\CallerKind;
use App\Gateway\Descriptor\ReadWrite;
use App\Gateway\Descriptor\RiskTier;
use App\Gateway\Descriptor\ToolScope;
use App\Gateway\Identity\ActingIdentity;
use App\Gateway\OutcomeClass;
use App\Gateway\OutcomeStatus;
use App\Gateway\ToolGateway;
use App\Gateway\ToolRequest;
use App\Models\Agent;
use App\Models\Business;
use App\Models\FactsOrder;
use App\Models\OutcomeAttribution;
use App\Models\Store;
use App\Models\ToolCall;
use App\Models\User;
use App\Models\UserBusinessScope;
use App\Outcomes\AttributionMethod;
use App\Outcomes\AttributionRole;
use App\Outcomes\OutcomeRecorder;
use App\Outcomes\OutcomeSource;
use App\Outcomes\OutcomeSpec;
use App\Outcomes\ResultRefSpec;
use App\Outcomes\ResultType;
use App\Tools\Outcomes\AttributeOutcomeTool;
use App\Tools\ToolContext;
use App\Tools\ToolRegistry;
use Database\Seeders\AgentSeeder;
use Database\Seeders\DomainSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Execution\Contexts;

/*
 | docs/110 §3 Q11, docs/115 §4: operator-asserted attribution is a typed, human-authorized Tool Gateway
 | capability, `outcomes.attribute`, and nothing else. What must hold:
 |  - the descriptor says what the code does: R3 write, `hq.outcomes.attribute`, business-scoped, permanently
 |    human-only, held by no agent, effectful (so the gateway coverage pin applies);
 |  - a person with the permission records exactly one `operator_asserted` row, as that person, with the reason
 |    and the evidence reference, through the recorder (every ledger rule applies: business binding, Q6, Q3,
 |    idempotency, append-only);
 |  - without the permission, as an agent, for another business's outcome, for an action of another business
 |    or with no business of its own, or with no human behind the call: refused, nothing written.
 */

const ATTRIBUTE = 'outcomes.attribute';

beforeEach(function () {
    Cache::flush();
    $this->seed(DomainSeeder::class);
    (new RolesAndPermissionsSeeder)->run();
    $this->laladia = Business::where('slug', 'laladia')->sole();
    $this->bionic = Business::where('slug', 'bionic')->sole();
    $this->store = Store::where('slug', 'laladia-com')->sole();

    $order = FactsOrder::query()->forceCreate(['business_id' => $this->laladia->id, 'store_id' => $this->store->id, 'order_ref' => 'LD-7',
        'source' => 'checkout', 'status' => 'delivered', 'gross' => 900, 'placed_at' => now()->subDay()]);
    $this->outcome = app(OutcomeRecorder::class)->record(new OutcomeSpec(
        type: 'order.delivered', businessId: $this->laladia->id, occurredAt: now()->subHour(), source: OutcomeSource::ShopWebhook,
        sourceSystem: 'shop', provenance: Provenance::Business, idempotencyKey: 'order.delivered:shop:'.$this->store->id.':LD-7',
        results: [new ResultRefSpec(ResultType::ShopOrder, 'shop', 'laladia-com:LD-7', (int) $order->id)], storeId: $this->store->id,
    ));
    $this->call = ToolCall::query()->forceCreate(['tool_key' => 'messaging.send_reply', 'status' => 'succeeded', 'idempotency_key' => 'reply-'.Str::random(6),
        'business_id' => $this->laladia->id, 'created_at' => now()->subHours(2)]);
});

function attributeArgs(object $t, array $overrides = []): array
{
    return array_merge([
        'business_slug' => 'laladia', 'outcome_uuid' => $t->outcome->uuid, 'action_type' => 'tool_call', 'action_id' => $t->call->id,
        'role' => 'contributed', 'confidence' => 0.8, 'reason' => 'the customer said on the phone that this reply made her order',
        'evidence_ref' => 'call-note:2026-10-11:0931',
    ], $overrides);
}

function attributeAs(User $user, array $args, ?ActingIdentity $identity = null)
{
    return app(ToolGateway::class)->execute(new ToolRequest(
        toolKey: ATTRIBUTE, args: $args, caller: CallerKind::HumanUi, identity: $identity ?? ActingIdentity::human($user), correlationId: (string) Str::uuid(),
    ));
}

function attributeOperator(array $permissions = [AttributeOutcomeTool::PERMISSION]): User
{
    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user->fresh();
}

it('declares what it is: R3 write, its own permission, business-scoped, permanently human-only, held by no agent', function () {
    $this->seed(AgentSeeder::class);
    $d = app(ToolRegistry::class)->get(ATTRIBUTE)->descriptor();

    expect($d->key)->toBe(AttributeOutcomeTool::KEY)->toBe('outcomes.attribute')
        ->and($d->riskTier)->toBe(RiskTier::R3)
        ->and($d->readWrite)->toBe(ReadWrite::Write)
        ->and($d->hasEffect())->toBeTrue()
        ->and($d->permission)->toBe('hq.outcomes.attribute')
        ->and($d->scope)->toBe(ToolScope::Business)
        ->and($d->scopeFrom)->toBe('business:args.business_slug')
        ->and($d->agentExecutable)->toBeFalse()
        ->and($d->permanentlyHumanOnly)->toBeTrue()
        ->and($d->agentRefusalReason())->toContain('permanently human-only')
        ->and($d->customerFacing)->toBeFalse()
        ->and($d->channel->isOutbound())->toBeFalse()
        ->and($d->consentRequired)->toBeNull()
        ->and($d->supportedBusinesses)->toBe(['*']);

    // The permission is seeded, held by owner and operator through `*`, and by no other role.
    expect(RolesAndPermissionsSeeder::PERMISSIONS)->toContain('hq.outcomes.attribute');
    foreach (RolesAndPermissionsSeeder::ROLES as $role => $permissions) {
        expect($permissions === '*' || ! in_array('hq.outcomes.attribute', $permissions, true))->toBeTrue("[{$role}] is given hq.outcomes.attribute");
    }

    // Held by no agent: not in any seeded agent's list, and not in the seeder's constants.
    expect(Agent::count())->toBeGreaterThan(0);
    foreach (Agent::all() as $agent) {
        expect($agent->mayUseTool(ATTRIBUTE))->toBeFalse("agent [{$agent->key}] holds ".ATTRIBUTE);
    }
    expect(file_get_contents(database_path('seeders/AgentSeeder.php')))->not->toContain(ATTRIBUTE);

    // The schema takes the ledger's own vocabularies and nothing else; method and actor are not inputs.
    $schema = app(ToolRegistry::class)->get(ATTRIBUTE)->inputSchema();
    expect($schema['required'])->toBe(['business_slug', 'outcome_uuid', 'action_type', 'action_id', 'role', 'confidence', 'reason', 'evidence_ref'])
        ->and($schema['additionalProperties'])->toBeFalse()
        ->and(array_keys($schema['properties']))->not->toContain('method', 'actor', 'actor_user_id', 'method_version')
        ->and($schema['properties']['action_type']['enum'])->toContain('tool_call', 'agent_run', 'approval')
        ->and($schema['properties']['role']['enum'])->toBe(['produced', 'proposed', 'approved', 'contributed']);
});

it('records one operator_asserted row as the signed-in person, through the recorder, with reason and evidence', function () {
    $operator = attributeOperator();

    $outcome = attributeAs($operator, attributeArgs($this));

    expect($outcome->status)->toBe(OutcomeStatus::Succeeded, (string) $outcome->error)
        ->and($outcome->output['method'])->toBe('operator_asserted')
        ->and($outcome->output['method_version'])->toBe('operator_asserted.v1')
        ->and($outcome->output['confidence'])->toBe(0.8)
        ->and($outcome->output['replayed'])->toBeFalse()
        ->and($outcome->output['action_ref'])->toBe('tool_call:messaging.send_reply:'.$this->call->idempotency_key)
        ->and($outcome->output['actor'])->toBe($operator->email);

    $row = OutcomeAttribution::query()->sole();
    expect($row->outcome_id)->toBe($this->outcome->id)
        ->and($row->business_id)->toBe($this->laladia->id)
        ->and($row->tool_call_id)->toBe($this->call->id)
        ->and($row->method)->toBe(AttributionMethod::OperatorAsserted)
        ->and($row->role)->toBe(AttributionRole::Contributed)
        ->and($row->actor_type)->toBe('human')
        ->and($row->actor_id)->toBe($operator->id)
        ->and($row->reason)->toContain('on the phone')
        ->and($row->evidence['evidence_ref'])->toBe('call-note:2026-10-11:0931')
        ->and($row->evidence['asserted_through'])->toBe('outcomes.attribute')
        ->and($row->method->isEvidenceGrade())->toBeTrue()
        ->and($row->method->isDeterministicProof())->toBeFalse();

    // The gateway recorded the call under the tool's audit action, as the person, R3, allowed without approval.
    $call = ToolCall::query()->where('tool_key', ATTRIBUTE)->sole();
    expect($call->status)->toBe('succeeded')
        ->and($call->actor_id)->toBe($operator->id)
        ->and($call->business_id)->toBe($this->laladia->id)
        ->and($call->risk_tier)->toBe('r3')
        ->and($call->approval_id)->toBeNull();

    // The same assertion again is a replay in the ledger; a changed meaning under the same assertion is refused, never overwritten.
    $again = attributeAs($operator, attributeArgs($this));
    expect($again->status)->toBe(OutcomeStatus::Succeeded, (string) $again->error)->and($again->output['replayed'])->toBeTrue()->and(OutcomeAttribution::count())->toBe(1);

    $changed = attributeAs($operator, attributeArgs($this, ['confidence' => 0.3]));
    expect($changed->status)->toBe(OutcomeStatus::Failed)
        ->and($changed->error)->toContain('never overwrites')
        ->and(OutcomeAttribution::count())->toBe(1);
});

it('refuses a person without the permission, and an agent permanently, before the tool runs', function () {
    $viewer = attributeOperator(['hq.tools.view']);
    $refused = attributeAs($viewer, attributeArgs($this));
    expect($refused->status)->toBe(OutcomeStatus::Denied)
        ->and($refused->class)->toBe(OutcomeClass::PermissionDenied)
        ->and(OutcomeAttribution::count())->toBe(0);

    // An agent whose enabling human holds the permission and whose list names the tool is still refused: permanently.
    $owner = User::factory()->create();
    $owner->assignRole('owner');
    $agent = Agent::create(['key' => 'test-attributor', 'name' => 'Test', 'status' => 'active', 'enabling_user_id' => $owner->id, 'allowed_tools' => [ATTRIBUTE]]);
    $asAgent = attributeAs($owner, attributeArgs($this), ActingIdentity::forExecution(Contexts::for($agent)));
    expect($asAgent->status)->toBe(OutcomeStatus::Denied)
        ->and($asAgent->policyReason)->toContain('permanently human-only')
        ->and(OutcomeAttribution::count())->toBe(0);
});

it('fails closed on scope: another business\'s outcome, an action of another business, an action with no business, a restricted user', function () {
    $operator = attributeOperator();

    // The outcome belongs to laladia; naming bionic finds nothing (and the gateway scoped the call to bionic).
    $other = attributeAs($operator, attributeArgs($this, ['business_slug' => 'bionic']));
    expect($other->status)->toBe(OutcomeStatus::Failed)->and($other->error)->toContain('No outcome');

    // An action of another business: the recorder refuses (D16).
    $bionicCall = ToolCall::query()->forceCreate(['tool_key' => 'messaging.send_reply', 'status' => 'succeeded', 'idempotency_key' => 'b-'.Str::random(6),
        'business_id' => $this->bionic->id, 'created_at' => now()]);
    $cross = attributeAs($operator, attributeArgs($this, ['action_id' => $bionicCall->id]));
    expect($cross->status)->toBe(OutcomeStatus::Failed)->and($cross->error)->toContain('belongs to another business');

    // An HQ-wide action with no business of its own: ruling Q6, refused with the named reason.
    $hqCall = ToolCall::query()->forceCreate(['tool_key' => 'erp.poll_overview', 'status' => 'succeeded', 'idempotency_key' => 'h-'.Str::random(6),
        'business_id' => null, 'created_at' => now()]);
    $implicit = attributeAs($operator, attributeArgs($this, ['action_id' => $hqCall->id]));
    expect($implicit->status)->toBe(OutcomeStatus::Failed)->and($implicit->error)->toContain('no business of its own')->toContain('ruling Q6');

    // A missing action, and a well-formed but unknown outcome.
    $missing = attributeAs($operator, attributeArgs($this, ['action_id' => 999999]));
    expect($missing->status)->toBe(OutcomeStatus::Failed)->and($missing->error)->toContain('does not exist');
    $unknown = attributeAs($operator, attributeArgs($this, ['outcome_uuid' => (string) Str::uuid()]));
    expect($unknown->status)->toBe(OutcomeStatus::Failed)->and($unknown->error)->toContain('No outcome');

    // A user restricted to bionic may not act on laladia at all: the gateway's scope check, before the tool.
    $restricted = attributeOperator();
    UserBusinessScope::create(['user_id' => $restricted->id, 'business_id' => $this->bionic->id, 'access' => 'full']);
    $outOfScope = attributeAs($restricted, attributeArgs($this));
    expect($outOfScope->status)->toBe(OutcomeStatus::Denied)->and($outOfScope->class)->toBe(OutcomeClass::ScopeDenied);

    // Malformed arguments never reach the tool: no reason, a short reason, a confidence above 1, an extra field, a method.
    foreach ([
        array_diff_key(attributeArgs($this), ['reason' => 1]),
        attributeArgs($this, ['reason' => 'too short']),
        array_diff_key(attributeArgs($this), ['evidence_ref' => 1]),
        attributeArgs($this, ['confidence' => 1.5]),
        attributeArgs($this, ['method' => 'deterministic_key']),
        attributeArgs($this, ['role' => 'owner']),
    ] as $bad) {
        expect(attributeAs($operator, $bad)->status)->toBe(OutcomeStatus::Invalid);
    }

    expect(OutcomeAttribution::count())->toBe(0)
        ->and(DB::table('tool_calls')->where('tool_key', ATTRIBUTE)->where('status', 'succeeded')->count())->toBe(0);
});

it('refuses a call with no human behind it, inside the tool, before the recorder is asked', function () {
    // A system identity holds nothing, so the gateway refuses first; the tool's own guard is proven directly,
    // because it is the line that holds if a future caller ever reaches execute() with a service identity.
    $tool = app(ToolRegistry::class)->get(ATTRIBUTE);
    $context = new ToolContext(idempotencyKey: 'k', correlationId: 'c', businessId: $this->laladia->id, actorUserId: null);
    $result = $tool->execute(attributeArgs($this), $context);

    expect($result->ok())->toBeFalse()
        ->and($result->error)->toContain('no human actor')
        ->and(OutcomeAttribution::count())->toBe(0);

    $system = app(ToolGateway::class)->execute(new ToolRequest(
        toolKey: ATTRIBUTE, args: attributeArgs($this), caller: CallerKind::System, identity: ActingIdentity::system(), correlationId: (string) Str::uuid(),
    ));
    expect($system->status)->toBe(OutcomeStatus::Denied)->and(OutcomeAttribution::count())->toBe(0);
});
