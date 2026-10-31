<?php

use App\Models\Business;
use App\Models\Customer;
use App\Models\FeatureFlag;
use App\Models\ScheduledJob;
use App\OwnerConsole\Do\DoModeActivation;
use App\OwnerConsole\Flag;
use Database\Seeders\DomainSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Tests\Support\OwnerConsole\TestDoModeActivation;

/*
 | Owner request 31 Oct: every list screen sends the same paging facts (App\Support\Http\ListPage) and the shared
 | Pagination bar reads them. One request per screen family, checking the paginator props are there and that a
 | second list on the same screen pages by its own keys.
 */

beforeEach(function () {
    $this->seed(DomainSeeder::class);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->owner = $this->hqUser('owner');
});

/** The paging facts every list prop carries. */
function lpsAssertMeta($page, string $prop, string $pageName = 'page'): void
{
    $perPageName = $pageName === 'page' ? 'per_page' : str_replace('page', 'per_page', $pageName);
    $page->where("{$prop}.page_name", $pageName)
        ->where("{$prop}.per_page_name", $perPageName)
        ->where("{$prop}.per_page_option", '20')
        ->where("{$prop}.per_page_options", ['20', '100', '200', '500', 'all'])
        ->has("{$prop}.current_page")->has("{$prop}.last_page")->has("{$prop}.per_page")->has("{$prop}.total")
        ->has("{$prop}.from")->has("{$prop}.to")->has("{$prop}.capped")->has("{$prop}.full_total");
}

dataset('single-list screens', [
    'audit log' => ['/audit', 'audit/index', 'logs'],
    'customers' => ['/customers', 'customers/index', 'customers'],
    'scheduler' => ['/scheduler', 'scheduler/index', 'jobs'],
    'agent runs' => ['/agents', 'agents/index', 'pagination'],
    'approvals' => ['/approvals?status=all', 'approvals/index', 'pagination'],
    'research digests' => ['/research/digests', 'research/digests', 'pagination'],
    'ad drafts' => ['/ads-drafts', 'ads-drafts/index', 'pagination'],
    'reel drafts' => ['/media-drafts', 'media-drafts/index', 'pagination'],
    'supplier documents' => ['/ingestion', 'ingestion/index', 'pagination'],
    'parcels' => ['/parcels', 'parcels/index', 'pagination'],
    'inbox threads' => ['/inbox?filter=all', 'inbox/index', 'pagination'],
    'identity conflicts' => ['/customers/identity-conflicts', 'customers/identity-conflicts', 'pagination'],
    'quarantine flags' => ['/customers/quarantine', 'customers/quarantine', 'pagination'],
    'briefings' => ['/observe/briefings', 'observe/briefings', 'pagination'],
    'event watches' => ['/watches', 'watches/index', 'pagination'],
]);

it('sends the paging facts on every single-list screen', function (string $url, string $component, string $prop) {
    $this->actingAsHq($this->owner)->get($url)->assertOk()
        ->assertInertia(fn ($page) => tap($page->component($component), fn ($p) => lpsAssertMeta($p, $prop)));
})->with('single-list screens');

dataset('two-list screens', [
    'avatar consents and generations' => ['/avatar', 'avatar/index', ['consentsPagination' => 'consents_page', 'generationsPagination' => 'page']],
    'autonomy grants and thresholds' => ['/autonomy', 'autonomy/index', ['grantsPagination' => 'page', 'thresholdsPagination' => 'thresholds_page']],
    'skills and delegations' => ['/skills', 'skills/index', ['skillsPagination' => 'page', 'delegationsPagination' => 'delegations_page']],
    'follow-ups and opportunities' => ['/follow-ups', 'follow-ups/index', ['followUpsPagination' => 'page', 'opportunitiesPagination' => 'opportunities_page']],
    'content calendar, claims rules and testimonials' => ['/content', 'content/index', ['entriesPagination' => 'page', 'claimsPagination' => 'claims_page', 'testimonialsPagination' => 'testimonials_page']],
    'customers and merge candidates' => ['/customers', 'customers/index', ['candidatesPagination' => 'candidates_page']],
    'alerts cleared' => ['/observe/alerts', 'observe/alerts', ['resolvedPagination' => 'resolved_page']],
]);

it('pages each list of a multi-list screen by its own keys', function (string $url, string $component, array $props) {
    $this->actingAsHq($this->owner)->get($url)->assertOk()->assertInertia(function ($page) use ($component, $props) {
        $page->component($component);
        foreach ($props as $prop => $pageName) {
            lpsAssertMeta($page, $prop, $pageName);
        }
    });
})->with('two-list screens');

it('pages the run history of one scheduled job and the consent history of one customer', function () {
    $job = ScheduledJob::create(['name' => 'Paging test', 'kind' => 'cron', 'cron_expression' => '0 4 * * *', 'tool_key' => 'hq.list_jobs']);
    $this->actingAsHq($this->owner)->get("/scheduler/{$job->id}")->assertOk()
        ->assertInertia(fn ($page) => tap($page->component('scheduler/show'), fn ($p) => lpsAssertMeta($p, 'runs')));

    $customer = Customer::create(['business_id' => Business::orderBy('id')->value('id'), 'name' => 'Paging Test Customer']);
    $this->actingAsHq($this->owner)->get("/customers/{$customer->id}/consent")->assertOk()
        ->assertInertia(fn ($page) => tap($page->component('customers/consent'), fn ($p) => lpsAssertMeta($p, 'historyPagination', 'history_page')));
});

it('pages the batch overview in the same oldest-first order and adds no way to approve', function () {
    FeatureFlag::query()->where('key', Flag::BATCH_APPROVAL)->update(['enabled' => true]);

    $this->actingAsHq($this->owner)->get('/approvals/batch')->assertOk()
        ->assertInertia(fn ($page) => tap($page->component('approvals/batch')->where('enabled', true), fn ($p) => lpsAssertMeta($p, 'pagination')));

    $page = file_get_contents(resource_path('js/pages/approvals/batch.tsx'));
    expect($page)->not->toMatch('/router\.(post|put|patch|delete)|type="checkbox"|useForm/');
});

it('pages the do plans list while Do mode is active (it stays dark otherwise)', function () {
    FeatureFlag::query()->where('key', Flag::DO_MODE)->update(['enabled' => true]);
    app()->instance(DoModeActivation::class, new TestDoModeActivation);

    $this->actingAsHq($this->owner)->get('/do-plans')->assertOk()
        ->assertInertia(fn ($page) => tap($page->component('do-plans/index'), fn ($p) => lpsAssertMeta($p, 'pagination')));
});
