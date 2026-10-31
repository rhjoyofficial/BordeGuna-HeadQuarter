<?php

use App\Conversation\Channels\SimulatedChannelAdapter;
use App\Conversation\ConversationService;
use App\Gateway\Descriptor\AutonomyLevel;
use App\Gateway\Registry\ToolAvailability;
use App\Models\AgentRun;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Conversation;
use App\Models\DecisionRecord;
use App\Models\FeatureFlag;
use App\Models\PlatformAccount;
use App\Models\ReplyOptionSet;
use App\Models\ToolCall;
use App\Models\UserBusinessScope;
use App\OwnerConsole\Flag;
use App\OwnerConsole\Refused;
use App\OwnerConsole\Replies\ReplyOptionService;
use App\Tools\ToolRegistry;
use Database\Seeders\DomainSeeder;
use Illuminate\Support\Str;
use Tests\Support\Tools\RecordingSendReplyTool;
use Tests\Support\Tools\ScriptCheckedSendReplyTool;

/*
 | docs/143 E (docs/138 ruling 11): Support proposes up to three replies; a person picks one and may edit it; only that final
 | text goes to the gateway, once. Flag OFF in production.
 */

function ocThread(string $psid = 'psid-oc'): Conversation
{
    PlatformAccount::updateOrCreate(
        ['platform' => 'facebook_page', 'account_ref' => SimulatedChannelAdapter::DEFAULT_PAGE],
        ['brand_id' => Brand::where('code', 'LLD')->sole()->id, 'name' => 'Simulated', 'approval_status' => 'unknown', 'is_active' => true],
    );
    $conversation = null;
    foreach ((new SimulatedChannelAdapter)->normalise(['messages' => [['from' => $psid, 'text' => 'Where is my order?', 'mid' => 'm-'.Str::uuid()]]]) as $inbound) {
        $conversation = app(ConversationService::class)->ingest($inbound)->conversation;
    }

    return $conversation->fresh();
}

function ocRun(Conversation $conversation, array $texts): AgentRun
{
    return AgentRun::create([
        'uuid' => (string) Str::uuid(), 'agent_key' => 'support', 'correlation_id' => $conversation->uuid, 'trigger' => 'conversation',
        'business_id' => $conversation->business_id, 'question' => 'Answer the customer.', 'autonomy_level' => AutonomyLevel::A0->value,
        'autonomy_reason' => 'no grant', 'shadow' => true, 'status' => 'answered', 'answer' => 'Proposed.', 'evidence' => [],
        'proposals' => array_map(fn ($t) => ['kind' => 'proposal', 'tool' => 'messaging.send_reply', 'args' => ['conversation_uuid' => $conversation->uuid, 'text' => $t], 'why' => 'w', 'reason' => 'A0'], $texts),
        'context_provenance' => [], 'turns' => 1, 'tool_calls_count' => count($texts), 'started_at' => now(), 'finished_at' => now(),
    ]);
}

/** Swaps the real send tool for a recording one under the same key, so the gateway really runs. */
function ocSendTool(bool $succeeds): void
{
    RecordingSendReplyTool::$sent = [];
    RecordingSendReplyTool::$succeeds = $succeeds;
    app(ToolRegistry::class)->register(RecordingSendReplyTool::class);
    app(ToolAvailability::class)->forget();
}

beforeEach(function () {
    $this->seed(DomainSeeder::class);
    FeatureFlag::query()->where('key', Flag::REPLY_OPTIONS)->update(['enabled' => true]);
    $this->owner = $this->hqUser('owner');
    $this->thread = ocThread();
    // The thread must be answerable: link a customer with consent, as the inbox tests do through the simulator.
});

it('turns a Support run proposals into at most three labelled options and sends nothing', function () {
    ocSendTool(true);
    $set = app(ReplyOptionService::class)->propose($this->thread, ['One', 'Two', 'Three'], ocRun($this->thread, ['One']));

    expect(collect($set->options)->pluck('label')->all())->toBe(['A', 'B', 'C'])->and($set->state)->toBe('open')->and(RecordingSendReplyTool::$sent)->toBe([]);
    expect(fn () => app(ReplyOptionService::class)->propose($this->thread, ['1', '2', '3', '4']))->toThrow(Refused::class, 'between 1 and 3');
    expect(fn () => app(ReplyOptionService::class)->propose($this->thread, ['', '  ']))->toThrow(Refused::class);
});

it('sends only the chosen option, as edited, once, as that person, and records the pick', function () {
    ocSendTool(true);
    $service = app(ReplyOptionService::class);
    $set = $service->propose($this->thread, ['Alpha reply', 'Beta reply', 'Gamma reply']);

    $done = $service->choose($set, 'B', 'Beta reply, edited by me', $this->owner);

    expect($done->state)->toBe('chosen')->and($done->chosen_label)->toBe('B')->and(RecordingSendReplyTool::$sent)->toBe(['Beta reply, edited by me'])
        ->and(ToolCall::where('tool_key', 'messaging.send_reply')->where('actor_id', $this->owner->id)->count())->toBe(1);
    $record = DecisionRecord::latest('id')->first();
    expect($record->final['label'])->toBe('B')->and($record->final['edited'])->toBeTrue()->and($record->delta['from'])->toBe('Beta reply');

    expect(fn () => $service->choose($set, 'A', null, $this->owner))->toThrow(Refused::class, 'already decided');
    expect(RecordingSendReplyTool::$sent)->toHaveCount(1);
    expect(AuditLog::where('action', 'reply_options.chosen')->count())->toBe(1);
});

it('puts the set back to open when the send fails, so nothing is half-decided, and never sends another label', function () {
    ocSendTool(false);
    $service = app(ReplyOptionService::class);
    $set = $service->propose($this->thread, ['Alpha', 'Beta']);

    $after = $service->choose($set, 'A', null, $this->owner);
    expect($after->state)->toBe('open')->and($after->chosen_label)->toBeNull()->and($after->send_status)->toBe('failed')
        ->and(RecordingSendReplyTool::$sent)->toBe([]);
});

it('rejects an unknown label, an empty edit and a person without hq.inbox.manage', function () {
    ocSendTool(true);
    $service = app(ReplyOptionService::class);
    $set = $service->propose($this->thread, ['Alpha']);

    expect(fn () => $service->choose($set, 'C', null, $this->owner))->toThrow(Refused::class, 'no option C');
    expect(fn () => $service->choose($set, 'A', '   ', $this->owner))->toThrow(Refused::class);
    expect(fn () => $service->choose($set, 'A', null, $this->hqUser('finance_viewer')))->toThrow(Refused::class, 'hq.inbox.manage');
    expect(RecordingSendReplyTool::$sent)->toBe([])->and($set->fresh()->state)->toBe('open');
});

it('is business scoped on the routes: another business thread and its set are a 404', function () {
    ocSendTool(true);
    $set = app(ReplyOptionService::class)->propose($this->thread, ['Alpha']);
    $restricted = $this->hqUser('operator');
    UserBusinessScope::create(['user_id' => $restricted->id, 'business_id' => Brand::where('code', 'BNC')->sole()->business_id]);

    $this->actingAsHq($restricted)->post("/inbox/{$this->thread->uuid}/reply-options/{$set->uuid}/choose", ['label' => 'A'])->assertNotFound();
    $this->actingAsHq($restricted)->post("/inbox/{$this->thread->uuid}/reply-options/{$set->uuid}/discard")->assertNotFound();
    $this->actingAsHq($restricted)->post("/inbox/{$this->thread->uuid}/reply-options", ['run_id' => ocRun($this->thread, ['x'])->id])->assertNotFound();
    expect(RecordingSendReplyTool::$sent)->toBe([])->and($set->fresh()->state)->toBe('open');

    $this->actingAsHq($this->owner)->post("/inbox/{$this->thread->uuid}/reply-options/{$set->uuid}/choose", ['label' => 'A', 'text' => 'Edited'])->assertRedirect();
    expect(RecordingSendReplyTool::$sent)->toBe(['Edited']);
});

it('discards a set so none of it can ever be sent', function () {
    ocSendTool(true);
    $service = app(ReplyOptionService::class);
    $set = $service->discard($service->propose($this->thread, ['Alpha']), $this->owner);

    expect($set->state)->toBe('discarded');
    expect(fn () => $service->choose($set, 'A', null, $this->owner))->toThrow(Refused::class, 'already decided');
    expect(RecordingSendReplyTool::$sent)->toBe([]);
});

it('offers no options for a thread the outbound policy will not answer or a closed one, and none when the flag is off', function () {
    $closed = ocThread('psid-closed');
    $closed->forceFill(['status' => 'closed'])->save();
    expect(fn () => app(ReplyOptionService::class)->propose($closed, ['x']))->toThrow(Refused::class, 'closed');

    FeatureFlag::query()->where('key', Flag::REPLY_OPTIONS)->update(['enabled' => false]);
    expect(fn () => app(ReplyOptionService::class)->propose($this->thread, ['x']))->toThrow(Refused::class, 'switched off');
    expect(ReplyOptionSet::count())->toBe(0);
});

/*
 | ChatGPT ruling 31 Oct 2026, item 4 (approved with conditions).
 */

it('audits the actor, the hash of the exact final text, the time and the result, and never the text', function () {
    ocSendTool(true);
    $service = app(ReplyOptionService::class);
    $set = $service->propose($this->thread, ['Alpha reply']);
    $service->choose($set, 'A', 'Alpha reply, edited', $this->owner);

    $row = AuditLog::where('action', 'reply_options.chosen')->sole();
    expect($row->actor_type)->toBe('human')->and((int) $row->actor_id)->toBe($this->owner->id)
        ->and($row->after['text_sha256'])->toBe(hash('sha256', 'Alpha reply, edited'))
        ->and($row->after['outcome'])->toBe('succeeded')->and($row->after['sent_at'])->not->toBeEmpty()
        ->and($row->occurred_at)->not->toBeNull()
        ->and(json_encode($row->after))->not->toContain('Alpha reply, edited');
});

it('keeps the reply script enforced: an edited option in the wrong script is refused by the gateway and sent to nobody', function () {
    ScriptCheckedSendReplyTool::$sent = [];
    app(ToolRegistry::class)->register(ScriptCheckedSendReplyTool::class);
    app(ToolAvailability::class)->forget();
    $this->thread->forceFill(['language' => 'banglish'])->save();
    $service = app(ReplyOptionService::class);
    $set = $service->propose($this->thread->fresh(), ['দুঃখিত, অর্ডারের অবস্থা এখান থেকে নিশ্চিত করতে পারছি না।']);

    // The person edits it into romanised Bangla: the thread requires Bengali script, so it is refused before execution.
    $after = $service->choose($set, 'A', 'Dukkhoito, order-er obostha ekhane theke nischit korte parchi na.', $this->owner);
    expect($after->state)->toBe('open')->and(ScriptCheckedSendReplyTool::$sent)->toBe([])
        ->and(ToolCall::where('tool_key', 'messaging.send_reply')->latest('id')->value('status'))->not->toBe('succeeded');

    // The option as offered, in the required script, goes through.
    $done = $service->choose($set->fresh(), 'A', null, $this->owner);
    expect($done->state)->toBe('chosen')->and(ScriptCheckedSendReplyTool::$sent)->toBe(['দুঃখিত, অর্ডারের অবস্থা এখান থেকে নিশ্চিত করতে পারছি না।']);
});

it('goes through the gateway as the person, so scope and messaging permission are checked there too', function () {
    ocSendTool(true);
    $set = app(ReplyOptionService::class)->propose($this->thread, ['Alpha']);
    $source = file_get_contents(app_path('OwnerConsole/Replies/ReplyOptionService.php'));

    expect(substr_count($source, '$this->gateway->execute('))->toBe(1)
        ->and($source)->toContain('ActingIdentity::human($by)')
        ->and($source)->not->toMatch('/->get\([^)]*\)->execute\(/');

    app(ReplyOptionService::class)->choose($set, 'A', null, $this->owner);
    $call = ToolCall::where('tool_key', 'messaging.send_reply')->sole();
    expect($call->actor_id)->toBe($this->owner->id)->and((int) $call->business_id)->toBe((int) $this->thread->business_id);
});

it('never sends an alternative by itself: only a person choosing on the inbox route reaches choose()', function () {
    $callers = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() === 'php' && preg_match('/(ReplyOptionService|\$this->options)[^;]*->choose\(|->options->choose\(/', (string) file_get_contents($file->getPathname()))) {
            $callers[] = basename($file->getPathname());
        }
    }
    expect($callers)->toBe(['ReplyOptionController.php']);

    // Proposing three options sends none of them, and choosing one leaves the others unsendable.
    ocSendTool(true);
    $service = app(ReplyOptionService::class);
    $set = $service->propose($this->thread, ['A text', 'B text', 'C text']);
    expect(RecordingSendReplyTool::$sent)->toBe([]);
    $service->choose($set, 'C', null, $this->owner);
    expect(RecordingSendReplyTool::$sent)->toBe(['C text']);
    foreach (['A', 'B'] as $label) {
        expect(fn () => $service->choose($set->fresh(), $label, null, $this->owner))->toThrow(Refused::class);
    }
    expect(RecordingSendReplyTool::$sent)->toBe(['C text']);
});

it('shows the person the recipient beside the exact final text before sending', function () {
    $component = file_get_contents(resource_path('js/components/inbox/reply-options.tsx'));
    $page = file_get_contents(resource_path('js/pages/inbox/show.tsx'));

    expect($component)->toContain('recipient: string')->and($component)->toContain('{recipient}')
        ->and($component)->toContain('text: texts[key] ?? o.text')
        ->and($page)->toContain('recipient={');
});
