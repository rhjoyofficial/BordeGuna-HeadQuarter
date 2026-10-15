<?php

use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Business;
use App\Models\UserBusinessScope;
use App\Playbooks\PlaybookKind;
use App\Playbooks\PlaybookLoader;
use App\Playbooks\Playbooks;
use Database\Seeders\DomainSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;

/*
 | docs/120 section B, S6 items 22-25 (docs/125): reviewer independence with a visible single-person bootstrap
 | rule; legacy columns left in place; business-wide and brand playbooks both loaded, labelled, the brand one
 | refining and never standing without its base. Item 26 (Support runs) is in SupportWakeUpTest; item 23 is held
 | by PlaybookStructureTest (exactly one reader outside the namespace).
 */

beforeEach(function () {
    $this->seed(DomainSeeder::class);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->owner = $this->hqUser('owner');
    $this->laladia = Business::where('slug', 'laladia')->sole();
    $this->lld = Brand::where('code', 'LLD')->sole();
    $this->store = app(Playbooks::class);
});

function w4Submitted(Playbooks $store, $by, Business $business, ?Brand $brand = null, PlaybookKind $kind = PlaybookKind::ProductClaims, string $body = 'Say natural.')
{
    $v = $store->draft($by, $business, $brand, $kind, ['title' => 'Claims', 'body' => $body]);

    return $store->submit($by, $v);
}

it('item 22: refuses self-approval while a second eligible reviewer exists, and the other person may approve', function () {
    $second = $this->hqUser('operator');
    $v = w4Submitted($this->store, $this->owner, $this->laladia);

    expect(fn () => $this->store->review($this->owner, $v, true, 'mine, fine'))->toThrow(InvalidArgumentException::class, 'Reviewer independence');
    expect($v->fresh()->status)->toBe('in_review');

    // Sending one's own version back is never refused on this ground.
    $back = $this->store->review($this->owner, $v, false, 'not ready');
    expect($back->status)->toBe('draft');
    $this->store->submit($this->owner, $back);

    $reviewed = $this->store->review($second, $back, true, 'read it');
    expect($reviewed->status)->toBe('reviewed')->and($reviewed->reviewed_by)->toBe($second->id)
        ->and(Playbooks::reviewedByDrafter($reviewed))->toBeFalse()
        ->and(AuditLog::where('action', 'playbook.self_review_bootstrap')->count())->toBe(0);
});

it('item 22: allows self-review only under the single-person bootstrap rule, recorded and shown on the page', function () {
    // A disabled holder and a holder without access to this business are not eligible reviewers.
    $disabled = $this->hqUser('operator');
    $disabled->forceFill(['is_active' => false])->save();
    $elsewhere = $this->hqUser('operator');
    UserBusinessScope::create(['user_id' => $elsewhere->id, 'business_id' => Business::where('slug', 'bionic')->value('id'), 'access' => 'full']);

    $v = w4Submitted($this->store, $this->owner, $this->laladia);
    expect($this->store->otherEligibleReviewers($v, $this->owner))->toBe(0);

    $reviewed = $this->store->review($this->owner, $v, true, 'only reviewer');
    $audit = AuditLog::where('action', 'playbook.self_review_bootstrap')->sole();
    expect($reviewed->status)->toBe('reviewed')
        ->and(Playbooks::reviewedByDrafter($reviewed))->toBeTrue()
        ->and($audit->after['rule'])->toBe(Playbooks::BOOTSTRAP_RULE)
        ->and($audit->after['other_eligible_reviewers'])->toBe(0)
        ->and($audit->actor_id)->toBe($this->owner->id);

    $this->actingAsHq($this->owner)->get("/playbooks/{$reviewed->uuid}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('version.reviewed_by_drafter', true));

    // The moment a second eligible reviewer exists, the same act is refused.
    $this->hqUser('operator');
    $next = w4Submitted($this->store, $this->owner, $this->laladia, null, PlaybookKind::Escalation, 'Complaints go to a person.');
    expect(fn () => $this->store->review($this->owner, $next, true, 'again'))->toThrow(InvalidArgumentException::class, 'Reviewer independence');
});

it('item 24: leaves the legacy guidance columns in place', function () {
    expect(Schema::hasColumn('content_briefs', 'claims_policy'))->toBeTrue()
        ->and(Schema::hasColumn('brands', 'guidelines'))->toBeTrue();
    foreach (glob(app_path('Playbooks/*.php')) as $file) {
        expect((string) file_get_contents($file))->not->toContain('claims_policy')->not->toContain('guidelines');
    }
});

it('item 25: loads the business-wide and the brand version of a kind together, labelled, the brand one refining the other', function () {
    $live = function (?Brand $brand, string $body) {
        $v = w4Submitted($this->store, $this->owner, $this->laladia, $brand, PlaybookKind::ProductClaims, $body);
        $this->store->review($this->owner, $v, true, 'ok');

        return $this->store->activate($this->owner, $v, 'go');
    };
    $wide = $live(null, 'Business-wide: never say organic.');
    $brand = $live($this->lld, 'LaLaDia: say lab-tested for the dryfish.');

    $selection = app(PlaybookLoader::class)->load($this->laladia->id, $this->lld->id);
    $loaded = collect($selection->record['loaded'])->keyBy('id');
    $text = collect($selection->blocks)->map(fn ($b) => $b->content)->implode("\n");

    expect($loaded->keys()->all())->toBe([$wide->id, $brand->id])
        ->and($loaded[$brand->id]['refines'])->toBe($wide->id)
        ->and($loaded[$wide->id])->not->toHaveKey('refines')
        ->and($selection->record['overlaps'])->toBe([['kind' => 'product_claims', 'business_wide' => $wide->id, 'brand' => $brand->id, 'precedence' => 'brand_refines_business_wide']])
        ->and($text)->toContain('(business-wide)')
        ->toContain('Refines the business-wide Product claims playbook, version 1, for this brand only')
        ->toContain('never widen a permission or override a policy')
        ->toContain('say that the playbooks conflict and leave that point to a person');

    $this->actingAsHq($this->owner)->get("/playbooks/{$brand->uuid}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('version.refines_business_version', 1));
});

it('item 25: never loads a brand refinement without its business-wide base, and records why', function () {
    config()->set('hq.playbooks.max_chars_per_run', 60);
    $v = w4Submitted($this->store, $this->owner, $this->laladia, null, PlaybookKind::ProductClaims, str_repeat('w', 80));
    $this->store->review($this->owner, $v, true, 'ok');
    $wide = $this->store->activate($this->owner, $v, 'go');
    $b = w4Submitted($this->store, $this->owner, $this->laladia, $this->lld, PlaybookKind::ProductClaims, 'short brand rule');
    $this->store->review($this->owner, $b, true, 'ok');
    $brand = $this->store->activate($this->owner, $b, 'go');

    $selection = app(PlaybookLoader::class)->load($this->laladia->id, $this->lld->id);
    $skipped = collect($selection->record['skipped'])->keyBy('id');

    expect($selection->record['loaded'])->toBe([])
        ->and($skipped[$wide->id]['reason'])->toBe('budget')
        ->and($skipped[$brand->id]['reason'])->toBe('base_not_loaded')
        ->and($skipped[$brand->id]['refines'])->toBe($wide->id)
        ->and($selection->blocks)->toBe([]);
});
