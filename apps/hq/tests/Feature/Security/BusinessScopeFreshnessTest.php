<?php

use App\Execution\ExecutionContextResolver;
use App\Execution\Requester;
use App\Gateway\CallerKind;
use App\Gateway\Identity\ActingIdentity;
use App\Gateway\OutcomeClass;
use App\Gateway\OutcomeStatus;
use App\Gateway\ToolGateway;
use App\Gateway\ToolRequest;
use App\Models\Agent;
use App\Models\Business;
use App\Models\User;
use App\Support\Authorization\BusinessScope;
use Database\Seeders\AgentSeeder;
use Database\Seeders\DomainSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Support\Execution\Contexts;

/*
 | docs/81 P0-2: business scope is never stale.
 |
 | `BusinessScope` used to keep a person's rows for 60 seconds in the shared cache. Nothing in HQ
 | writes `user_business_scopes` (rows are made by hand), so every change here is made the way it
 | is made in life: straight into the table, with no model event and nobody to call `forget()`.
 |
 | What is proved: a narrowing reaches the gateway at once, in this process and in one that shares
 | the cache store; a widening is seen only once its row exists; the resolver and Ask HQ read the
 | rows as they are; and an agent run that was resolved before the narrowing is stopped at its next
 | call, because the gateway's re-read now looks at business scope too.
 */

beforeEach(function () {
    Cache::flush();
    $this->seed(DomainSeeder::class);

    $this->owner = $this->hqUser('owner');
    $this->seed(AgentSeeder::class);
    Agent::query()->where('key', 'executive')->update(['status' => 'active', 'enabling_user_id' => $this->owner->id]);
    $this->executive = Agent::where('key', 'executive')->sole();
    $this->laladia = Business::where('slug', 'laladia')->sole();
    $this->bionic = Business::where('slug', 'bionic')->sole();
});

/** A scope row written around the application: no model, no event, no cache call. */
function grantScope(User $user, Business $business): void
{
    DB::table('user_business_scopes')->insert([
        'user_id' => $user->id, 'business_id' => $business->id, 'access' => 'read', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function revokeScope(User $user, Business $business): void
{
    DB::table('user_business_scopes')->where('user_id', $user->id)->where('business_id', $business->id)->delete();
}

function humanReads(User $user, string $businessSlug)
{
    return app(ToolGateway::class)->execute(ToolRequest::forOperator($user, 'erp.read_snapshot', ['business_slug' => $businessSlug]));
}

function agentReads(ActingIdentity $identity, string $businessSlug)
{
    return app(ToolGateway::class)->execute(new ToolRequest(
        toolKey: 'erp.read_snapshot', args: ['business_slug' => $businessSlug], caller: CallerKind::Agent,
        identity: $identity, correlationId: (string) Str::uuid(),
    ));
}

/** The default store becomes one that outlives the process, as Redis does in production. */
function persistentCacheStore(): string
{
    $path = storage_path('framework/testing/scope-cache-'.Str::random(8));
    File::ensureDirectoryExists($path);
    config(['cache.stores.scope_file' => ['driver' => 'file', 'path' => $path], 'cache.default' => 'scope_file']);
    Cache::setDefaultDriver('scope_file');

    return $path;
}

it('narrows a group-wide person at the gateway the moment their first row exists', function () {
    $person = $this->hqUser('operator');
    expect(humanReads($person, 'bionic')->ok())->toBeTrue();

    grantScope($person, $this->laladia);

    $after = humanReads($person, 'bionic');
    expect($after->status)->toBe(OutcomeStatus::Denied)
        ->and($after->class)->toBe(OutcomeClass::ScopeDenied)
        ->and(humanReads($person, 'laladia')->ok())->toBeTrue()
        ->and(app(BusinessScope::class)->all($person))->toBe([$this->laladia->id]);
});

it('takes a removed business away at the gateway at once', function () {
    $person = $this->hqUser('operator');
    grantScope($person, $this->laladia);
    grantScope($person, $this->bionic);
    expect(humanReads($person, 'bionic')->ok())->toBeTrue();

    revokeScope($person, $this->bionic);

    expect(humanReads($person, 'bionic')->status)->toBe(OutcomeStatus::Denied)
        ->and(app(BusinessScope::class)->allows($person, $this->bionic->id))->toBeFalse();
});

it('widens only once the row exists: a rolled-back grant gives nothing, a committed one gives its business', function () {
    $person = $this->hqUser('operator');
    grantScope($person, $this->laladia);
    expect(humanReads($person, 'bionic')->status)->toBe(OutcomeStatus::Denied);

    DB::beginTransaction();
    grantScope($person, $this->bionic);
    DB::rollBack();
    expect(humanReads($person, 'bionic')->status)->toBe(OutcomeStatus::Denied);

    grantScope($person, $this->bionic);
    expect(humanReads($person, 'bionic')->ok())->toBeTrue();
});

it('ignores whatever another process left in a shared cache store', function (Closure $planted, string $why) {
    $path = persistentCacheStore();
    try {
        $person = $this->hqUser('operator');
        grantScope($person, $this->laladia);

        // What a second container would have written under the old code, 1 second ago.
        Cache::store('scope_file')->put("hq:business_scope:{$person->id}", $planted($this), 60);

        // A new process: nothing resolved is reused.
        app()->forgetInstance(ToolGateway::class);
        app()->forgetInstance(BusinessScope::class);

        expect((new BusinessScope)->all($person))->toBe([$this->laladia->id], $why)
            ->and(humanReads($person, 'bionic')->status)->toBe(OutcomeStatus::Denied, $why)
            ->and(humanReads($person, 'laladia')->ok())->toBeTrue();
    } finally {
        Cache::store('scope_file')->flush();
        File::deleteDirectory($path);
    }
})->with([
    'cached as group-wide before the first row was made' => [fn () => [], 'a cached empty list must not mean group-wide'],
    'cached with a business that was since removed' => [fn ($t) => [$t->laladia->id, $t->bionic->id], 'a cached wider list must not be believed'],
]);

it('keeps no business-scope cache at all', function () {
    $source = (string) file_get_contents(app_path('Support/Authorization/BusinessScope.php'));
    expect($source)->not->toContain('Cache::')->not->toContain('function forget');

    foreach (File::allFiles(app_path()) as $file) {
        expect((string) file_get_contents($file->getPathname()))->not->toContain('hq:business_scope', $file->getFilename());
    }
});

it('resolves a run, and previews Ask HQ, from the rows as they are now', function () {
    $asker = $this->hqUser('operator');
    $resolver = app(ExecutionContextResolver::class);
    $requester = Requester::human($asker, 'test');

    expect($resolver->preview($this->executive, $requester, 'operator')->businessIds)->toBeNull();

    grantScope($asker, $this->laladia);
    expect($resolver->preview($this->executive, $requester, 'operator')->businessIds)->toBe([$this->laladia->id])
        ->and($resolver->resolve($this->executive, $requester, 'test')->businessIds)->toBe([$this->laladia->id]);

    grantScope($asker, $this->bionic);
    expect($resolver->preview($this->executive, $requester, 'operator')->businessIds)->toBe([$this->laladia->id, $this->bionic->id]);

    revokeScope($asker, $this->bionic);
    expect($resolver->resolve($this->executive, $requester, 'test')->businessIds)->toBe([$this->laladia->id]);
});

it('stops an agent run at its next call when a person it rests on was narrowed after it was resolved', function (Closure $start, Closure $narrow) {
    $asker = $this->hqUser('operator');
    $start($asker, $this);
    $identity = ActingIdentity::forExecution(Contexts::for($this->executive, requester: $asker));
    expect(agentReads($identity, 'bionic')->ok())->toBeTrue();

    $narrow($asker, $this);

    // The business just taken away, and one the person still has: the run is over either way.
    foreach (['bionic', 'laladia'] as $slug) {
        $after = agentReads($identity, $slug);
        expect($after->status)->toBe(OutcomeStatus::Denied)
            ->and($after->error)->toContain('narrowed after this run was resolved');
    }
    expect($identity->execution->allowsBusiness($this->bionic->id))->toBeTrue('the context itself is immutable; the re-read is what stops it');

    // The next run is resolved from the rows as they are.
    $next = ActingIdentity::forExecution(Contexts::for($this->executive->fresh(), requester: $asker));
    expect(agentReads($next, 'laladia')->ok())->toBeTrue()
        ->and(agentReads($next, 'bionic')->status)->toBe(OutcomeStatus::Denied);
})->with([
    'the requester, group-wide when it began, given a first row' => [
        fn () => null,
        fn ($asker, $t) => grantScope($asker, $t->laladia),
    ],
    'the requester loses one of two businesses' => [
        function ($asker, $t) {
            grantScope($asker, $t->laladia);
            grantScope($asker, $t->bionic);
        },
        fn ($asker, $t) => revokeScope($asker, $t->bionic),
    ],
    'the enabling user, group-wide when it began, given a first row' => [
        fn () => null,
        fn ($asker, $t) => grantScope($t->owner, $t->laladia),
    ],
]);

it('never widens a run that is under way: a row added mid-run shows at the next resolve', function () {
    $asker = $this->hqUser('operator');
    grantScope($asker, $this->laladia);
    $identity = ActingIdentity::forExecution(Contexts::for($this->executive, requester: $asker));
    expect(agentReads($identity, 'bionic')->status)->toBe(OutcomeStatus::Denied);

    grantScope($asker, $this->bionic);

    $same = agentReads($identity, 'bionic');
    expect($same->status)->toBe(OutcomeStatus::Denied)
        ->and($same->error)->toContain('not scoped to business')
        ->and(agentReads($identity, 'laladia')->ok())->toBeTrue('a widening does not end the run either');

    $next = ActingIdentity::forExecution(Contexts::for($this->executive, requester: $asker));
    expect(agentReads($next, 'bionic')->ok())->toBeTrue();
});
