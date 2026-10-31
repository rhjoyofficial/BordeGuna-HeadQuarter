<?php

use App\Content\Calendar\ContentCalendar;
use App\Content\ContentDigest;
use App\Content\ContentDraftService;
use App\Content\ProductFact;
use App\Content\Publishing\FakePostPublisher;
use App\Content\Publishing\PublicationService;
use App\Models\Approval;
use App\Models\Brand;
use App\Models\ContentCalendarEntry;
use App\Models\ContentClaimsRule;
use App\Models\ContentPublication;
use App\Models\ContentTestimonial;
use App\Models\PlatformAccount;
use App\Platform\PlatformRegistry;
use App\Research\ResearchRefused;
use Database\Seeders\DomainSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Completion\M4;
use Tests\Support\Completion\R3;
use Tests\Support\Content\PhaseSix;

/*
 | ChatGPT ruling 31 Oct 2026, items 13 and 15 (content side). The claims floor stays absolute; a recorded,
 | consented testimonial may waive ONLY the floor's testimonial phrase rule, and consent or model output can
 | never override the claims validators. A draft's writer approves it only as the sole eligible reviewer, with
 | a reason, recorded as self-decided; approving a draft never authorises publication.
 */

beforeEach(function () {
    Http::preventStrayRequests();
    $this->seed(DomainSeeder::class);
    $this->owner = $this->hqUser('owner');
    $this->lld = Brand::where('code', 'LLD')->sole();
    $this->bnc = Brand::where('code', 'BNC')->sole();
    PhaseSix::brief($this->lld, $this->owner);
    PhaseSix::brief($this->bnc, $this->owner);
    M4::on('content.drafting');
});

function r3Entry(Brand $brand, string $platform = 'facebook_page'): ContentCalendarEntry
{
    test()->actingAsHq(test()->owner)->post('/content/calendar', [
        'brand_id' => $brand->id, 'planned_for' => now()->addDays(3)->toDateString(), 'platform' => $platform,
        'product_lines' => 'mustard_oil', 'idea' => 'A customer on mustard oil',
    ])->assertSessionHasNoErrors();

    return ContentCalendarEntry::query()->latest('id')->firstOrFail();
}

function r3Testimonial(array $over = []): ContentTestimonial
{
    test()->actingAsHq(test()->owner)->post('/content/testimonials', R3::testimonial(test()->lld->business, test()->lld->id, $over))->assertSessionHasNoErrors();

    return ContentTestimonial::query()->latest('id')->firstOrFail();
}

function r3Draft(ContentCalendarEntry $entry, string $text, ?ContentTestimonial $t = null, bool $edited = false)
{
    test()->actingAsHq(test()->owner)->post("/content/calendar/{$entry->uuid}/draft", array_filter([
        'text' => $text, 'testimonial_uuid' => $t?->uuid, 'testimonial_edited' => $edited ?: null,
    ], fn ($v) => $v !== null))->assertSessionHasNoErrors();

    return $entry->fresh()->draft;
}

const R3_QUOTE = 'সরিষার তেলের ঘ্রাণ খুব ভালো লেগেছে।';

it('records a testimonial only with consent and evidence of this business, behind the claims permission, and never widens or deletes it', function () {
    foreach (['content.testimonials.store' => 'permission:hq.content.claims.manage', 'content.testimonials.revoke' => 'permission:hq.content.claims.manage'] as $name => $p) {
        expect(Route::getRoutes()->getByName($name)->gatherMiddleware())->toContain('auth', '2fa.enabled', '2fa.verified', $p);
    }
    $biz = $this->lld->business;
    $this->actingAsHq($this->hqUser('brand_manager'))->post('/content/testimonials', R3::testimonial($biz, $this->lld->id))->assertForbidden();
    // Consent kept in another business's library, a generated "evidence" file, or one record used as both: refused.
    $this->actingAsHq($this->owner)->post('/content/testimonials', R3::testimonial($biz, $this->lld->id, ['consent_document_asset_id' => R3::asset($this->bnc->business)->id]))->assertSessionHasErrors('form');
    $this->actingAsHq($this->owner)->post('/content/testimonials', R3::testimonial($biz, $this->lld->id, ['original_evidence_asset_id' => R3::asset($biz, 'image', 'generated')->id]))->assertSessionHasErrors('form');
    $same = R3::asset($biz);
    $this->actingAsHq($this->owner)->post('/content/testimonials', R3::testimonial($biz, $this->lld->id, ['original_evidence_asset_id' => $same->id, 'consent_document_asset_id' => $same->id]))->assertSessionHasErrors('form');
    expect(ContentTestimonial::query()->count())->toBe(0);

    $t = r3Testimonial();
    expect(fn () => DB::transaction(fn () => ContentTestimonial::query()->whereKey($t->id)->update(['channels' => json_encode(['facebook_page', 'instagram'])])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => ContentTestimonial::query()->whereKey($t->id)->update(['original_statement' => 'embellished'])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => ContentTestimonial::query()->whereKey($t->id)->delete()))->toThrow(QueryException::class);
});

it('waives only the floor testimonial phrase for a verbatim, consented quote written by a person', function () {
    $t = r3Testimonial();
    $entry = r3Entry($this->lld);

    // Without the recorded testimonial the floor blocks the same words.
    $plain = r3Draft($entry, 'আমাদের গ্রাহকরা বলেন: '.R3_QUOTE);
    expect($plain->status)->toBe('failed_checks')->and(collect($plain->checks['claims_policy']['violations'])->pluck('rule')->all())->toContain('floor.testimonial');

    $draft = r3Draft($entry, 'আমাদের গ্রাহকরা বলেন: '.R3_QUOTE, $t);
    expect($draft->status)->toBe('checked')->and($draft->testimonial_id)->toBe($t->id)
        ->and($draft->checks['claims_policy']['testimonial']['applies'])->toBeTrue()
        ->and($draft->checks['claims_policy']['testimonial']['edited'])->toBeFalse();
});

it('never lets consent override the claims validators: a prohibited claim in a real, consented quote still fails', function () {
    $t = r3Testimonial(['original_statement' => 'এই তেল খেয়ে আমার রক্তচাপ কমেছে।']);
    $entry = r3Entry($this->lld);
    $draft = r3Draft($entry, 'গ্রাহকরা বলেন: এই তেল খেয়ে আমার রক্তচাপ কমেছে।', $t);
    expect($draft->status)->toBe('failed_checks')
        ->and($draft->checks['claims_policy']['testimonial']['applies'])->toBeTrue()
        ->and(collect($draft->checks['claims_policy']['violations'])->pluck('rule')->all())->toContain('floor.health_claim')->not->toContain('floor.testimonial');

    // The owner's own forbidden words also still apply inside a quote.
    ContentClaimsRule::query()->create(['business_id' => $this->lld->business_id, 'product_line' => 'mustard_oil', 'kind' => 'forbidden', 'phrases' => ['ঘ্রাণ'], 'reason' => 'owner', 'status' => 'active']);
    $t2 = r3Testimonial();
    expect(r3Draft($entry, 'গ্রাহকরা বলেন: '.R3_QUOTE, $t2)->status)->toBe('failed_checks');

    $this->actingAsHq($this->owner)->post("/content/calendar/{$entry->uuid}/review", ['verdict' => 'approved', 'self_reason' => 'x'])->assertSessionHasErrors('form');
});

it('never lets model output carry a testimonial, or present rewritten words as the customer\'s', function () {
    $t = r3Testimonial();
    $entry = r3Entry($this->lld);

    // Model output naming the testimonial: the exception never applies to generated words.
    $generated = app(ContentDraftService::class)->create([
        'brief_id' => app(ContentCalendar::class)->latestBrief($this->lld->id)->id, 'text' => 'গ্রাহকরা বলেন: '.R3_QUOTE, 'text_provenance' => 'generated',
        'platform' => 'facebook_page', 'product_lines' => ['mustard_oil'], 'testimonial_id' => $t->id,
    ], $this->owner);
    expect($generated->status)->toBe('failed_checks')->and($generated->checks['claims_policy']['testimonial']['applies'])->toBeFalse()
        ->and(implode(' ', $generated->checks['claims_policy']['testimonial']['reasons']))->toContain('model')
        ->and(collect($generated->checks['claims_policy']['violations'])->pluck('rule')->all())->toContain('floor.testimonial');

    // Embellished words presented as the customer's: fail.
    $embellished = r3Draft($entry, 'গ্রাহকরা বলেন: সরিষার তেলের ঘ্রাণ অসাধারণ, জীবনে সেরা!', $t);
    expect($embellished->status)->toBe('failed_checks')->and($embellished->checks['claims_policy']['testimonial']['edited'])->toBeTrue();

    // A person's declared edit passes the checks, but is approved only against the exact final post.
    $edited = r3Draft($entry, 'গ্রাহকরা বলেন (সম্পাদিত): তেলের ঘ্রাণ ভালো।', $t, true);
    expect($edited->status)->toBe('checked')->and($edited->checks['claims_policy']['testimonial']['edited'])->toBeTrue();
    $this->actingAsHq($this->owner)->post("/content/calendar/{$entry->uuid}/review", ['verdict' => 'approved', 'self_reason' => 'sole'])->assertSessionHasErrors('form');
    $this->actingAsHq($this->owner)->post("/content/calendar/{$entry->uuid}/review", ['verdict' => 'approved', 'self_reason' => 'sole', 'confirm_digest' => str_repeat('0', 64)])->assertSessionHasErrors('form');
    $this->actingAsHq($this->owner)->post("/content/calendar/{$entry->uuid}/review", ['verdict' => 'approved', 'self_reason' => 'sole', 'confirm_digest' => $edited->content_digest])->assertSessionHasNoErrors();
    expect($edited->fresh()->status)->toBe('approved');
});

it('holds the consent to its exact brand, channel, period and modality, and to its withdrawal, again at publish time', function () {
    $t = r3Testimonial();
    // Instagram is not a channel the customer agreed to.
    expect(r3Draft(r3Entry($this->lld, 'instagram'), 'গ্রাহকরা বলেন: '.R3_QUOTE, $t)->status)->toBe('failed_checks');
    // An expired period.
    $old = r3Testimonial(['valid_from' => now()->subMonths(3)->toDateString(), 'valid_until' => now()->subDay()->toDateString()]);
    expect(r3Draft(r3Entry($this->lld), 'গ্রাহকরা বলেন: '.R3_QUOTE, $old)->status)->toBe('failed_checks');
    // Another brand's testimonial cannot be quoted for this brand (and another business's not at all).
    $this->actingAsHq($this->owner)->post('/content/testimonials', R3::testimonial($this->bnc->business, $this->bnc->id))->assertSessionHasNoErrors();
    $theirs = ContentTestimonial::query()->latest('id')->firstOrFail();
    $entry = r3Entry($this->lld);
    $this->actingAsHq($this->owner)->post("/content/calendar/{$entry->uuid}/draft", ['text' => 'গ্রাহকরা বলেন: '.R3_QUOTE, 'testimonial_uuid' => $theirs->uuid])->assertSessionHasErrors('form');

    // Approved, then the customer withdraws consent: the publish-time re-check refuses.
    M4::on('publishing.write');
    $entry = r3Entry($this->lld);
    $draft = r3Draft($entry, 'গ্রাহকরা বলেন: '.R3_QUOTE, $t);
    $this->actingAsHq($this->owner)->post("/content/calendar/{$entry->uuid}/review", ['verdict' => 'approved', 'self_reason' => 'sole'])->assertSessionHasNoErrors();
    $this->actingAsHq($this->owner)->post("/content/testimonials/{$t->uuid}/revoke", ['reason' => 'customer asked'])->assertSessionHasNoErrors();
    expect(fn () => DB::transaction(fn () => ContentTestimonial::query()->whereKey($t->id)->update(['revoked_at' => null])))->toThrow(QueryException::class);

    $account = PlatformAccount::create(['brand_id' => $this->lld->id, 'platform' => 'facebook_page', 'account_ref' => 'SANDBOX-PAGE', 'name' => 'LaLaDia',
        'capabilities' => ['publish_post'], 'approval_status' => 'approved', 'is_active' => true]);
    $registry = new PlatformRegistry;
    $registry->register($publisher = new FakePostPublisher);
    app()->instance(PlatformRegistry::class, $registry);
    $draft = $draft->fresh();
    expect(fn () => app(PublicationService::class)->publish($draft, $account, ContentDigest::forPublication($draft, $account->id), 1, [ProductFact::fromArray(PhaseSix::erpPrice('MO-1L', '450'))]))
        ->toThrow(ResearchRefused::class)
        ->and($publisher->published())->toBe([]);
});

it('keeps testimonials inside the business scope', function () {
    $this->actingAsHq($this->owner)->post('/content/testimonials', R3::testimonial($this->bnc->business, $this->bnc->id))->assertSessionHasNoErrors();
    $theirs = ContentTestimonial::query()->sole();
    $limited = M4::limit($this->hqUser('operator'), 'laladia');

    $this->actingAsHq($limited)->get('/content')->assertOk()->assertInertia(fn (Assert $p) => $p->has('testimonials', 0));
    $this->actingAsHq($limited)->post("/content/testimonials/{$theirs->uuid}/revoke", ['reason' => 'x'])->assertNotFound();
    $this->actingAsHq($limited)->post('/content/testimonials', R3::testimonial($this->bnc->business, $this->bnc->id))->assertNotFound();
    $entry = r3Entry($this->lld);
    $this->actingAsHq($limited)->post("/content/calendar/{$entry->uuid}/draft", ['text' => 'x', 'testimonial_uuid' => $theirs->uuid])->assertNotFound();
    expect($theirs->fresh()->revoked_at)->toBeNull();
});

it('lets the writer approve their own draft only as the sole eligible reviewer, with a reason, recorded; never once a second reviewer exists', function () {
    $entry = r3Entry($this->lld);
    r3Draft($entry, 'সরিষার তেল, শীতের রান্নায়।');

    // A reviewer limited to the other business, an inactive one, and one without 2FA do not count.
    M4::limit($this->hqUser('operator'), 'bionic');
    $this->hqUser('operator', ['is_active' => false]);
    $this->hqUser('operator')->forceFill(['two_factor_confirmed_at' => null])->save();
    $this->actingAsHq($this->owner)->post("/content/calendar/{$entry->uuid}/review", ['verdict' => 'approved'])->assertSessionHasErrors('form');
    $this->actingAsHq($this->owner)->post("/content/calendar/{$entry->uuid}/review", ['verdict' => 'approved', 'self_reason' => 'no one else this week'])->assertSessionHasNoErrors();
    $draft = $entry->fresh()->draft;
    expect($draft->self_decided)->toBeTrue()->and($draft->self_decided_reason)->toBe('no one else this week')->and($draft->self_decided_at)->not->toBeNull()
        ->and($draft->reviewed_by)->toBe($this->owner->id)
        // Draft approval authorises nothing to publish: no publication, no publication approval.
        ->and(ContentPublication::query()->count())->toBe(0)
        ->and(Approval::query()->where('tool_key', 'publishing.publish_post')->count())->toBe(0);

    // A second eligible reviewer for this business now exists: independent review is required.
    $second = $this->hqUser('operator');
    $next = r3Entry($this->lld);
    r3Draft($next, 'সরিষার তেল, শীতের রান্নায়।');
    $this->actingAsHq($this->owner)->post("/content/calendar/{$next->uuid}/review", ['verdict' => 'approved', 'self_reason' => 'still me'])->assertSessionHasErrors('form');
    expect($next->fresh()->status)->toBe('drafted');
    $this->actingAsHq($second)->post("/content/calendar/{$next->uuid}/review", ['verdict' => 'approved'])->assertSessionHasNoErrors();
    expect($next->fresh()->draft->self_decided)->toBeFalse()->and($next->fresh()->draft->reviewed_by)->toBe($second->id);

    // The screen tells the writer which case they are in.
    $this->actingAsHq($this->owner)->get('/content')->assertInertia(fn (Assert $p) => $p->where('entries', fn ($rows) => collect($rows)->every(fn ($r) => $r['review'] === null || $r['review']['sole_reviewer'] === false)));
});

it('keeps draft approval apart from publication in the code: the calendar and draft service never reach publishing', function () {
    foreach (['Content/Calendar/ContentCalendar.php', 'Content/ContentDraftService.php', 'Content/ContentTestimonials.php', 'Http/Controllers/Completion/ContentController.php'] as $file) {
        // Code only: comments may name what the code never calls.
        $source = collect(token_get_all((string) file_get_contents(app_path($file))))
            ->reject(fn ($t) => is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true))
            ->map(fn ($t) => is_array($t) ? $t[1] : $t)->implode('');
        expect($source)->not->toContain('PublicationService')->not->toContain("'publishing.publish_post'")->not->toContain('ApprovalService');
    }
});
