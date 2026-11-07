<?php

use App\Models\Business;
use App\Models\CourierParcel;
use App\Models\UserBusinessScope;
use App\Support\Http\ListPage;
use Database\Seeders\DomainSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Str;

/*
 | Owner request 31 Oct: every HQ list screen pages the same way. App\Support\Http\ListPage reads `per_page`
 | (20, 100, 200, 500 or all; anything else is 20), keeps the screen's filters in every link, caps "All" at
 | hq.lists.all_ceiling with a notice, and pages a query that is already scoped to the person's businesses.
 */

beforeEach(function () {
    $this->seed(DomainSeeder::class);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->mine = Business::orderBy('id')->firstOrFail();
    $this->theirs = Business::where('id', '!=', $this->mine->id)->orderBy('id')->firstOrFail();
});

function lpParcels(Business $business, int $count, string $prefix): void
{
    for ($i = 1; $i <= $count; $i++) {
        CourierParcel::create(['uuid' => (string) Str::uuid(), 'business_id' => $business->id, 'provider' => 'pathao', 'consignment_ref' => "{$prefix}{$i}", 'status' => 'in_transit']);
    }
}

/** Runs the helper against a request with this query string, as a controller would. */
function lpPage(array $query, string $pageName = 'page')
{
    $request = Request::create('/parcels', 'GET', $query);
    app()->instance('request', $request);
    Paginator::currentPathResolver(fn () => $request->url());
    Paginator::currentPageResolver(fn ($name = 'page') => $request->query($name, 1));

    return ListPage::paginate(CourierParcel::query()->orderBy('consignment_ref'), $request, $pageName);
}

it('offers 20, 100, 200, 500 and All, and falls back to 20 for anything else', function () {
    foreach (['20' => '20', '100' => '100', '200' => '200', '500' => '500', 'all' => 'all', 'ALL' => 'all', '50' => '20', '7' => '20', '-1' => '20', 'abc' => '20', '' => '20'] as $given => $expected) {
        expect(ListPage::option(Request::create('/x', 'GET', ['per_page' => $given])))->toBe($expected, "per_page={$given}");
    }
    expect(ListPage::option(Request::create('/x', 'GET', ['per_page' => ['100']])))->toBe('20')
        ->and(ListPage::option(Request::create('/x')))->toBe('20');
});

it('pages at 20 by default and at the chosen size, with the meta the bar reads', function () {
    lpParcels($this->mine, 45, 'P');

    $first = lpPage([]);
    expect($first->count())->toBe(20)->and($first->total())->toBe(45)->and($first->lastPage())->toBe(3);
    $meta = $first->meta();
    expect($meta)->toMatchArray(['current_page' => 1, 'last_page' => 3, 'per_page' => 20, 'total' => 45, 'from' => 1, 'to' => 20,
        'page_name' => 'page', 'per_page_name' => 'per_page', 'per_page_option' => '20', 'full_total' => 45, 'capped' => false])
        ->and($meta)->not->toHaveKey('data');

    $third = lpPage(['page' => 3]);
    expect($third->count())->toBe(5)->and($third->firstItem())->toBe(41);

    $big = lpPage(['per_page' => '100']);
    expect($big->count())->toBe(45)->and($big->lastPage())->toBe(1)->and($big->meta()['per_page_option'])->toBe('100');

    // A page past the end shows the last page rather than an empty list.
    expect(lpPage(['page' => 99])->currentPage())->toBe(3);
});

it('keeps the filters in every page link', function () {
    lpParcels($this->mine, 25, 'P');

    $page = lpPage(['status' => 'in_transit', 'per_page' => '20', 'page' => 1]);
    $next = $page->nextPageUrl();

    expect($next)->toContain('status=in_transit')->toContain('per_page=20')->toContain('page=2');
});

it('caps All at the configured ceiling and says it was capped', function () {
    config(['hq.lists.all_ceiling' => 30]);
    lpParcels($this->mine, 35, 'P');

    $all = lpPage(['per_page' => 'all']);
    expect($all->count())->toBe(30)
        ->and($all->capped())->toBeTrue()
        ->and($all->meta())->toMatchArray(['per_page_option' => 'all', 'total' => 30, 'full_total' => 35, 'capped' => true, 'last_page' => 1, 'ceiling' => 30]);

    config(['hq.lists.all_ceiling' => 5000]);
    $uncapped = lpPage(['per_page' => 'all']);
    expect($uncapped->count())->toBe(35)->and($uncapped->capped())->toBeFalse()->and($uncapped->lastPage())->toBe(1);
    expect(config('hq.lists.all_ceiling'))->toBe(5000);
});

it('pairs a second list on one screen with its own keys', function () {
    lpParcels($this->mine, 25, 'P');

    $page = lpPage(['consents_page' => 2, 'page' => 1], 'consents_page');
    expect($page->currentPage())->toBe(2)->and($page->meta())->toMatchArray(['page_name' => 'consents_page', 'per_page_name' => 'consents_per_page']);
    expect(ListPage::perPageNameFor('page'))->toBe('per_page')->and(ListPage::perPageNameFor('history_page'))->toBe('history_per_page');
});

it('keeps the order stable with the id as a tiebreaker, so no row shows on two pages', function () {
    for ($i = 1; $i <= 30; $i++) {
        CourierParcel::create(['uuid' => (string) Str::uuid(), 'business_id' => $this->mine->id, 'provider' => 'pathao', 'consignment_ref' => "SAME{$i}", 'status' => 'delivered']);
    }
    $request = Request::create('/parcels', 'GET', []);
    $seen = [];
    foreach ([1, 2] as $n) {
        Paginator::currentPageResolver(fn () => $n);
        $page = ListPage::paginate(CourierParcel::query()->orderBy('status'), $request);
        $seen = array_merge($seen, $page->getCollection()->pluck('id')->all());
    }
    expect($seen)->toHaveCount(30)->and(array_unique($seen))->toHaveCount(30);
});

it("never counts or pages another business's rows for a restricted person", function () {
    lpParcels($this->mine, 25, 'M');
    lpParcels($this->theirs, 40, 'T');
    $restricted = $this->hqUser('operator');
    UserBusinessScope::create(['user_id' => $restricted->id, 'business_id' => $this->mine->id]);

    $this->actingAsHq($restricted)->get('/parcels?page=2')->assertOk()->assertInertia(fn ($page) => $page->component('parcels/index')
        ->has('parcels', 5)
        ->where('pagination.total', 25)->where('pagination.full_total', 25)->where('pagination.last_page', 2)->where('pagination.current_page', 2)
        ->where('parcels', fn ($rows) => collect($rows)->every(fn ($r) => str_starts_with($r['consignment_ref'], 'M'))));

    $this->actingAsHq($restricted)->get('/parcels?per_page=all')->assertOk()->assertInertia(fn ($page) => $page
        ->has('parcels', 25)->where('pagination.total', 25)->where('pagination.per_page_option', 'all'));

    $owner = $this->hqUser('owner');
    $this->actingAsHq($owner)->get('/parcels?per_page=100')->assertOk()->assertInertia(fn ($page) => $page
        ->has('parcels', 65)->where('pagination.total', 65));
});
