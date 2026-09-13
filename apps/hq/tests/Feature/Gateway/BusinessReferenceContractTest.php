<?php

use App\Agents\AgentToolset;
use App\Agents\ContextAssembler;
use App\Gateway\CallerKind;
use App\Gateway\Identity\ActingIdentity;
use App\Gateway\OutcomeStatus;
use App\Gateway\Scope\BusinessReferenceResolver;
use App\Gateway\Scope\ScopeResolver;
use App\Gateway\ToolGateway;
use App\Gateway\ToolRequest;
use App\Models\Agent;
use App\Models\Brand;
use App\Models\Business;
use App\Models\ToolCall;
use App\Models\ToolRegistration;
use App\Models\User;
use App\Models\UserBusinessScope;
use App\Tools\ToolRegistry;
use Database\Seeders\AgentSeeder;
use Database\Seeders\DomainSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;

/*
 | The business a tool argument names is a contract, not a guess (docs/67, ADR-039).
 |
 | Gate 4 refused `erp.read_snapshot` twelve times for naming no business. The tool's schema said the
 | argument was optional, the question said "Bionic Garden" while the slug is `bionic`, and the
 | refusal named no argument. These tests hold the three corrections: HQ resolves a reference
 | deterministically from every name it holds; the agent's catalogue marks the scope argument
 | required and enumerates exactly the businesses it may name; and a refusal says which argument
 | would have worked and what it takes. Resolving is not authorising: a business the caller may not
 | act on is still refused, after resolution, by the scope check that always did.
 */

beforeEach(function () {
    Cache::flush();
    $this->seed(DomainSeeder::class);
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->owner = User::factory()->create();
    $this->owner->givePermissionTo(['hq.tools.facts.read', 'hq.tools.erp.read', 'hq.tools.commerce.read', 'hq.approvals.view', 'hq.jobs.view']);

    $this->seed(AgentSeeder::class);
    Agent::query()->update(['status' => 'active', 'enabling_user_id' => $this->owner->id]);
    $this->executive = Agent::where('key', 'executive')->sole();
});

function scopedRequest(string $tool, array $args, User $enabling, ?int $businessId = null): ToolRequest
{
    return new ToolRequest(
        toolKey: $tool,
        args: $args,
        caller: CallerKind::Agent,
        identity: ActingIdentity::agent(Agent::where('key', 'executive')->sole(), $enabling),
        correlationId: 'corr-'.uniqid(),
        businessId: $businessId,
    );
}

// ── The resolver ────────────────────────────────────────────────────────────────────────────────

it('resolves a business from its slug, its name, its store, its brand, and a display name with accents, all to one row', function () {
    $resolver = app(BusinessReferenceResolver::class);
    $bionic = Business::where('slug', 'bionic')->sole();

    foreach (['bionic', 'Bionic', 'bionic-garden', 'bionic.garden', 'Bionic Garden', 'BIONIC GARDEN', ' bionic garden '] as $reference) {
        expect($resolver->business($reference)?->id)->toBe($bionic->id, "[{$reference}] should resolve to bionic");
    }

    $parent = Business::where('slug', 'bor-de-guna')->sole();
    foreach (['bor-de-guna', 'Bor de Guna', 'Bôr dé Güna', 'erp-counter', 'ERP counter / POS'] as $reference) {
        expect($resolver->business($reference)?->id)->toBe($parent->id, "[{$reference}] should resolve to bor-de-guna");
    }
});

it('resolves nothing for a name HQ does not hold, and nothing for an empty reference, rather than widening', function () {
    $resolver = app(BusinessReferenceResolver::class);

    expect($resolver->business('not-a-business'))->toBeNull()
        ->and($resolver->business(''))->toBeNull()
        ->and($resolver->business('   '))->toBeNull()
        ->and($resolver->store('nowhere'))->toBeNull();
});

it('resolves nothing when a reference names two different businesses, because an ambiguity is not a choice', function () {
    // A brand of Econic that happens to carry LaLaDia's name: a data defect, and the resolver must
    // not pick whichever business it met first.
    $econic = Business::where('slug', 'econic')->sole();
    Brand::create(['business_id' => $econic->id, 'code' => 'DUP', 'slug' => 'duplicate', 'name' => 'LaLaDia', 'is_active' => true]);

    expect(app(BusinessReferenceResolver::class)->business('LaLaDia'))->toBeNull();
    // The slug is still unambiguous.
    expect(app(BusinessReferenceResolver::class)->business('laladia')?->slug)->toBe('laladia');
});

it('normalises the way a person would: case, accents and punctuation do not distinguish two names', function () {
    expect(BusinessReferenceResolver::normalise('Bôr dé Güna'))->toBe('bor de guna')
        ->and(BusinessReferenceResolver::normalise('bionic.garden'))->toBe('bionic garden')
        ->and(BusinessReferenceResolver::normalise('  BIONIC-Garden  '))->toBe('bionic garden');
});

// ── The gateway: a reference lands, an unknown one is refused with the argument named ───────────

it('runs erp.read_snapshot for a business named by its brand, and records the business it resolved to', function () {
    $outcome = app(ToolGateway::class)->execute(scopedRequest('erp.read_snapshot', ['business_slug' => 'Bionic Garden'], $this->owner));

    expect($outcome->ok())->toBeTrue();
    expect($outcome->output['business'])->toBe('bionic');
    expect(ToolCall::latest('id')->firstOrFail()->business_id)->toBe(Business::where('slug', 'bionic')->sole()->id);
});

it('refuses an unscoped call by naming the argument that would have scoped it and the values it takes', function () {
    $outcome = app(ToolGateway::class)->execute(scopedRequest('erp.read_snapshot', [], $this->owner));

    expect($outcome->status)->toBe(OutcomeStatus::Denied);
    expect($outcome->error)
        ->toContain('neither the call nor its arguments named one')
        ->toContain('Pass [business_slug] with one of:')
        ->toContain('bor-de-guna')
        ->toContain('bionic');
});

it('refuses a business HQ does not have, again naming the argument and the valid values', function () {
    $outcome = app(ToolGateway::class)->execute(scopedRequest('erp.read_snapshot', ['business_slug' => 'not-a-business'], $this->owner));

    expect($outcome->status)->toBe(OutcomeStatus::Denied);
    expect($outcome->error)->toContain('[not-a-business]')->toContain('[business_slug] takes one of:')->toContain('laladia');
});

it('still refuses a business the enabling human may not act on, after resolving it: resolution is not authorisation', function () {
    $laladia = Business::where('slug', 'laladia')->sole();
    UserBusinessScope::create(['user_id' => $this->owner->id, 'business_id' => $laladia->id]);
    Cache::flush();

    $outcome = app(ToolGateway::class)->execute(scopedRequest('erp.read_snapshot', ['business_slug' => 'Bionic Garden'], $this->owner));

    expect($outcome->status)->toBe(OutcomeStatus::Denied);
    expect($outcome->error)->toContain('not scoped to business');
});

it('resolves a store argument by its display name too, and refuses an unknown store naming the argument', function () {
    $resolver = app(ScopeResolver::class);
    $descriptor = app(ToolRegistry::class)->get('commerce.list_orders')->descriptor();

    $named = $resolver->resolve($descriptor, scopedRequest('commerce.list_orders', [], $this->owner), ['store_slug' => 'laladia.com']);
    expect($named->missing)->toBeFalse();
    expect($named->storeSlug)->toBe('laladia-com');
    expect($named->businessSlug)->toBe('laladia');

    $unknown = $resolver->resolve($descriptor, scopedRequest('commerce.list_orders', [], $this->owner), ['store_slug' => 'nowhere']);
    expect($unknown->missing)->toBeTrue();
    expect($unknown->problem)->toContain('[nowhere]')->toContain('[store_slug] takes one of:')->toContain('laladia-com');
});

// ── The agent's catalogue: required and enumerated ──────────────────────────────────────────────

it('marks the scope argument required and enumerates exactly the businesses the agent may name', function () {
    $catalogue = collect(app(AgentToolset::class)->catalogueFor($this->executive));

    $snapshot = $catalogue->firstWhere('tool', 'erp.read_snapshot');
    expect($snapshot)->not->toBeNull();
    expect($snapshot['arguments']['required'])->toContain('business_slug');
    expect($snapshot['arguments']['properties']['business_slug']['enum'])
        ->toBe(Business::where('is_active', true)->orderBy('id')->pluck('slug')->all());

    $report = $catalogue->firstWhere('tool', 'erp.run_report');
    expect($report['arguments']['required'])->toContain('business_slug')->toContain('report_key');

    // A store-scoped tool gets the same treatment on its own argument.
    $orders = $catalogue->firstWhere('tool', 'commerce.list_orders');
    expect($orders['arguments']['required'])->toContain('store_slug');
    expect($orders['arguments']['properties']['store_slug']['enum'])->toContain('laladia-com', 'bionic-garden');

    // A tool with no scope argument is untouched.
    $freshness = $catalogue->firstWhere('tool', 'facts.snapshot_freshness');
    expect($freshness['arguments'])->not->toHaveKey('required');
});

it('narrows the enumeration to the business the run was opened for, to the enabling human\'s scope, and to the tool\'s runtime switch', function () {
    $laladia = Business::where('slug', 'laladia')->sole();
    $bionic = Business::where('slug', 'bionic')->sole();
    $toolset = app(AgentToolset::class);

    $one = collect($toolset->catalogueFor($this->executive, $bionic->id))->firstWhere('tool', 'erp.read_snapshot');
    expect($one['arguments']['properties']['business_slug']['enum'])->toBe(['bionic']);

    UserBusinessScope::create(['user_id' => $this->owner->id, 'business_id' => $laladia->id]);
    Cache::flush();
    $scoped = collect($toolset->catalogueFor($this->executive->fresh()))->firstWhere('tool', 'erp.read_snapshot');
    expect($scoped['arguments']['properties']['business_slug']['enum'])->toBe(['laladia']);

    UserBusinessScope::query()->delete();
    ToolRegistration::create(['key' => 'erp.read_snapshot', 'enabled' => true, 'enabled_businesses' => [$bionic->id]]);
    Cache::flush();
    $switched = collect($toolset->catalogueFor($this->executive->fresh()))->firstWhere('tool', 'erp.read_snapshot');
    expect($switched['arguments']['properties']['business_slug']['enum'])->toBe(['bionic']);
});

it('does not change the tool\'s own schema: a screen or a job that names the business in context still passes validation', function () {
    $schema = app(ToolRegistry::class)->get('erp.read_snapshot')->inputSchema();

    expect($schema)->not->toHaveKey('required');
    expect($schema['properties']['business_slug'])->not->toHaveKey('enum');

    $business = Business::where('slug', 'bor-de-guna')->sole();
    $outcome = app(ToolGateway::class)->execute(scopedRequest('erp.read_snapshot', [], $this->owner, $business->id));
    expect($outcome->ok())->toBeTrue();
});

// ── The directory the model reads ───────────────────────────────────────────────────────────────

it('hands the model a directory of the businesses it may name, with the slug beside every other name', function () {
    $bundle = app(ContextAssembler::class)->open($this->executive, 'How is Bionic Garden doing?', null, []);
    $block = collect($bundle->blocks)->firstWhere('source', 'businesses:directory');

    expect($block)->not->toBeNull();
    $text = $block->content;
    expect($text)
        ->toContain('bionic: Bionic')
        ->toContain('bionic-garden (bionic.garden)')
        ->toContain('Bionic Garden (bionic-garden)')
        ->toContain('laladia: LaLaDia')
        ->toContain('bor-de-guna: Bor de Guna')
        ->toContain('takes the slug exactly as written here');

    // Provenance: HQ's own data about HQ's own businesses.
    expect($block->provenance->value)->toBe('system');
});

it('narrows the directory to the one business a single-business run is about', function () {
    $bionic = Business::where('slug', 'bionic')->sole();
    $bundle = app(ContextAssembler::class)->open($this->executive, 'How is Bionic Garden doing?', $bionic, []);
    $text = collect($bundle->blocks)->firstWhere('source', 'businesses:directory')->content;

    expect($text)->toContain('bionic: Bionic')->not->toContain('laladia: LaLaDia');
});
