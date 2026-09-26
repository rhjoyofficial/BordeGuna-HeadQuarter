<?php

use App\Commerce\Exceptions\Unsupported;
use App\Content\ContentDigest;
use App\Content\ContentDraftService;
use App\Content\ProductFact;
use App\Content\Publishing\FacebookPagePublisher;
use App\Content\Publishing\InstagramPublisher;
use App\Content\Publishing\PostReadBack;
use App\Content\Publishing\PublicationService;
use App\Content\Publishing\PublishOutcome;
use App\Content\Publishing\PublishRequest;
use App\Models\ContentPublication;
use App\Models\FeatureFlag;
use App\Models\IntegrationCredential;
use App\Models\PlatformAccount;
use App\Platform\PlatformRegistry;
use App\Research\ResearchRefused;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\Content\FakePublisher;
use Tests\Support\Content\PhaseSix;

/*
 | Stream T (docs/88 A.5, ADR-051 accepted): publication is R5, one approval per exact post, bound to a
 | digest of the words, assets and account; checked again against live facts; read back before it is
 | called published; reconciled before any retry. The adapters are exercised against Meta's documented
 | request shapes with every HTTP call faked. The workflow tests register a SCRIPTED publisher in a
 | registry of their own; the application's registry stays empty (PhaseSixInertTest).
 */

beforeEach(function () {
    Http::preventStrayRequests();
    PhaseSix::on('content.drafting', 'publishing.write');
    $this->w = PhaseSix::world();
    $this->brief = PhaseSix::brief($this->w['brand'], $this->w['user']);
    $this->account = PlatformAccount::create([
        'brand_id' => $this->w['brand']->id, 'platform' => 'facebook_page', 'account_ref' => 'PAGE123', 'name' => 'LaLaDia',
        'capabilities' => ['publish_post'], 'approval_status' => 'approved', 'is_active' => true,
    ]);
    $this->draft = app(ContentDraftService::class)->create([
        'brief_id' => $this->brief->id, 'text' => 'সরিষার তেল, দাম ৪৫০ টাকা।', 'text_provenance' => 'business', 'platform' => 'facebook_page',
        'product_lines' => ['mustard_oil'], 'product_facts' => [PhaseSix::erpPrice('MO-1L', '450')],
    ], $this->w['user']);
    app(ContentDraftService::class)->review($this->draft, 'approved', $this->w['user']);
    $this->digest = ContentDigest::forPublication($this->draft->fresh(), $this->account->id);
    $this->live = [ProductFact::fromArray(PhaseSix::erpPrice('MO-1L', '450'))];
});

function pbWith(FakePublisher $publisher): PublicationService
{
    $registry = new PlatformRegistry;
    $registry->register($publisher);
    app()->instance(PlatformRegistry::class, $registry);

    return app(PublicationService::class);
}

it('publishes the approved words and calls it published only after reading them back', function () {
    $fake = new FakePublisher(PublishOutcome::accepted('PAGE123_1'), new PostReadBack('PAGE123_1', 'সরিষার তেল,  দাম ৪৫০ টাকা।', '2026-09-22T10:00:00+0000'));

    $pub = pbWith($fake)->publish($this->draft->fresh(), $this->account, $this->digest, 7, $this->live);

    expect($pub->status)->toBe('published')->and($pub->platform_post_id)->toBe('PAGE123_1')->and($pub->approval_id)->toBe(7)
        ->and($pub->read_back['text'])->toContain('৪৫০')
        ->and($fake->published[0]->text)->toBe('সরিষার তেল, দাম ৪৫০ টাকা।')
        ->and($fake->published[0]->accountRef)->toBe('PAGE123');
});

it('refuses an approval whose digest covers other words, other assets or another account', function () {
    $fake = new FakePublisher(PublishOutcome::accepted('x'));
    $other = PlatformAccount::create(['brand_id' => $this->w['brand']->id, 'platform' => 'facebook_page', 'account_ref' => 'PAGE999',
        'capabilities' => ['publish_post'], 'approval_status' => 'approved', 'is_active' => true]);

    expect(fn () => pbWith($fake)->publish($this->draft->fresh(), $this->account, str_repeat('0', 64), null, $this->live))->toThrow(ResearchRefused::class, 'different content')
        // The approval was for PAGE123; the same words on PAGE999 are a different publication.
        ->and(fn () => pbWith($fake)->publish($this->draft->fresh(), $other, $this->digest, null, $this->live))->toThrow(ResearchRefused::class, 'different content');
    expect($fake->published)->toBe([]);
});

it('stops a publication when a live product fact changed since approval', function () {
    $fake = new FakePublisher(PublishOutcome::accepted('x'));
    $newPrice = [ProductFact::fromArray(PhaseSix::erpPrice('MO-1L', '480'))];

    expect(fn () => pbWith($fake)->publish($this->draft->fresh(), $this->account, $this->digest, null, $newPrice))->toThrow(ResearchRefused::class, 'live product facts');
    expect($fake->published)->toBe([]);
});

it('publishes only an approved draft, of its own brand and platform', function () {
    $fake = new FakePublisher(PublishOutcome::accepted('x'));
    $checked = app(ContentDraftService::class)->create(['brief_id' => $this->brief->id, 'text' => 'দাম ৪৫০ টাকা।', 'text_provenance' => 'business',
        'platform' => 'facebook_page', 'product_lines' => ['mustard_oil'], 'product_facts' => [PhaseSix::erpPrice('MO-1L', '450')]]);
    $ig = PlatformAccount::create(['brand_id' => $this->w['brand']->id, 'platform' => 'instagram', 'account_ref' => 'IG1',
        'capabilities' => ['publish_post'], 'approval_status' => 'approved', 'is_active' => true]);

    expect(fn () => pbWith($fake)->publish($checked, $this->account, ContentDigest::forPublication($checked, $this->account->id), null, $this->live))->toThrow(ResearchRefused::class, 'only a draft a person approved')
        ->and(fn () => pbWith($fake)->publish($this->draft->fresh(), $ig, ContentDigest::forPublication($this->draft->fresh(), $ig->id), null, $this->live))->toThrow(ResearchRefused::class, 'not an active account');
});

it('marks a lost answer uncertain, then adopts the one matching post before any retry', function () {
    $fake = new FakePublisher(PublishOutcome::uncertain('timeout'));
    $service = pbWith($fake);
    $first = $service->publish($this->draft->fresh(), $this->account, $this->digest, null, $this->live);
    expect($first->status)->toBe('uncertain')->and(count($fake->published))->toBe(1);

    $fake->recent = [new PostReadBack('PAGE123_9', 'সরিষার তেল, দাম ৪৫০ টাকা।', null), new PostReadBack('PAGE123_8', 'another post', null)];
    $second = $service->publish($this->draft->fresh(), $this->account, $this->digest, null, $this->live);

    expect($second->status)->toBe('published')->and($second->platform_post_id)->toBe('PAGE123_9')
        // Reconciled, not re-sent.
        ->and(count($fake->published))->toBe(1)
        ->and(ContentPublication::count())->toBe(1);
});

it('reconciles a row left pending by a crash between the send and the record, before anything is sent again', function () {
    $fake = new FakePublisher(PublishOutcome::accepted('PAGE123_5'), new PostReadBack('PAGE123_5', 'সরিষার তেল, দাম ৪৫০ টাকা।', null));
    ContentPublication::create([
        'draft_id' => $this->draft->id, 'platform_account_id' => $this->account->id, 'variant' => 'default',
        'logical_key' => "content:{$this->draft->uuid}|account:{$this->account->id}|variant:default",
        'content_digest' => $this->digest, 'status' => 'pending',
    ]);
    $fake->recent = [new PostReadBack('PAGE123_4', 'সরিষার তেল, দাম ৪৫০ টাকা।', null)];

    $pub = pbWith($fake)->publish($this->draft->fresh(), $this->account, $this->digest, null, $this->live);

    expect($pub->status)->toBe('published')->and($pub->platform_post_id)->toBe('PAGE123_4')
        ->and($fake->published)->toBe([])
        ->and(ContentPublication::count())->toBe(1);
});

it('keeps the post id when the read-back fails, and reads that id back before any retry', function () {
    $fake = new FakePublisher(PublishOutcome::accepted('PAGE123_6'));
    $fake->readBackFails = new ConnectionException('read timed out');
    $service = pbWith($fake);

    $first = $service->publish($this->draft->fresh(), $this->account, $this->digest, null, $this->live);
    expect($first->status)->toBe('uncertain')->and($first->platform_post_id)->toBe('PAGE123_6');

    $fake->readBackFails = null;
    $fake->readBack = new PostReadBack('PAGE123_6', 'সরিষার তেল, দাম ৪৫০ টাকা।', null);
    $second = $service->publish($this->draft->fresh(), $this->account, $this->digest, null, $this->live);

    expect($second->status)->toBe('published')->and($second->platform_post_id)->toBe('PAGE123_6')
        ->and(count($fake->published))->toBe(1)
        ->and($fake->readBackCalls)->toBe(['PAGE123_6', 'PAGE123_6']);
});

it('retries only when reconciliation shows no post exists, and stops at two matches', function () {
    $fake = new FakePublisher(PublishOutcome::uncertain('timeout'));
    $service = pbWith($fake);
    $service->publish($this->draft->fresh(), $this->account, $this->digest, null, $this->live);

    $fake->outcome = PublishOutcome::accepted('PAGE123_2');
    $fake->readBack = new PostReadBack('PAGE123_2', 'সরিষার তেল, দাম ৪৫০ টাকা।', null);
    expect($service->publish($this->draft->fresh(), $this->account, $this->digest, null, $this->live)->status)->toBe('published')
        ->and(count($fake->published))->toBe(2);

    // A second draft whose lost answer turns out to have made TWO posts: an incident for a person.
    ContentPublication::query()->update(['status' => 'uncertain', 'platform_post_id' => null]);
    $fake->recent = [new PostReadBack('a', 'সরিষার তেল, দাম ৪৫০ টাকা।', null), new PostReadBack('b', 'সরিষার তেল, দাম ৪৫০ টাকা।', null)];
    expect($service->publish($this->draft->fresh(), $this->account, $this->digest, null, $this->live)->status)->toBe('incident');
    expect(fn () => $service->publish($this->draft->fresh(), $this->account, $this->digest, null, $this->live))->toThrow(ResearchRefused::class, 'a person resolves');
});

it('returns the existing publication for a key already published, sending nothing', function () {
    $fake = new FakePublisher(PublishOutcome::accepted('P1'), new PostReadBack('P1', 'সরিষার তেল, দাম ৪৫০ টাকা।', null));
    $service = pbWith($fake);
    $one = $service->publish($this->draft->fresh(), $this->account, $this->digest, null, $this->live);
    $two = $service->publish($this->draft->fresh(), $this->account, $this->digest, null, $this->live);

    expect($two->id)->toBe($one->id)->and(count($fake->published))->toBe(1);
});

it('records a mismatch when the platform shows words other than the approved ones', function () {
    $fake = new FakePublisher(PublishOutcome::accepted('P1'), new PostReadBack('P1', 'সরিষার তেল, দাম ৪০০ টাকা।', null));

    expect(pbWith($fake)->publish($this->draft->fresh(), $this->account, $this->digest, null, $this->live)->status)->toBe('mismatch');
});

it('refuses for everyone with the application\'s own registry, which holds no publisher', function () {
    expect(fn () => app(PublicationService::class)->publish($this->draft->fresh(), $this->account, $this->digest, null, $this->live))
        ->toThrow(ResearchRefused::class, 'No publishing provider is registered');
    expect(ContentPublication::count())->toBe(0);
    Http::assertNothingSent();
});

it('refuses everything while publishing.write is off', function () {
    FeatureFlag::query()->where('key', 'publishing.write')->delete();
    $fake = new FakePublisher(PublishOutcome::accepted('x'));

    expect(fn () => pbWith($fake)->publish($this->draft->fresh(), $this->account, $this->digest, null, $this->live))->toThrow(ResearchRefused::class, 'off');
    expect($fake->published)->toBe([]);
});

// ── the adapters, against Meta's documented shapes ──────────────────────────────────────────────

it('posts to a Page as Meta documents it, and maps the answers', function () {
    IntegrationCredential::create(['provider' => FacebookPagePublisher::CREDENTIAL, 'account_ref' => 'PAGE123', 'credential_type' => 'token', 'payload' => ['value' => 'page-token'], 'status' => 'active']);
    Http::fake([
        'graph.facebook.com/v25.0/PAGE123/feed' => Http::sequence()
            ->push(['id' => 'PAGE123_77'])
            ->push(['error' => ['message' => 'Permissions error']], 403)
            ->push('', 502),
        'graph.facebook.com/v25.0/PAGE123_77*' => Http::response(['id' => 'PAGE123_77', 'message' => 'hello', 'created_time' => '2026-09-22T10:00:00+0000']),
    ]);

    $fb = app(FacebookPagePublisher::class);
    [$ok, $refused, $lost, $back] = withCredentials([[FacebookPagePublisher::CREDENTIAL, 'PAGE123']], fn () => [
        $fb->publish(new PublishRequest('PAGE123', 'hello', 'k')),
        $fb->publish(new PublishRequest('PAGE123', 'hello', 'k')),
        $fb->publish(new PublishRequest('PAGE123', 'hello', 'k')),
        $fb->readBack('PAGE123_77', 'PAGE123'),
    ]);

    expect($ok->status)->toBe('accepted')->and($ok->postId)->toBe('PAGE123_77')
        ->and($refused->status)->toBe('failed')->and($refused->error)->toContain('Permissions error')
        ->and($lost->status)->toBe('uncertain')
        ->and($back->matches('hello'))->toBeTrue();
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === 'https://graph.facebook.com/v25.0/PAGE123/feed'
        && $r['message'] === 'hello' && $r['published'] === 'true' && $r['access_token'] === 'page-token');
});

it('publishes to Instagram in Meta\'s two documented steps, and never calls a failed container a post', function () {
    IntegrationCredential::create(['provider' => InstagramPublisher::CREDENTIAL, 'account_ref' => 'IG1', 'credential_type' => 'token', 'payload' => ['value' => 'ig-token'], 'status' => 'active']);
    Http::fake([
        'graph.facebook.com/v25.0/IG1/media' => Http::sequence()->push(['id' => 'CONTAINER1'])->push(['error' => ['message' => 'bad image']], 400),
        'graph.facebook.com/v25.0/IG1/media_publish' => Http::response(['id' => 'MEDIA1']),
    ]);

    $ig = app(InstagramPublisher::class);
    [$ok, $bad, $noImage] = withCredentials([[InstagramPublisher::CREDENTIAL, 'IG1']], fn () => [
        $ig->publish(new PublishRequest('IG1', 'caption', 'k', 'https://cdn.example.com/card.jpg')),
        $ig->publish(new PublishRequest('IG1', 'caption', 'k', 'https://cdn.example.com/card.jpg')),
        $ig->publish(new PublishRequest('IG1', 'caption', 'k')),
    ]);

    expect($ok->status)->toBe('accepted')->and($ok->postId)->toBe('MEDIA1')
        ->and($bad->status)->toBe('failed')->and($bad->error)->toContain('bad image')
        ->and($noImage->status)->toBe('failed');
    Http::assertSent(fn (Request $r) => $r->url() === 'https://graph.facebook.com/v25.0/IG1/media' && $r['image_url'] === 'https://cdn.example.com/card.jpg'
        && $r['caption'] === 'caption' && $r->hasHeader('Authorization', 'Bearer ig-token'));
    Http::assertSent(fn (Request $r) => $r->url() === 'https://graph.facebook.com/v25.0/IG1/media_publish' && $r['creation_id'] === 'CONTAINER1');
    Http::assertSentCount(3);
});

it('cannot form a request without the token the gateway opens', function () {
    IntegrationCredential::create(['provider' => FacebookPagePublisher::CREDENTIAL, 'account_ref' => 'PAGE123', 'credential_type' => 'token', 'payload' => ['value' => 'page-token'], 'status' => 'active']);

    expect(fn () => app(FacebookPagePublisher::class)->publish(new PublishRequest('PAGE123', 'x', 'k')))->toThrow(Unsupported::class);
    Http::assertNothingSent();
});
