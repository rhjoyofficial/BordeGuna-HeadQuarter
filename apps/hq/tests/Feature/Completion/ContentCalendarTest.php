<?php

use App\Content\Calendar\CaptionDrafter;
use App\Content\Calendar\FakeCaptionDrafter;
use App\Content\ContentDigest;
use App\Content\ProductFact;
use App\Content\Publishing\FakePostPublisher;
use App\Content\Publishing\PublicationService;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\ContentCalendarEntry;
use App\Models\ContentClaimsRule;
use App\Models\ContentPublication;
use App\Models\PlatformAccount;
use App\Platform\PlatformRegistry;
use Database\Seeders\DomainSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Completion\M4;
use Tests\Support\Content\PhaseSix;

/*
 | docs/145-a (Phase 6): the content calendar, drafts from a person or a connected drafter, the owner's claims
 | rules table, the floor (no unproven health claim, no invented testimonial, CRPA 2009 s.44), human review,
 | the sandbox publisher, and business scope on every read and write. Real routes, real database triggers.
 */

beforeEach(function () {
    Http::preventStrayRequests();
    $this->seed(DomainSeeder::class);
    $this->owner = $this->hqUser('owner');
    $this->lld = Brand::where('code', 'LLD')->sole();
    $this->bnc = Brand::where('code', 'BNC')->sole();
    PhaseSix::brief($this->lld, $this->owner);
    PhaseSix::brief($this->bnc, $this->owner);
});

function ccPlan(Brand $brand, array $over = []): ContentCalendarEntry
{
    test()->actingAsHq(test()->owner)->post('/content/calendar', $over + [
        'brand_id' => $brand->id, 'planned_for' => now()->addDays(3)->toDateString(), 'platform' => 'facebook_page',
        'occasion' => 'hilsa_season', 'product_lines' => 'mustard_oil', 'idea' => 'Winter mustard oil post',
    ])->assertSessionHasNoErrors();

    return ContentCalendarEntry::query()->latest('id')->firstOrFail();
}

it('registers every content route behind auth, both 2FA gates and its own permission', function () {
    $expected = [
        'content.index' => 'permission:hq.content.view',
        'content.plan' => 'permission:hq.content.plan',
        'content.draft' => 'permission:hq.content.plan',
        'content.ai-draft' => 'permission:hq.content.plan',
        'content.review' => 'permission:hq.content.review',
        'content.skip' => 'permission:hq.content.plan',
        'content.claims.store' => 'permission:hq.content.claims.manage',
        'content.claims.retire' => 'permission:hq.content.claims.manage',
        'research.digests' => 'permission:hq.research.view',
        'research.digests.store' => 'permission:hq.research.digest',
    ];
    foreach ($expected as $name => $permission) {
        expect(Route::getRoutes()->getByName($name)->gatherMiddleware())->toContain('auth', '2fa.enabled', '2fa.verified', $permission);
    }
});

it('shows the calendar in the new interface to the roles that hold the view permission, and nobody else', function () {
    $this->get('/content')->assertRedirect();
    foreach (['owner' => true, 'operator' => true, 'brand_manager' => true, 'support_agent' => false, 'finance_viewer' => false] as $role => $allowed) {
        $r = $this->actingAsHq($this->hqUser($role))->get('/content');
        $allowed ? $r->assertOk()->assertInertia(fn (Assert $p) => $p->component('content/index')->has('floor', 2)) : $r->assertForbidden();
    }
});

it('plans a post, and a person\'s caption becomes a checked draft that a reviewer approves without publishing anything', function () {
    M4::on('content.drafting');
    $entry = ccPlan($this->lld);

    $this->actingAsHq($this->owner)->post("/content/calendar/{$entry->uuid}/draft", ['text' => 'সরিষার তেল, শীতের রান্নায়।'])->assertSessionHasNoErrors();
    $entry->refresh();
    expect($entry->status)->toBe('drafted')->and($entry->draft->status)->toBe('checked')->and($entry->draft->text_provenance)->toBe('business');

    $this->actingAsHq($this->owner)->post("/content/calendar/{$entry->uuid}/review", ['verdict' => 'approved'])->assertSessionHasNoErrors();
    expect($entry->fresh()->status)->toBe('approved')->and($entry->fresh()->draft->status)->toBe('approved')
        ->and(ContentPublication::query()->count())->toBe(0);
});

it('fails an unproven health claim and an invented testimonial by the floor, whatever the owner\'s policy says', function (string $text, string $rule) {
    M4::on('content.drafting');
    $entry = ccPlan($this->lld, ['product_lines' => 'honey']);
    // The owner covered `honey` with a rule that forbids something else entirely: the floor still applies.
    ContentClaimsRule::query()->create(['business_id' => $this->lld->business_id, 'product_line' => 'honey', 'kind' => 'forbidden', 'phrases' => ['miracle food'], 'reason' => 'owner', 'status' => 'active']);

    $this->actingAsHq($this->owner)->post("/content/calendar/{$entry->uuid}/draft", ['text' => $text])->assertSessionHasNoErrors();
    $draft = $entry->fresh()->draft;

    expect($draft->status)->toBe('failed_checks')
        ->and(collect($draft->checks['claims_policy']['violations'])->pluck('rule')->all())->toContain($rule);
    // A failed draft cannot be approved.
    $this->actingAsHq($this->owner)->post("/content/calendar/{$entry->uuid}/review", ['verdict' => 'approved'])->assertSessionHasErrors('form');
})->with([
    'English cure' => ['This honey cures diabetes.', 'floor.health_claim'],
    'Bangla medicine' => ['এই মধু ওষুধ হিসেবে কাজ করে।', 'floor.health_claim'],
    'English testimonial' => ['Our customers say it is the best honey.', 'floor.testimonial'],
    'Bangla testimonial' => ['গ্রাহকের মতামত: অসাধারণ মধু।', 'floor.testimonial'],
]);

it('fails a draft about a product line no rule covers, and passes it once the owner writes a rule for that line', function () {
    M4::on('content.drafting');
    $entry = ccPlan($this->lld, ['product_lines' => 'ghee']);
    $this->actingAsHq($this->owner)->post("/content/calendar/{$entry->uuid}/draft", ['text' => 'খাঁটি ঘি।'])->assertSessionHasNoErrors();
    expect($entry->fresh()->draft->checks['claims_policy']['uncovered_product_lines'])->toBe(['ghee']);

    $this->actingAsHq($this->owner)->post('/content/claims-rules', [
        'business_id' => $this->lld->business_id, 'product_line' => 'ghee', 'kind' => 'forbidden', 'phrases' => "100% pure\n১০০% খাঁটি", 'reason' => 'needs a lab certificate',
        'legal_ref' => 'CRPA 2009 s.44',
    ])->assertSessionHasNoErrors();

    $this->actingAsHq($this->owner)->post("/content/calendar/{$entry->uuid}/draft", ['text' => 'খাঁটি ঘি।'])->assertSessionHasNoErrors();
    $passing = $entry->fresh()->draft;
    expect($passing->status)->toBe('checked');

    $this->actingAsHq($this->owner)->post("/content/calendar/{$entry->uuid}/draft", ['text' => 'ঘি 100% pure!'])->assertSessionHasNoErrors();
    expect($entry->fresh()->draft->status)->toBe('failed_checks');
});

it('keeps the owner\'s rules: an allowed claim needs its proof, a rule is never edited or deleted, retiring is audited', function () {
    $this->actingAsHq($this->owner)->post('/content/claims-rules', [
        'business_id' => $this->lld->business_id, 'product_line' => 'honey', 'kind' => 'allowed', 'phrases' => 'lab tested', 'reason' => 'we have a report',
    ])->assertSessionHasErrors('form');

    $this->actingAsHq($this->owner)->post('/content/claims-rules', [
        'business_id' => $this->lld->business_id, 'product_line' => 'honey', 'kind' => 'forbidden', 'phrases' => 'raw honey cures', 'reason' => 'no proof',
    ])->assertSessionHasNoErrors();
    $rule = ContentClaimsRule::query()->latest('id')->firstOrFail();

    // Each attempt in its own savepoint, as a real caller's transaction would be.
    expect(fn () => DB::transaction(fn () => ContentClaimsRule::query()->whereKey($rule->id)->update(['phrases' => json_encode(['something else'])])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => ContentClaimsRule::query()->whereKey($rule->id)->delete()))->toThrow(QueryException::class);

    $this->actingAsHq($this->owner)->post("/content/claims-rules/{$rule->uuid}/retire", ['reason' => 'superseded'])->assertSessionHasNoErrors();
    expect($rule->fresh()->status)->toBe('retired')
        ->and(AuditLog::query()->where('action', 'content.claims_rule.retired')->count())->toBe(1);

    // The brand manager plans and drafts but never writes the owner's rules or reviews.
    $manager = $this->hqUser('brand_manager');
    $this->actingAsHq($manager)->post('/content/claims-rules', ['business_id' => $this->lld->business_id])->assertForbidden();
});

it('drafts with AI only when the flag is on, a drafter is connected and AI is not paused; the words are labelled and checked', function () {
    M4::on('content.drafting');
    $entry = ccPlan($this->lld);

    $this->actingAsHq($this->owner)->post("/content/calendar/{$entry->uuid}/ai-draft")->assertSessionHasErrors(['form' => 'AI caption drafting is off (flag content.ai_drafting). Write the caption yourself.']);
    M4::on('content.ai_drafting');
    $this->actingAsHq($this->owner)->post("/content/calendar/{$entry->uuid}/ai-draft")->assertSessionHasErrors('form');
    expect($entry->fresh()->status)->toBe('planned');

    $fake = new FakeCaptionDrafter('সরিষার তেল সব রোগ সারায়।');
    app()->instance(CaptionDrafter::class, $fake);
    M4::pause();
    $this->actingAsHq($this->owner)->post("/content/calendar/{$entry->uuid}/ai-draft")->assertSessionHasErrors('form');
    expect($fake->briefs)->toBe([]);

    M4::pause(false);
    $this->actingAsHq($this->owner)->post("/content/calendar/{$entry->uuid}/ai-draft")->assertSessionHasNoErrors();
    $draft = $entry->fresh()->draft;
    expect($draft->text_provenance)->toBe('generated')->and($draft->status)->toBe('failed_checks')
        ->and($fake->briefs[0]->forbiddenPhrases)->toContain('ওষুধ')
        ->and($fake->briefs[0]->brand)->toBe($this->lld->name);
});

it('never shows or touches another business\'s calendar to a person limited to one business', function () {
    M4::on('content.drafting');
    $theirs = ccPlan($this->bnc);
    $mine = ccPlan($this->lld);
    $limited = M4::limit($this->hqUser('operator'), 'laladia');

    $this->actingAsHq($limited)->get('/content')->assertOk()
        ->assertInertia(fn (Assert $p) => $p->has('entries', 1)->where('entries.0.uuid', $mine->uuid));
    foreach (['draft' => ['text' => 'x'], 'skip' => ['reason' => 'x'], 'review' => ['verdict' => 'rejected'], 'ai-draft' => []] as $act => $body) {
        $this->actingAsHq($limited)->post("/content/calendar/{$theirs->uuid}/{$act}", $body)->assertNotFound();
    }
    $this->actingAsHq($limited)->post('/content/calendar', ['brand_id' => $this->bnc->id, 'planned_for' => now()->toDateString(), 'platform' => 'instagram', 'product_lines' => 'honey', 'idea' => 'x'])->assertNotFound();
    $this->actingAsHq($limited)->post('/content/claims-rules', ['business_id' => $this->bnc->business_id, 'product_line' => 'honey', 'kind' => 'forbidden', 'phrases' => 'x', 'reason' => 'x'])->assertNotFound();
    expect($theirs->fresh()->status)->toBe('planned');
});

it('publishes through the sandbox publisher only after approval, the digest and a read-back; the app registers no publisher', function () {
    expect(app(PlatformRegistry::class)->platforms())->toBe([]);

    M4::on('content.drafting', 'publishing.write');
    $entry = ccPlan($this->lld);
    $this->actingAsHq($this->owner)->post("/content/calendar/{$entry->uuid}/draft", ['text' => 'সরিষার তেল, দাম ৪৫০ টাকা।'])->assertSessionHasNoErrors();
    $draft = $entry->fresh()->draft;
    // The figure has no ERP fact behind it in a calendar draft, so the checks fail and nobody can approve it.
    expect($draft->status)->toBe('failed_checks');

    $this->actingAsHq($this->owner)->post("/content/calendar/{$entry->uuid}/draft", ['text' => 'সরিষার তেল, শীতের রান্নায়।'])->assertSessionHasNoErrors();
    $this->actingAsHq($this->owner)->post("/content/calendar/{$entry->uuid}/review", ['verdict' => 'approved'])->assertSessionHasNoErrors();
    $draft = $entry->fresh()->draft;

    $account = PlatformAccount::create(['brand_id' => $this->lld->id, 'platform' => 'facebook_page', 'account_ref' => 'SANDBOX-PAGE', 'name' => 'LaLaDia',
        'capabilities' => ['publish_post'], 'approval_status' => 'approved', 'is_active' => true]);
    $publisher = new FakePostPublisher;
    $registry = new PlatformRegistry;
    $registry->register($publisher);
    app()->instance(PlatformRegistry::class, $registry);

    $pub = app(PublicationService::class)->publish($draft, $account, ContentDigest::forPublication($draft, $account->id), 1, [ProductFact::fromArray(PhaseSix::erpPrice('MO-1L', '450'))]);
    expect($pub->status)->toBe('published')->and($publisher->published()[0]->text)->toBe('সরিষার তেল, শীতের রান্নায়।')
        ->and($pub->platform_post_id)->toStartWith('sandbox_');
});
