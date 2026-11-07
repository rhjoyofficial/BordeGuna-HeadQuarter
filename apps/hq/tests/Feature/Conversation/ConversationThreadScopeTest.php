<?php

use App\Conversation\Channels\InboundMessage;
use App\Conversation\Channels\OutboundEcho;
use App\Conversation\ConversationService;
use App\Conversation\ConversationThreadCollision;
use App\Gateway\Descriptor\Channel;
use App\Models\Brand;
use App\Models\Business;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationParticipant;
use App\Models\Customer;
use App\Models\PlatformAccount;
use Carbon\CarbonImmutable;
use Database\Seeders\DomainSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/*
 | docs/140 (ruling docs/138 section 5 P3-W1, docs/131 ruling 2): a platform thread reference is unique per business.
 |
 | Proved here: one reference in two businesses is two threads, each with its own customer and messages; a replay in one
 | business deduplicates; under the OLD group-wide key a second business's message fails closed and attaches nothing;
 | the migration refuses data that would violate the new key, and its down refuses once two businesses share a
 | reference; an echo lands only on its own Page's business's thread; and, on real PostgreSQL with two connections, a
 | same-business race resolves to the winner's thread while another business's thread neither waits nor collides.
 */

function threadScopeMigration(): object
{
    return require database_path('migrations/2026_10_17_100000_scope_conversation_threads_to_business.php');
}

function threadInbound(string $page, string $threadRef, string $sender, string $text, ?string $mid = null): InboundMessage
{
    return new InboundMessage(Channel::Messenger, $page, $threadRef, $mid ?? 'm-'.Str::uuid(), $sender, $text, CarbonImmutable::now(), hash('sha256', $text));
}

function raceThreadRow(int $businessId, string $ref): array
{
    return ['uuid' => (string) Str::uuid(), 'business_id' => $businessId, 'channel' => 'messenger', 'external_thread_ref' => $ref,
        'status' => 'open', 'owner' => 'none', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()];
}

beforeEach(function () {
    Cache::flush();
    $this->seed(DomainSeeder::class);
    $this->laladia = Business::where('slug', 'laladia')->sole();
    $this->bionic = Business::where('slug', 'bionic')->sole();
    PlatformAccount::updateOrCreate(['platform' => 'facebook_page', 'account_ref' => 'scope-page-lld'],
        ['brand_id' => Brand::where('code', 'LLD')->sole()->id, 'name' => 'LaLaDia test page', 'approval_status' => 'unknown', 'is_active' => true]);
    PlatformAccount::updateOrCreate(['platform' => 'facebook_page', 'account_ref' => 'scope-page-bnc'],
        ['brand_id' => Brand::where('code', 'BNC')->sole()->id, 'name' => 'Bionic test page', 'approval_status' => 'unknown', 'is_active' => true]);
});

it('opens two threads, one per business, for one thread reference, and never mixes their customers or messages', function () {
    $service = app(ConversationService::class);
    $a = $service->ingest(threadInbound('scope-page-lld', 'shared-thread-1', 'psid-shared', 'LaLaDia question'));
    $b = $service->ingest(threadInbound('scope-page-bnc', 'shared-thread-1', 'psid-shared', 'Bionic question'));

    $threadA = $a->conversation()->first();
    $threadB = $b->conversation()->first();
    expect($threadA->id)->not->toBe($threadB->id)
        ->and($threadA->business_id)->toBe($this->laladia->id)
        ->and($threadB->business_id)->toBe($this->bionic->id)
        ->and(Customer::find($threadA->customer_id)->business_id)->toBe($this->laladia->id)
        ->and(Customer::find($threadB->customer_id)->business_id)->toBe($this->bionic->id)
        ->and($threadA->customer_id)->not->toBe($threadB->customer_id)
        ->and(ConversationMessage::where('conversation_id', $threadA->id)->pluck('body')->all())->toBe(['LaLaDia question'])
        ->and(ConversationMessage::where('conversation_id', $threadB->id)->pluck('body')->all())->toBe(['Bionic question'])
        ->and(ConversationParticipant::where('conversation_id', $threadA->id)->pluck('customer_id')->all())->toBe([$threadA->customer_id])
        ->and(ConversationParticipant::where('conversation_id', $threadB->id)->pluck('customer_id')->all())->toBe([$threadB->customer_id]);
});

it('deduplicates a replay in one business: the same message once, a later message on the same thread', function () {
    $service = app(ConversationService::class);
    $first = $service->ingest(threadInbound('scope-page-lld', 'replay-thread', 'psid-replay', 'Hello', 'mid-replay-1'));
    $again = $service->ingest(threadInbound('scope-page-lld', 'replay-thread', 'psid-replay', 'Hello', 'mid-replay-1'));
    $later = $service->ingest(threadInbound('scope-page-lld', 'replay-thread', 'psid-replay', 'Still there?', 'mid-replay-2'));
    $direct = $service->openOrFind($this->laladia->id, Channel::Messenger, 'replay-thread');

    expect($again->id)->toBe($first->id)
        ->and($later->conversation_id)->toBe($first->conversation_id)
        ->and($direct->id)->toBe($first->conversation_id)
        ->and(Conversation::where('external_thread_ref', 'replay-thread')->count())->toBe(1)
        ->and(ConversationMessage::where('conversation_id', $first->conversation_id)->count())->toBe(2);
});

it('fails closed under the old group-wide key: another business\'s message attaches nothing, and works once the business key is deployed', function () {
    threadScopeMigration()->down();
    $service = app(ConversationService::class);
    $a = $service->ingest(threadInbound('scope-page-lld', 'old-key-thread', 'psid-old', 'LaLaDia first'));
    $threadA = $a->conversation()->first();
    $customersBefore = Customer::where('business_id', $this->bionic->id)->count();

    expect(fn () => $service->ingest(threadInbound('scope-page-bnc', 'old-key-thread', 'psid-old', 'Bionic second')))
        ->toThrow(ConversationThreadCollision::class, 'held open by another business');
    expect(fn () => $service->openOrFind($this->bionic->id, Channel::Messenger, 'old-key-thread'))->toThrow(ConversationThreadCollision::class);

    // Nothing of Bionic reached LaLaDia's thread, and the refused ingest left nothing behind.
    expect(ConversationMessage::where('conversation_id', $threadA->id)->pluck('body')->all())->toBe(['LaLaDia first'])
        ->and(ConversationParticipant::where('conversation_id', $threadA->id)->pluck('customer_id')->all())->toBe([$threadA->customer_id])
        ->and($threadA->fresh()->customer_id)->toBe($a->customer_id)
        ->and(Conversation::where('business_id', $this->bionic->id)->count())->toBe(0)
        ->and(Customer::where('business_id', $this->bionic->id)->count())->toBe($customersBefore)
        ->and(ConversationMessage::where('body', 'Bionic second')->count())->toBe(0);

    threadScopeMigration()->up();
    $b = $service->ingest(threadInbound('scope-page-bnc', 'old-key-thread', 'psid-old', 'Bionic second'));
    expect($b->conversation()->first()->business_id)->toBe($this->bionic->id)
        ->and($b->conversation_id)->not->toBe($threadA->id);
});

it('refuses to build the business key over duplicates, and refuses to roll back once two businesses share a reference', function () {
    threadScopeMigration()->down();
    DB::statement('DROP INDEX conversations_channel_thread_unique');
    foreach ([1, 2] as $n) {
        Conversation::create(['uuid' => (string) Str::uuid(), 'business_id' => $this->laladia->id, 'channel' => 'messenger', 'external_thread_ref' => 'dup-thread',
            'status' => 'open', 'owner' => 'none', 'opened_at' => now()]);
    }

    expect(fn () => threadScopeMigration()->up())->toThrow(RuntimeException::class, 'held more than once in one business');
    expect(DB::selectOne("SELECT count(*) AS n FROM pg_indexes WHERE indexname = 'conversations_business_channel_thread_unique'")->n)->toBe(0);

    // A closed duplicate is history, not a clash: the key is built.
    Conversation::where('external_thread_ref', 'dup-thread')->orderByDesc('id')->first()->forceFill(['status' => 'closed'])->save();
    threadScopeMigration()->up();
    Conversation::create(['uuid' => (string) Str::uuid(), 'business_id' => $this->bionic->id, 'channel' => 'messenger', 'external_thread_ref' => 'dup-thread',
        'status' => 'open', 'owner' => 'none', 'opened_at' => now()]);

    expect(fn () => threadScopeMigration()->down())->toThrow(RuntimeException::class, 'held by more than one business');
    expect(DB::selectOne("SELECT count(*) AS n FROM pg_indexes WHERE indexname = 'conversations_business_channel_thread_unique'")->n)->toBe(1)
        ->and(DB::selectOne("SELECT count(*) AS n FROM pg_indexes WHERE indexname = 'conversations_channel_thread_unique'")->n)->toBe(0);
});

it('records a Page echo only on a thread of that Page\'s business', function () {
    $service = app(ConversationService::class);
    $a = $service->ingest(threadInbound('scope-page-lld', 'echo-thread', 'psid-echo', 'LaLaDia asks'));
    $b = $service->ingest(threadInbound('scope-page-bnc', 'echo-thread', 'psid-echo', 'Bionic asks'));

    $echo = $service->recordExternalOutbound(new OutboundEcho(Channel::Messenger, 'scope-page-bnc', 'echo-thread', 'echo-mid-1', 'psid-echo', 'Bionic answers', CarbonImmutable::now(), 'd'));
    $unknownPage = $service->recordExternalOutbound(new OutboundEcho(Channel::Messenger, 'not-a-page-we-hold', 'echo-thread', 'echo-mid-2', 'psid-echo', 'Who?', CarbonImmutable::now(), 'd'));

    expect($echo->conversation_id)->toBe($b->conversation_id)
        ->and(ConversationMessage::where('conversation_id', $a->conversation_id)->where('direction', 'outbound')->count())->toBe(0)
        ->and($unknownPage)->toBeNull();
});

describe('on real PostgreSQL with two connections', function () {
    beforeEach(function () {
        foreach (['race_a', 'race_b'] as $name) {
            config(["database.connections.{$name}" => config('database.connections.pgsql')]);
            DB::purge($name);
        }
        $tag = 'thread-race-'.Str::lower(Str::random(8));
        $b = DB::connection('race_b');
        $this->raceA = (int) $b->table('businesses')->insertGetId(['name' => 'Thread race A', 'slug' => $tag.'-a', 'created_at' => now(), 'updated_at' => now()]);
        $this->raceB = (int) $b->table('businesses')->insertGetId(['name' => 'Thread race B', 'slug' => $tag.'-b', 'created_at' => now(), 'updated_at' => now()]);
    });

    afterEach(function () {
        DB::setDefaultConnection('pgsql');
        foreach (['race_a', 'race_b'] as $name) {
            $c = DB::connection($name);
            while ($c->transactionLevel() > 0) {
                $c->rollBack();
            }
            $c->statement('SET lock_timeout = 0');
        }
        $b = DB::connection('race_b');
        $b->table('conversations')->whereIn('business_id', [$this->raceA, $this->raceB])->delete();
        $b->table('businesses')->whereIn('id', [$this->raceA, $this->raceB])->delete();
        DB::purge('race_a');
        DB::purge('race_b');
    });

    it('serialises two workers on one business\'s thread but lets another business open the same reference at once', function () {
        $a = DB::connection('race_a');
        $b = DB::connection('race_b');
        $a->beginTransaction();
        $a->table('conversations')->insert(raceThreadRow($this->raceA, 'race-thread'));

        $b->statement("SET lock_timeout = '300ms'");
        // Another business: its own key, so it neither waits on the uncommitted row nor collides with it.
        $b->table('conversations')->insert(raceThreadRow($this->raceB, 'race-thread'));
        // The same business: PostgreSQL makes the second worker wait on the first one's uncommitted key.
        expect(fn () => $b->table('conversations')->insert(raceThreadRow($this->raceA, 'race-thread')))
            ->toThrow(fn (QueryException $e) => expect($e->getCode())->toBe('55P03'));

        $a->commit();
        expect(fn () => $b->table('conversations')->insert(raceThreadRow($this->raceA, 'race-thread')))
            ->toThrow(fn (QueryException $e) => expect($e->getCode())->toBe('23505'));
        expect($b->table('conversations')->where('external_thread_ref', 'race-thread')->where('business_id', $this->raceA)->count())->toBe(1)
            ->and($b->table('conversations')->where('external_thread_ref', 'race-thread')->count())->toBe(2);
    });

    it('gives the losing worker the winner\'s thread when the winner commits between its lookup and its insert', function () {
        $winner = null;
        $fired = false;
        Event::listen(QueryExecuted::class, function (QueryExecuted $q) use (&$fired, &$winner) {
            if (! $fired && $q->connectionName === 'race_a' && str_starts_with(strtolower(ltrim($q->sql)), 'select') && in_array('race-window', $q->bindings, true)) {
                $fired = true;
                $row = raceThreadRow($this->raceA, 'race-window');
                DB::connection('race_b')->table('conversations')->insert($row);
                $winner = $row['uuid'];
            }
        });

        DB::connection('race_a')->beginTransaction();
        DB::setDefaultConnection('race_a');
        try {
            $thread = app(ConversationService::class)->openOrFind($this->raceA, Channel::Messenger, 'race-window');
        } finally {
            DB::setDefaultConnection('pgsql');
        }

        expect($fired)->toBeTrue()
            ->and($thread->uuid)->toBe($winner)
            ->and(DB::connection('race_a')->table('conversations')->where('external_thread_ref', 'race-window')->count())->toBe(1);
    });

    it('never hands a worker another business\'s thread when it loses a race on the same reference', function () {
        $fired = false;
        Event::listen(QueryExecuted::class, function (QueryExecuted $q) use (&$fired) {
            if (! $fired && $q->connectionName === 'race_a' && str_starts_with(strtolower(ltrim($q->sql)), 'select') && in_array('race-other', $q->bindings, true)) {
                $fired = true;
                DB::connection('race_b')->table('conversations')->insert(raceThreadRow($this->raceB, 'race-other'));
            }
        });

        DB::connection('race_a')->beginTransaction();
        DB::setDefaultConnection('race_a');
        try {
            $thread = app(ConversationService::class)->openOrFind($this->raceA, Channel::Messenger, 'race-other');
        } finally {
            DB::setDefaultConnection('pgsql');
        }

        expect($fired)->toBeTrue()
            ->and($thread->business_id)->toBe($this->raceA)
            ->and(DB::connection('race_a')->table('conversations')->where('external_thread_ref', 'race-other')->pluck('business_id')->sort()->values()->all())
            ->toBe([$this->raceA, $this->raceB]);
    });
});
