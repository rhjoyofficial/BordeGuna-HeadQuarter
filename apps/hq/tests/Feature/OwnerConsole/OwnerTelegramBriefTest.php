<?php

use App\Gateway\Approval\ApprovalExecutor;
use App\Gateway\Registry\ToolAvailability;
use App\Gateway\ToolGateway;
use App\Gateway\ToolRequest;
use App\Models\Approval;
use App\Models\Brand;
use App\Models\Briefing;
use App\Models\FeatureFlag;
use App\Models\OwnerBriefDelivery;
use App\Models\UserBusinessScope;
use App\OwnerConsole\Brief\BriefMessage;
use App\OwnerConsole\Brief\FakeOwnerChannel;
use App\OwnerConsole\Brief\OwnerChannel;
use App\OwnerConsole\Brief\OwnerSend;
use App\OwnerConsole\Brief\TelegramOwnerChannel;
use App\OwnerConsole\Brief\UnavailableOwnerChannel;
use App\OwnerConsole\Flag;
use Database\Seeders\DomainSeeder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/*
 | docs/143 D (docs/138 ruling 10): the morning brief to the owner's own Telegram. A gateway tool at R4 (approval),
 | flag OFF, destination never an argument, a minimised text built only from the stored briefing.
 */

function ocBriefing(?int $business, array $over = []): Briefing
{
    return Briefing::create($over + [
        'uuid' => (string) Str::uuid(), 'capability' => 'executive.briefing', 'capability_version' => '1.0.0', 'bundle_version' => '1',
        'briefing_date' => now(config('hq.timezone'))->toDateString(), 'timezone' => 'Asia/Dhaka', 'business_id' => $business,
        'scope_business_ids' => null, 'state' => 'complete', 'degraded_reasons' => [],
        'bundle' => ['freshness' => ['stale' => ['orders:lld'], 'missing' => []], 'items' => [
            ['id' => 'yesterday.placed', 'label' => 'Orders placed', 'value' => 12, 'unit' => 'orders', 'scope' => ['business' => 'lld', 'store' => null]],
            ['id' => 'yesterday.store', 'label' => 'Store row', 'value' => 5, 'unit' => 'orders', 'scope' => ['business' => 'lld', 'store' => 'shop']],
            ['id' => 'customer.phone', 'label' => 'Customer', 'value' => '01700000000', 'unit' => 'text', 'scope' => ['business' => 'lld', 'store' => null]],
        ]],
        'bundle_digest' => 'x', 'validation' => [], 'narration_state' => 'off', 'narrative' => null, 'narrative_check' => null,
        'idempotency_key' => 'k-'.Str::uuid(), 'generated_at' => now(),
    ]);
}

beforeEach(function () {
    $this->seed(DomainSeeder::class);
    FeatureFlag::query()->where('key', Flag::TELEGRAM_BRIEF)->update(['enabled' => true]);
    $this->fake = new FakeOwnerChannel;
    app()->instance(OwnerChannel::class, $this->fake);
    app(ToolAvailability::class)->forget();
    $this->asker = $this->hqUser('owner');
    $this->approver = $this->hqUser('owner');
    $this->lld = Brand::where('code', 'LLD')->sole()->business;
    $this->briefing = ocBriefing($this->lld->id);
    $this->args = ['business_slug' => $this->lld->slug, 'date' => $this->briefing->briefing_date->toDateString()];
});

it('ships with no owner channel: the default can send nothing', function () {
    expect((new UnavailableOwnerChannel)->send('x')->ok)->toBeFalse()->and(config('hq.owner_console.driver'))->toBe('none');
});

it('parks the send on an approval and sends nothing until a person approves, then sends the minimised brief once', function () {
    $outcome = app(ToolGateway::class)->execute(ToolRequest::forOperator($this->asker, 'briefing.send_owner_telegram', $this->args, $this->lld->id));
    expect($outcome->status->pending())->toBeTrue()->and($this->fake->sent)->toBe([]);

    $done = app(ApprovalExecutor::class)->approveAndExecute(Approval::findOrFail($outcome->approvalId), $this->approver);
    expect($done->ok())->toBeTrue()->and($this->fake->sent)->toHaveCount(1);

    $text = $this->fake->sent[0];
    expect($text)->toContain('Orders placed: 12 orders')->and($text)->toContain('Stale sources: orders:lld')
        ->and($text)->not->toContain('Store row')->and($text)->not->toContain('01700000000')->and($text)->not->toContain('Customer');

    $row = OwnerBriefDelivery::firstOrFail();
    expect($row->state)->toBe('sent')->and($row->destination)->toBe('owner_chat')->and($row->message_digest)->toBe(hash('sha256', $text));

    // One delivery per briefing: a second approved call replays and sends nothing.
    $second = app(ToolGateway::class)->execute(ToolRequest::forOperator($this->asker, 'briefing.send_owner_telegram', $this->args, $this->lld->id));
    app(ApprovalExecutor::class)->approveAndExecute(Approval::findOrFail($second->approvalId), $this->approver);
    expect($this->fake->sent)->toHaveCount(1)->and(OwnerBriefDelivery::count())->toBe(1);
});

it('has no destination argument: a chat id passed as an argument is refused as an unknown field', function () {
    $outcome = app(ToolGateway::class)->execute(ToolRequest::forOperator($this->asker, 'briefing.send_owner_telegram', $this->args + ['chat_id' => '12345678'], $this->lld->id));

    expect($outcome->status->value)->toBe('invalid')->and($this->fake->sent)->toBe([])->and(Approval::count())->toBe(0);
});

it('is refused for a person without hq.briefing.deliver, and is neither agent-executable nor below R4', function () {
    $nobody = $this->hqUser('brand_manager');
    $outcome = app(ToolGateway::class)->execute(ToolRequest::forOperator($nobody, 'briefing.send_owner_telegram', $this->args, $this->lld->id));
    expect($outcome->status->pending())->toBeFalse()->and($this->fake->sent)->toBe([]);

    $descriptor = app(ToolGateway::class)->descriptors()['briefing.send_owner_telegram'];
    expect($descriptor->agentExecutable)->toBeFalse()->and($descriptor->riskTier->value)->toBe('r4');
});

it('fails before reading anything while the flag is off', function () {
    FeatureFlag::query()->where('key', Flag::TELEGRAM_BRIEF)->update(['enabled' => false]);
    $outcome = app(ToolGateway::class)->execute(ToolRequest::forOperator($this->asker, 'briefing.send_owner_telegram', $this->args, $this->lld->id));
    $done = app(ApprovalExecutor::class)->approveAndExecute(Approval::findOrFail($outcome->approvalId), $this->approver);

    expect($done->ok())->toBeFalse()->and($this->fake->sent)->toBe([])->and(OwnerBriefDelivery::count())->toBe(0);
});

it('refuses at request time a business the person may not act for, and has no group briefing tool in v1', function () {
    $restricted = $this->hqUser('operator');
    UserBusinessScope::create(['user_id' => $restricted->id, 'business_id' => $this->lld->id]);
    $bnc = Brand::where('code', 'BNC')->sole()->business;
    ocBriefing($bnc->id);
    ocBriefing(null);

    foreach ([['business_slug' => $bnc->slug], ['date' => $this->args['date']]] as $args) {
        $outcome = app(ToolGateway::class)->execute(ToolRequest::forOperator($restricted, 'briefing.send_owner_telegram', $args, null));
        expect($outcome->status->pending())->toBeFalse();
    }
    expect(Approval::count())->toBe(0)->and($this->fake->sent)->toBe([]);

    $own = app(ToolGateway::class)->execute(ToolRequest::forOperator($restricted, 'briefing.send_owner_telegram', $this->args, $this->lld->id));
    expect($own->status->pending())->toBeTrue();
});

it('uses the narrative only when the stored unsourced-number check passed with every figure sourced', function () {
    $good = ocBriefing($this->lld->id, ['narration_state' => 'narrated', 'narrative' => 'Twelve orders came in.', 'narrative_check' => ['passed' => true, 'figures' => [['figure' => '12', 'sourced' => true]]]]);
    $bad = ocBriefing($this->lld->id, ['narration_state' => 'narrated', 'narrative' => 'Fifty orders came in.', 'narrative_check' => ['passed' => true, 'figures' => [['figure' => '50', 'sourced' => false]]]]);
    $failed = ocBriefing($this->lld->id, ['narration_state' => 'rejected', 'narrative' => null, 'narrative_check' => ['passed' => false]]);

    expect(BriefMessage::build($good, 'LLD'))->toContain('Twelve orders came in.')
        ->and(BriefMessage::build($bad, 'LLD'))->not->toContain('Fifty orders')->and(BriefMessage::build($bad, 'LLD'))->toContain('Orders placed: 12')
        ->and(BriefMessage::build($failed, 'LLD'))->toContain('Orders placed: 12');
});

it('validates the Telegram destination: only the owner private chat the owner confirmed, never a group', function () {
    config(['hq.owner_console.telegram.bot_token' => 'test-token', 'hq.owner_console.telegram.chat_id' => '123456789', 'hq.owner_console.telegram.chat_verified' => false]);
    expect(TelegramOwnerChannel::destinationRefusal())->toContain('not been confirmed');

    foreach (['-1001234567890', 'abc', '12', ''] as $bad) {
        config(['hq.owner_console.telegram.chat_id' => $bad, 'hq.owner_console.telegram.chat_verified' => true]);
        expect(TelegramOwnerChannel::destinationRefusal())->not->toBeNull("{$bad} is refused");
    }
    config(['hq.owner_console.telegram.chat_id' => '123456789', 'hq.owner_console.telegram.bot_token' => '']);
    expect(TelegramOwnerChannel::destinationRefusal())->toContain('token');
});

it('sends to the configured chat only, reports uncertain on a timeout and never leaks the token', function () {
    config(['hq.owner_console.telegram.bot_token' => 'secret-token-xyz', 'hq.owner_console.telegram.chat_id' => '123456789', 'hq.owner_console.telegram.chat_verified' => true]);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 77]], 200)]);

    $sent = (new TelegramOwnerChannel)->send('hello owner');
    expect($sent->ok)->toBeTrue()->and($sent->ref)->toBe('77');
    Http::assertSent(fn ($r) => $r['chat_id'] === '123456789' && $r['text'] === 'hello owner' && str_contains($r->url(), 'sendMessage'));

    Http::swap(new Factory);
    Http::fake(['api.telegram.org/*' => fn () => throw new ConnectionException('timeout with secret-token-xyz')]);
    $unsure = (new TelegramOwnerChannel)->send('again');
    expect($unsure->uncertain)->toBeTrue()->and($unsure->ok)->toBeFalse()->and((string) $unsure->error)->not->toContain('secret-token-xyz');

    Http::swap(new Factory);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false], 403)]);
    $refused = (new TelegramOwnerChannel)->send('x');
    expect($refused->error)->toContain('403')->and($refused->error)->not->toContain('secret-token-xyz');
});

it('records an uncertain send honestly and does not call it delivered', function () {
    $this->fake->next = OwnerSend::uncertain('unknown');
    $outcome = app(ToolGateway::class)->execute(ToolRequest::forOperator($this->asker, 'briefing.send_owner_telegram', $this->args, $this->lld->id));
    $done = app(ApprovalExecutor::class)->approveAndExecute(Approval::findOrFail($outcome->approvalId), $this->approver);

    expect($done->ok())->toBeFalse()->and(OwnerBriefDelivery::firstOrFail()->state)->toBe('uncertain');
});

it('lets the briefing screen only ask: the route parks an approval and sends nothing', function () {
    $this->actingAsHq($this->asker)->post("/observe/briefings/{$this->briefing->uuid}/send-telegram")->assertRedirect()->assertSessionHas('status', 'briefing-send-awaiting-approval');
    expect($this->fake->sent)->toBe([])->and(Approval::where('tool_key', 'briefing.send_owner_telegram')->count())->toBe(1);

    $this->actingAsHq($this->hqUser('brand_manager'))->post("/observe/briefings/{$this->briefing->uuid}/send-telegram")->assertForbidden();
});
