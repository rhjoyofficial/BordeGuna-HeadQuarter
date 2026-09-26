<?php

use App\Content\ContentDigest;
use App\Content\ContentDraftService;
use App\Content\ProductFact;
use App\Models\ContentBrief;
use App\Models\ContentDraft;
use App\Models\FeatureFlag;
use App\Models\MediaAsset;
use App\Research\ArtifactFiler;
use App\Research\ResearchRefused;
use App\Research\ResearchService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\Content\PhaseSix;

/*
 | Stream T (docs/88 A.5): a draft states product facts only from the ERP or the shop (or a source a
 | person approved), every figure must be traceable (content.numbers.v1, built on briefing.numbers.v1),
 | the owner's claims policy is enforced and fails closed, and a checked draft's words never change.
 | The claims policy used here is the EXAMPLE fixture, not the owner's.
 */

beforeEach(function () {
    Http::preventStrayRequests();
    PhaseSix::on('content.drafting');
    $this->w = PhaseSix::world();
    $this->brief = PhaseSix::brief($this->w['brand'], $this->w['user']);
});

function cdDraft(array $w, ContentBrief $brief, string $text, array $overrides = []): ContentDraft
{
    return app(ContentDraftService::class)->create(array_merge([
        'brief_id' => $brief->id,
        'text' => $text,
        'text_provenance' => 'generated',
        'platform' => 'facebook_page',
        'product_lines' => ['mustard_oil'],
        'product_facts' => [PhaseSix::erpPrice('MO-1L', '450'), PhaseSix::erpSize('MO-1L', '1000')],
    ], $overrides), $w['user']);
}

it('passes a draft whose every figure is a product fact from the ERP', function () {
    $draft = cdDraft($this->w, $this->brief, 'ঘানি ভাঙা সরিষার তেল, ১০০০ ml বোতল, দাম ৪৫০ টাকা।');

    expect($draft->status)->toBe('checked')
        ->and($draft->checks['numbers']['rule'])->toBe('content.numbers.v1')
        ->and($draft->checks['numbers']['built_on'])->toBe('briefing.numbers.v1')
        ->and($draft->checks['passed'])->toBeTrue()
        ->and($draft->text_provenance)->toBe('generated')
        ->and($draft->product_facts[0]['system'])->toBe('erp');
});

it('fails a draft whose price differs from the ERP, and leaves its words exactly as written', function () {
    $text = 'Mustard oil 1000 ml, now only 420 taka!';
    $draft = cdDraft($this->w, $this->brief, $text);

    expect($draft->status)->toBe('failed_checks')
        ->and($draft->text)->toBe($text)
        ->and($draft->checks['numbers']['passed'])->toBeFalse()
        ->and(array_column($draft->checks['numbers']['unsourced'], 'figure'))->toContain('420 taka');
});

it('fails a figure that is a sum or a share of two facts, because nothing is computed', function () {
    $draft = cdDraft($this->w, $this->brief, 'Buy two for 900 taka.');

    expect($draft->status)->toBe('failed_checks');
});

it('fails a forbidden health claim of the EXAMPLE policy, in English and in Bangla', function () {
    $en = cdDraft($this->w, $this->brief, 'Our mustard oil cures cancer and costs 450 taka.');
    $bn = cdDraft($this->w, $this->brief, 'এই সরিষার তেল হৃদরোগ প্রতিরোধ করে।');

    expect($en->status)->toBe('failed_checks')
        ->and($en->checks['claims_policy']['violations'][0]['rule'])->toBe('health.cure_or_prevent')
        ->and($bn->status)->toBe('failed_checks')
        ->and(collect($bn->checks['claims_policy']['violations'])->pluck('phrase')->all())->toContain('হৃদরোগ প্রতিরোধ');
});

it('does not read a forbidden phrase inside another word', function () {
    $draft = cdDraft($this->w, $this->brief, 'A secure, sealed 1000 ml bottle.');

    expect($draft->checks['claims_policy']['violations'])->toBe([])->and($draft->status)->toBe('checked');
});

it('fails closed for a product line the owner wrote no policy for, and for a draft that names none', function () {
    $noPolicy = cdDraft($this->w, $this->brief, 'Fresh ghee.', ['product_lines' => ['ghee']]);
    $noLine = cdDraft($this->w, $this->brief, 'Fresh oil.', ['product_lines' => []]);

    expect($noPolicy->status)->toBe('failed_checks')
        ->and($noPolicy->checks['claims_policy']['uncovered_product_lines'])->toBe(['ghee'])
        ->and($noLine->status)->toBe('failed_checks');
});

it('fails a banned word of the brief', function () {
    $draft = cdDraft($this->w, $this->brief, 'A miracle oil.');

    expect($draft->status)->toBe('failed_checks')->and($draft->checks['banned_words']['found'])->toBe(['miracle']);
});

it('refuses a product fact that did not come from the ERP, a shop or a person-approved source', function () {
    $web = ['key' => 'price:X', 'label' => 'p', 'value' => '400', 'unit' => 'BDT', 'system' => 'research', 'ref' => 'https://x', 'as_of' => now()->toIso8601String()];
    $unapproved = array_merge($web, ['system' => 'approved_source']);

    expect(fn () => cdDraft($this->w, $this->brief, 'x', ['product_facts' => [$web]]))->toThrow(ResearchRefused::class, 'only from the ERP')
        ->and(fn () => cdDraft($this->w, $this->brief, 'x', ['product_facts' => [$unapproved]]))->toThrow(ResearchRefused::class, 'no person who approved');
    expect(ProductFact::fromArray(array_merge($web, ['system' => 'approved_source', 'approved_by' => $this->w['user']->id]))->system)->toBe('approved_source');
});

it('lets a draft cite a research fact with its value, and refuses citing an inference as a fact', function () {
    PhaseSix::on('research.engine');
    PhaseSix::publicDns();
    $task = app(ResearchService::class)->open(['business_id' => $this->w['business']->id, 'brand_id' => $this->w['brand']->id,
        'question' => 'Mills?', 'approved_sources' => ['bfsa.gov.bd'], 'sources_approved_by' => $this->w['user']->id,
        'max_searches' => 0, 'max_fetches' => 1], $this->w['user']);
    Http::fake(['https://bfsa.gov.bd/m' => Http::response('<p>There are 12 approved mills.</p>', 200, ['Content-Type' => 'text/html'])]);
    $source = app(ResearchService::class)->fetch($task, 'https://bfsa.gov.bd/m')['source'];
    $artifact = app(ArtifactFiler::class)->file($task, [
        ['id' => 'mills', 'kind' => 'fact', 'statement' => 'There are 12 approved mills.', 'source' => $source->uuid,
            'span' => ['start' => 0, 'end' => 28], 'excerpt' => 'There are 12 approved mills.', 'value' => '12'],
        ['id' => 'guess', 'kind' => 'inference', 'statement' => 'Supply is plentiful.', 'based_on' => ['mills']],
    ]);

    $draft = cdDraft($this->w, $this->brief, 'Pressed at one of 12 approved mills.', ['research_artifact_id' => $artifact->id, 'claim_refs' => ['mills']]);
    expect($draft->status)->toBe('checked')->and($draft->claim_refs)->toBe(['mills']);

    expect(fn () => cdDraft($this->w, $this->brief, 'x', ['research_artifact_id' => $artifact->id, 'claim_refs' => ['guess']]))
        ->toThrow(ResearchRefused::class, 'states only facts and metrics');

    $other = PhaseSix::world('other-business');
    $otherBrief = PhaseSix::brief($other['brand'], $other['user']);
    expect(fn () => cdDraft($other, $otherBrief, 'x', ['research_artifact_id' => $artifact->id, 'claim_refs' => ['mills']]))
        ->toThrow(ResearchRefused::class, 'not one made for this business');
});

it('freezes a checked draft\'s words in the database; an edit is a new draft, checked again', function () {
    $draft = cdDraft($this->w, $this->brief, 'Mustard oil, 450 taka.');

    expect(fn () => DB::transaction(fn () => DB::table('content_drafts')->where('id', $draft->id)->update(['text' => 'Mustard oil, 399 taka.'])))
        ->toThrow(QueryException::class, 'frozen')
        ->and(fn () => DB::transaction(fn () => DB::table('content_drafts')->where('id', $draft->id)->delete()))->toThrow(QueryException::class);

    $edit = cdDraft($this->w, $this->brief, 'Mustard oil, 399 taka.', ['parent_draft_id' => $draft->id]);
    expect($edit->status)->toBe('failed_checks')->and($draft->fresh()->status)->toBe('superseded')->and($edit->parent_draft_id)->toBe($draft->id);
});

it('keeps briefs append-only and versioned', function () {
    expect(fn () => DB::transaction(fn () => DB::table('content_briefs')->where('id', $this->brief->id)->update(['persona' => 'loud'])))
        ->toThrow(QueryException::class, 'append-only');
    expect(PhaseSix::brief($this->w['brand'], $this->w['user'], version: 2)->version)->toBe(2);
});

it('approves only a checked draft', function () {
    $bad = cdDraft($this->w, $this->brief, 'A miracle.');
    $good = cdDraft($this->w, $this->brief, 'Mustard oil, 450 taka.');

    expect(fn () => app(ContentDraftService::class)->review($bad, 'approved', $this->w['user']))->toThrow(ResearchRefused::class, 'cannot be approved');
    expect(app(ContentDraftService::class)->review($good, 'approved', $this->w['user'])->status)->toBe('approved');
});

it('digests the exact words, assets, platform and variant', function () {
    $a = ContentDigest::ofDraftParts('তেল ৪৫০ টাকা', [], 'facebook_page', 'default', 1, 1, 1);

    expect(ContentDigest::ofDraftParts('তেল ৪৫০ টাকা', [], 'facebook_page', 'default', 1, 1, 1))->toBe($a)
        ->and(ContentDigest::ofDraftParts('তেল ৪৫১ টাকা', [], 'facebook_page', 'default', 1, 1, 1))->not->toBe($a)
        ->and(ContentDigest::ofDraftParts('তেল ৪৫০ টাকা', [['media_asset_id' => 3, 'checksum_sha256' => 'abc', 'role' => 'background']], 'facebook_page', 'default', 1, 1, 1))->not->toBe($a)
        ->and(ContentDigest::ofDraftParts('তেল ৪৫০ টাকা', [], 'instagram', 'default', 1, 1, 1))->not->toBe($a)
        ->and(ContentDigest::ofDraftParts('তেল ৪৫০ টাকা', [], 'facebook_page', 'story', 1, 1, 1))->not->toBe($a)
        // NFC: the same words in another Unicode form are the same words.
        ->and(ContentDigest::ofDraftParts(Normalizer::normalize('তেল ৪৫০ টাকা', Normalizer::FORM_D), [], 'facebook_page', 'default', 1, 1, 1))->toBe($a);
});

it('refuses a generated image as the product photo, an asset without publishing rights, and a PNG for Instagram', function () {
    $asset = fn (string $provenance, array $rights, string $mime = 'image/jpeg') => MediaAsset::create([
        'business_id' => $this->w['business']->id, 'kind' => 'image', 'mime' => $mime, 'bytes' => 10, 'checksum_sha256' => str_repeat('a', 64),
        'storage_driver' => 'local', 'storage_key' => uniqid(), 'provenance' => $provenance, 'rights' => $rights,
    ]);

    $generated = $asset('generated', ['allowed_uses' => ['publish']]);
    $noRights = $asset('business', []);
    $png = $asset('business', ['allowed_uses' => ['publish']], 'image/png');

    expect(fn () => cdDraft($this->w, $this->brief, 'x', ['assets' => [['media_asset_id' => $generated->id, 'role' => 'product_photo']]]))->toThrow(ResearchRefused::class, 'real photo')
        ->and(fn () => cdDraft($this->w, $this->brief, 'x', ['assets' => [['media_asset_id' => $noRights->id, 'role' => 'background']]]))->toThrow(ResearchRefused::class, 'right to be published')
        ->and(fn () => cdDraft($this->w, $this->brief, 'x', ['platform' => 'instagram', 'assets' => [['media_asset_id' => $png->id, 'role' => 'background']]]))->toThrow(ResearchRefused::class, 'JPEG');
});

it('refuses to draft while content.drafting is off', function () {
    FeatureFlag::query()->where('key', 'content.drafting')->delete();

    expect(fn () => cdDraft($this->w, $this->brief, 'Mustard oil, 450 taka.'))->toThrow(ResearchRefused::class, 'off');
    expect(ContentDraft::count())->toBe(0);
});
