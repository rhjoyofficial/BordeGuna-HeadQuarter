<?php

use App\Conversation\Channels\MetaCommentNormaliser;
use App\Conversation\Channels\MetaInstagramNormaliser;
use App\Conversation\Channels\MetaMessengerNormaliser;
use App\Conversation\ConversationState;
use App\Conversation\Simulation\ConversationSimulator;
use App\Conversation\Simulation\ScenarioLibrary;
use App\Gateway\Descriptor\Channel;
use App\Models\Brand;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use App\Models\DomainEvent;
use App\Models\IntegrationCredential;
use App\Models\PlatformAccount;
use Database\Seeders\AgentSeeder;
use Database\Seeders\DomainSeeder;
use Illuminate\Support\Facades\Cache;

/*
 | docs/78 B.1: Instagram DMs and comments on a Page's posts, through the same channel-neutral core.
 |
 | Proved here: each normaliser is pure and reduces its recorded Meta shape to InboundMessages with
 | its own account, thread and identity, and yields nothing for the other shapes, for our own echoes
 | and replies, for edits, removals and reactions; the webhook accepts an Instagram envelope and finds
 | the business through the Instagram account row; the drain hands one event to all three normalisers
 | and the core makes a thread, a customer under the right identity channel and untrusted messages;
 | and the simulation covers both channels.
 */

beforeEach(function () {
    Cache::flush();
    $this->seed(DomainSeeder::class);
    $brand = Brand::where('code', 'LLD')->sole();
    PlatformAccount::updateOrCreate(['platform' => 'facebook_page', 'account_ref' => '100085015853889'],
        ['brand_id' => $brand->id, 'name' => 'LaLaDia page', 'approval_status' => 'unknown', 'is_active' => true]);
    PlatformAccount::updateOrCreate(['platform' => 'instagram_account', 'account_ref' => '17841400000000001'],
        ['brand_id' => $brand->id, 'name' => 'LaLaDia Instagram', 'approval_status' => 'unknown', 'is_active' => true]);
});

function igFixture(string $name): array
{
    return json_decode((string) file_get_contents(base_path("tests/fixtures/meta/{$name}.json")), true);
}

it('reduces an Instagram DM fixture to messages on the Instagram channel, dropping the echo and the read receipt', function () {
    $messages = iterator_to_array((new MetaInstagramNormaliser)->normalise(igFixture('instagram_text')), false);

    expect($messages)->toHaveCount(3);
    [$text, $story, $image] = $messages;
    expect($text->channel)->toBe(Channel::Instagram)
        ->and($text->platformAccountRef)->toBe('17841400000000001')
        ->and($text->externalThreadRef)->toBe('17841400000000001:5591230000000001')
        ->and($text->senderExternalId)->toBe('5591230000000001')
        ->and($text->body)->toBe('ইলিশ আচার ৫০০ গ্রামের দাম কত?')
        ->and($text->receivedAt->getTimestampMs())->toBe(1757757800100)
        ->and($story->metadata['story_ref'])->toBe('18000000000000001')
        ->and($image->body)->toBeNull()
        ->and($image->attachments)->toBe([['type' => 'image', 'ref' => 'https://lookaside.example/photo.jpg']]);

    // The other two normalisers see nothing in an Instagram envelope.
    expect(iterator_to_array((new MetaMessengerNormaliser)->normalise(igFixture('instagram_text')), false))->toBe([])
        ->and(iterator_to_array((new MetaCommentNormaliser)->normalise(igFixture('instagram_text')), false))->toBe([]);
});

it('reduces a feed fixture to added comments by others only: one thread per person per post, named, with the post and the parent', function () {
    $messages = iterator_to_array((new MetaCommentNormaliser)->normalise(igFixture('feed_comment')), false);

    // Six changes: a comment, the Page's own reply, an edit, a reaction, a second comment by the same person, a comment with a photo.
    expect($messages)->toHaveCount(3);
    [$first, $second, $photo] = $messages;
    expect($first->channel)->toBe(Channel::Comment)
        ->and($first->platformAccountRef)->toBe('100085015853889')
        ->and($first->externalThreadRef)->toBe('comment:100085015853889:100085015853889_9001:2233440000000001')
        ->and($first->externalMessageRef)->toBe('100085015853889_9001_7001')
        ->and($first->senderExternalId)->toBe('2233440000000001')
        ->and($first->body)->toBe('দাম কত? ঢাকায় ডেলিভারি হয়?')
        ->and($first->receivedAt->getTimestamp())->toBe(1757757900)
        ->and($first->metadata)->toBe(['post_id' => '100085015853889_9001', 'sender_name' => 'Rina Akter'])
        ->and($second->externalThreadRef)->toBe($first->externalThreadRef)
        ->and($second->metadata['parent_id'])->toBe('100085015853889_9001_7002')
        ->and($photo->externalThreadRef)->toBe('comment:100085015853889:100085015853889_9002:2233440000000003')
        ->and($photo->attachments)->toBe([['type' => 'image', 'ref' => 'https://scontent.example/comment-photo.jpg']]);

    // The Messenger normaliser sees a page envelope with no `messaging`, so nothing.
    expect(iterator_to_array((new MetaMessengerNormaliser)->normalise(igFixture('feed_comment')), false))->toBe([]);
});

it('accepts an Instagram envelope at the webhook and files the event under the account\'s business', function () {
    metaFlag(true);
    IntegrationCredential::create(['provider' => 'meta_app_secret', 'account_ref' => 'meta', 'credential_type' => 'api_key', 'payload' => ['value' => 'meta-app-secret'], 'status' => 'active']);

    metaPost($this, igFixture('instagram_text'))->assertStatus(202)->assertJsonPath('duplicate', false);
    metaPost($this, ['object' => 'user', 'entry' => []])->assertStatus(422); // docs/88 stream Q: a WhatsApp envelope is accepted now, so the malformed example is another object

    $event = DomainEvent::where('type', 'conversation.inbound_received')->latest('id')->firstOrFail();
    expect($event->business_id)->toBe(Brand::where('code', 'LLD')->sole()->business_id)
        ->and($event->aggregate_id)->toBe('17841400000000001');
});

it('turns an Instagram event and a comment event into threads, customers under their own identity channels, and untrusted messages', function () {
    metaFlag(true);
    IntegrationCredential::create(['provider' => 'meta_app_secret', 'account_ref' => 'meta', 'credential_type' => 'api_key', 'payload' => ['value' => 'meta-app-secret'], 'status' => 'active']);

    metaPost($this, igFixture('instagram_text'))->assertStatus(202);
    metaPost($this, igFixture('feed_comment'))->assertStatus(202);
    $this->artisan('hq:events:process')->assertSuccessful();

    $ig = Conversation::with('platformAccount')->where('channel', Channel::Instagram->value)->sole();
    expect($ig->state)->toBe(ConversationState::HumanRequired)
        ->and($ig->platformAccount->platform)->toBe('instagram_account')
        ->and($ig->messages()->where('direction', 'inbound')->count())->toBe(3)
        ->and(CustomerIdentity::where('customer_id', $ig->customer_id)->sole()->channel)->toBe('instagram_igsid');

    $comments = Conversation::with(['platformAccount', 'customer'])->where('channel', Channel::Comment->value)->orderBy('id')->get();
    expect($comments)->toHaveCount(2)
        ->and($comments[0]->messages()->where('direction', 'inbound')->count())->toBe(2)
        ->and($comments[0]->customer->name)->toBe('Rina Akter')
        ->and(CustomerIdentity::where('customer_id', $comments[0]->customer_id)->sole()->channel)->toBe('facebook_user')
        ->and($comments[0]->platformAccount->platform)->toBe('facebook_page')
        ->and(ConversationMessage::where('conversation_id', $comments[0]->id)->pluck('provenance')->map->value->unique()->all())->toBe(['customer']);

    // Redelivery of either changes nothing.
    metaPost($this, igFixture('feed_comment'))->assertStatus(202)->assertJsonPath('duplicate', true);
    $this->artisan('hq:events:process')->assertSuccessful();
    expect(Conversation::count())->toBe(3)->and(Customer::count())->toBe(3);
});

it('covers both channels in the simulation world, traceless', function () {
    $this->seed(AgentSeeder::class);
    $simulator = app(ConversationSimulator::class);

    $ig = $simulator->capture(ScenarioLibrary::find('instagram.dm'), 'LLD');
    $fb = $simulator->capture(ScenarioLibrary::find('facebook.comment'), 'LLD');

    expect($ig['core_passed'])->toBeTrue(implode('; ', $ig['core_mismatches']))
        ->and($fb['core_passed'])->toBeTrue(implode('; ', $fb['core_mismatches']))
        ->and($fb['messages'])->toHaveCount(2)
        ->and(implode("\n", array_column($fb['context'], 'content')))->toContain('name: Rina Akter')
        ->and(Conversation::count())->toBe(0)
        ->and(PlatformAccount::where('account_ref', ConversationSimulator::INSTAGRAM)->exists())->toBeFalse();
});
