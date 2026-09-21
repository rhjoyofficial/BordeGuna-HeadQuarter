<?php

use App\Erp\ErpRegistry;
use App\Gateway\CallerKind;
use App\Gateway\Descriptor\DataClass;
use App\Gateway\Descriptor\ReadWrite;
use App\Gateway\Descriptor\RiskTier;
use App\Gateway\Descriptor\ToolScope;
use App\Gateway\Identity\ActingIdentity;
use App\Gateway\OutcomeStatus;
use App\Gateway\ToolGateway;
use App\Gateway\ToolOutcome;
use App\Gateway\ToolRequest;
use App\Models\Agent;
use App\Models\Business;
use App\Models\ConnectorCall;
use App\Models\IntegrationCredential;
use App\Models\ToolCall;
use App\Models\User;
use App\Models\UserBusinessScope;
use App\Tools\ToolRegistry;
use Database\Seeders\AgentSeeder;
use Database\Seeders\DomainSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\Execution\Contexts;

/*
 | The five Phase 4 read tools (docs/81 C.5.3), through the ordinary gateway.
 |
 | ⛔ Reads only. Each is R2 and read-only, business-scoped, audited, and NO agent holds any of them:
 | the agents' tool lists and their stamps are untouched, so what a model is shown is byte for byte
 | what it was. `erp.check_order_eligibility` takes a phone and names a person, so it is not
 | agent-executable at all, and its phone and the name it returns never reach a stored row.
 */

const ORDER_READ_TOOLS = ['erp.lookup_product', 'erp.lookup_sku', 'erp.read_price', 'erp.read_availability', 'erp.check_order_eligibility'];

const ORDER_TOOLS_ERP = 'https://erp.order-tools.test';

beforeEach(function () {
    Cache::flush();
    $this->seed(DomainSeeder::class);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->owner = User::factory()->create();
    $this->owner->assignRole('owner');
    $this->owner = $this->owner->fresh();

    config(['hq.erp.base_url' => ORDER_TOOLS_ERP]);
    IntegrationCredential::create(['provider' => ErpRegistry::PROVIDER, 'account_ref' => 'bor-de-guna', 'credential_type' => 'token',
        'payload' => ['value' => 'order-reads-token'], 'status' => 'active']);
});

/** An ERP fixture as the ERP would send it for business 4, the one HQ's Bor de Guna maps to. */
function orderToolsBody(string $name): array
{
    $body = json_decode((string) file_get_contents(base_path("tests/fixtures/erp/order-reads/{$name}.json")), true);
    if (isset($body['meta'])) {
        $body['meta']['business_id'] = 4;
    }

    return $body;
}

function orderToolsUrl(string $path): string
{
    return ORDER_TOOLS_ERP."/api/hq/v1/4/order-reads/{$path}";
}

function orderTool(User $user, string $tool, array $args): ToolOutcome
{
    return app(ToolGateway::class)->execute(ToolRequest::forOperator($user, $tool, $args));
}

it('registers the five read tools at R2, read-only, business-scoped, with the business named and nothing else accepted', function () {
    $registry = app(ToolRegistry::class);

    foreach (ORDER_READ_TOOLS as $key) {
        $tool = $registry->get($key);
        $d = $tool->descriptor();
        $schema = $tool->inputSchema();

        expect($d->riskTier)->toBe(RiskTier::R2)
            ->and($d->readWrite)->toBe(ReadWrite::Read)
            ->and($d->scope)->toBe(ToolScope::Business)
            ->and($d->scopeFrom)->toBe('business:args.business_slug')
            ->and($d->permission)->toBe('hq.tools.erp.read')
            ->and($d->customerFacing)->toBeFalse()
            ->and($d->idempotent)->toBeTrue()
            ->and($d->replayWithinSeconds)->toBe(60)
            ->and($d->credentials[0]->provider)->toBe(ErpRegistry::PROVIDER)
            ->and($schema['required'])->toContain('business_slug')
            ->and($schema['additionalProperties'])->toBeFalse();
    }

    // Four ordinary reads an agent could one day be given; the fifth names a person from a phone.
    $eligibility = $registry->get('erp.check_order_eligibility')->descriptor();
    expect(collect(ORDER_READ_TOOLS)->filter(fn ($k) => $registry->get($k)->descriptor()->agentExecutable)->values()->all())
        ->toBe(['erp.lookup_product', 'erp.lookup_sku', 'erp.read_price', 'erp.read_availability'])
        ->and($eligibility->dataClass)->toBe(DataClass::Personal)
        ->and($eligibility->redact)->toContain('phone');
});

it('gives none of them to any agent, and an agent asking for one is refused before the ERP is called', function () {
    $this->seed(AgentSeeder::class);
    Agent::query()->update(['status' => 'active', 'enabling_user_id' => $this->owner->id]);
    Http::fake();

    foreach (Agent::all() as $agent) {
        expect(array_values(array_intersect($agent->allowed_tools ?? [], ORDER_READ_TOOLS)))->toBe([]);
    }

    $executive = Agent::where('key', 'executive')->sole();
    $outcome = app(ToolGateway::class)->execute(new ToolRequest(
        toolKey: 'erp.lookup_sku', args: ['business_slug' => 'bor-de-guna', 'sku' => 'HON-500'], caller: CallerKind::Agent,
        identity: ActingIdentity::forExecution(Contexts::for($executive)), correlationId: (string) Str::uuid(),
    ));

    expect($outcome->ok())->toBeFalse();
    Http::assertNothingSent();
});

it('looks up a SKU live through the gateway, recording the call, the business, the connector call and the ERP status', function () {
    Http::fake([orderToolsUrl('skus/HON-500') => Http::response(orderToolsBody('sku'))]);

    $outcome = orderTool($this->owner, 'erp.lookup_sku', ['business_slug' => 'bor-de-guna', 'sku' => 'HON-500']);

    expect($outcome->ok())->toBeTrue()
        ->and($outcome->output)->toMatchArray(['business' => 'bor-de-guna', 'source' => 'erp', 'contract' => 'hq-order-reads.v1',
            'as_of' => '2026-09-21T10:00:00+06:00', 'found' => true, 'variant_model' => 'none'])
        ->and($outcome->output['product']['name'])->toBe('Alpha Foods honey 500g')
        ->and($outcome->output)->toHaveKey('read_live_at');

    $call = ToolCall::findOrFail($outcome->toolCallId);
    expect($call->tool_key)->toBe('erp.lookup_sku')->and($call->status)->toBe('succeeded')
        ->and($call->business_id)->toBe(Business::where('slug', 'bor-de-guna')->value('id'));
    $connector = ConnectorCall::where('correlation_id', $call->correlation_id)->sole();
    expect($connector->operation)->toBe('lookupSku')->and($connector->response_status)->toBe(200)->and($connector->request_ref)->toBe('HON-500');
    Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer order-reads-token') && $r->header('X-Correlation-Id') === [$call->correlation_id]);
});

it('answers "no such SKU" and "no price in force" as facts, never as a guessed product or a zero', function () {
    Http::fake([
        orderToolsUrl('skus/NOPE-1') => Http::response(orderToolsBody('sku-not-found'), 404),
        orderToolsUrl('prices/OIL-1L*') => Http::response(orderToolsBody('price-none'), 404),
    ]);

    $sku = orderTool($this->owner, 'erp.lookup_sku', ['business_slug' => 'bor-de-guna', 'sku' => 'NOPE-1']);
    expect($sku->ok())->toBeTrue()->and($sku->output)->toMatchArray(['found' => false, 'product' => null, 'reason' => 'No product with SKU NOPE-1.']);

    $price = orderTool($this->owner, 'erp.read_price', ['business_slug' => 'bor-de-guna', 'sku' => 'OIL-1L']);
    expect($price->ok())->toBeTrue()
        ->and($price->output)->toMatchArray(['price_in_force' => false, 'unit_price' => null, 'price_source' => null, 'reason_code' => 'no_price_in_force']);
});

it('names the rule behind every price, so a price never travels without its source', function () {
    Http::fake([orderToolsUrl('prices/HON-500?quantity=12&store=laladia') => Http::response(orderToolsBody('price-store'))]);

    $out = orderTool($this->owner, 'erp.read_price', ['business_slug' => 'bor-de-guna', 'sku' => 'HON-500', 'quantity' => 12, 'store_key' => 'laladia'])->output;

    expect($out)->toMatchArray(['price_in_force' => true, 'unit_price' => '800.0000', 'currency' => 'BDT', 'priced_as' => 'store', 'mrp' => null])
        ->and($out['price_source'])->toMatchArray(['tier' => 'price_group', 'min_quantity' => '10.0000', 'explanation' => 'Price for group [RETAIL] from 10.0000 units'])
        ->and($out['promotions_applied_to_sales_orders'])->toBe([]);
    // The tool takes no customer: a price agreed with a named customer is not an agent's to read.
    expect(app(ToolRegistry::class)->get('erp.read_price')->inputSchema()['properties'])->not->toHaveKey('customer_id');
});

it('keeps stock and availability apart, as the ERP does', function () {
    Http::fake([orderToolsUrl('availability/GHE-250?store=laladia') => Http::response(orderToolsBody('availability-store'))]);

    $out = orderTool($this->owner, 'erp.read_availability', ['business_slug' => 'bor-de-guna', 'sku' => 'GHE-250', 'store_key' => 'laladia'])->output;

    expect($out['stock'])->toBe(['on_hand' => '0', 'unit' => 'pcs', 'warehouses' => [], 'warehouses_withheld' => false])
        ->and($out['availability']['store'])->toMatchArray(['sellable' => false, 'message' => 'Out of stock.', 'policy' => 'strict']);
});

it('checks a phone for a person without the phone or the name ever reaching a stored row', function () {
    Http::fake([orderToolsUrl('customer-eligibility') => Http::response(orderToolsBody('eligibility'))]);

    $outcome = orderTool($this->owner, 'erp.check_order_eligibility', ['business_slug' => 'bor-de-guna', 'phone' => '01711000004']);

    expect($outcome->ok())->toBeTrue()
        ->and($outcome->output['match']['display_name'])->toBe('Salma Begum')
        ->and($outcome->output['fields_the_erp_does_not_have'])->toBe(['blocked', 'cod_eligible', 'credit_hold']);

    $stored = json_encode(ToolCall::findOrFail($outcome->toolCallId)->only(['input', 'output', 'error']));
    expect($stored)->not->toContain('01711000004')->not->toContain('Salma');
    expect(json_encode(ConnectorCall::all()->toArray()))->not->toContain('01711000004');
});

it('refuses a business the person may not act on, and a call that names no business, before anything leaves HQ', function () {
    Http::fake();
    $narrow = User::factory()->create();
    $narrow->assignRole('owner');
    UserBusinessScope::create(['user_id' => $narrow->id, 'business_id' => Business::where('slug', 'laladia')->value('id')]);

    $other = orderTool($narrow->fresh(), 'erp.lookup_sku', ['business_slug' => 'bor-de-guna', 'sku' => 'HON-500']);
    expect($other->status)->toBe(OutcomeStatus::Denied);

    $none = orderTool($this->owner, 'erp.lookup_sku', ['sku' => 'HON-500']);
    expect($none->ok())->toBeFalse()->and($none->error)->toContain('business_slug');

    Http::assertNothingSent();
});

it('reports a token without the ability as that, and an ERP outage as unavailable, never as an empty answer', function () {
    Http::fake([
        orderToolsUrl('prices/*') => Http::response(orderToolsBody('forbidden'), 403),
        orderToolsUrl('availability/*') => Http::response(['message' => 'down'], 503),
    ]);

    $forbidden = orderTool($this->owner, 'erp.read_price', ['business_slug' => 'bor-de-guna', 'sku' => 'HON-500']);
    expect($forbidden->ok())->toBeFalse()->and($forbidden->error)->toContain('Token lacks ability sales.view.');

    $down = orderTool($this->owner, 'erp.read_availability', ['business_slug' => 'bor-de-guna', 'sku' => 'HON-500']);
    expect($down->ok())->toBeFalse()->and($down->output)->not->toHaveKey('stock');
});

it('sends nothing to the ERP but the five reads, and never a write', function () {
    Http::fake([ORDER_TOOLS_ERP.'/*' => Http::response(orderToolsBody('products'))]);

    orderTool($this->owner, 'erp.lookup_product', ['business_slug' => 'bor-de-guna', 'query' => 'honey', 'limit' => 2]);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $r) => $r->method() === 'GET' && str_starts_with($r->url(), orderToolsUrl('products?')));
});
