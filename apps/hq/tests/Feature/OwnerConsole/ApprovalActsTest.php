<?php

use App\Gateway\Descriptor\RiskTier;
use App\Gateway\Registry\ToolAvailability;
use App\Gateway\ToolGateway;
use App\Gateway\ToolRequest;
use App\Models\Approval;
use App\Models\ApprovalBatch;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\FeatureFlag;
use App\Models\UserBusinessScope;
use App\OwnerConsole\Batch\ApprovalActs;
use App\OwnerConsole\Flag;
use App\OwnerConsole\Refused;
use App\Tools\ToolRegistry;
use Database\Seeders\DomainSeeder;
use Tests\Support\Tools\CustomerMessageTool;

/*
 | docs/143 B and C: batch review of customer messages and one-click approval. Each message is its own decision with its
 | own hash, scope check, gateway pre-check and audit row; a failed check removes that message only.
 */

/** Parks one customer message approval for a business and returns it. */
function ocMessage(string $text, $user, ?int $business = null): Approval
{
    $outcome = app(ToolGateway::class)->execute(ToolRequest::forOperator($user, 'test.customer_message', ['text' => $text], $business));
    expect($outcome->status->pending())->toBeTrue();

    return Approval::findOrFail($outcome->approvalId);
}

beforeEach(function () {
    $this->seed(DomainSeeder::class);
    CustomerMessageTool::$sent = [];
    CustomerMessageTool::$requesterMayDecide = true;
    CustomerMessageTool::$tier = RiskTier::R4;
    app(ToolRegistry::class)->register(CustomerMessageTool::class);
    app(ToolAvailability::class)->forget();
    FeatureFlag::query()->whereIn('key', [Flag::BATCH_APPROVAL, Flag::ONE_CLICK_APPROVE])->update(['enabled' => true]);
    $this->asker = $this->hqUser('owner');
    $this->owner = $this->hqUser('owner');
    $this->lld = Brand::where('code', 'LLD')->sole()->business_id;
    $this->bnc = Brand::where('code', 'BNC')->sole()->business_id;
    $this->acts = app(ApprovalActs::class);
});

it('decides each message on its own and sends only the ones whose checks pass', function () {
    $a = ocMessage('first', $this->asker, $this->lld);
    $b = ocMessage('second', $this->asker, $this->lld);
    $c = ocMessage('third', $this->asker, $this->lld);

    $batch = $this->acts->batch($this->owner, [
        ['id' => $a->id, 'payload_hash' => $a->payload_hash],
        ['id' => $b->id, 'payload_hash' => 'not-what-was-shown'],
        ['id' => $c->id, 'payload_hash' => $c->payload_hash],
    ]);

    expect($batch)->toBeInstanceOf(ApprovalBatch::class)->and($batch->executed_count)->toBe(2)->and($batch->refused_count)->toBe(1)
        ->and(collect($batch->results)->pluck('result', 'id')->all())->toBe([$a->id => 'executed', $b->id => 'payload_changed', $c->id => 'executed'])
        ->and(CustomerMessageTool::$sent)->toBe(['first', 'third'])
        ->and($b->fresh()->status->value)->toBe('pending')
        ->and(AuditLog::where('action', 'approvals.batch_item')->count())->toBe(3)
        ->and(AuditLog::where('action', 'approvals.batch')->count())->toBe(1);
});

it('never approves a sibling: a regenerated variant has its own hash and stays pending', function () {
    $a = ocMessage('hello', $this->asker, $this->lld);
    $variant = ocMessage('hello there', $this->asker, $this->lld);

    $this->acts->batch($this->owner, [['id' => $a->id, 'payload_hash' => $a->payload_hash]]);

    expect(CustomerMessageTool::$sent)->toBe(['hello'])->and($variant->fresh()->status->value)->toBe('pending');
    $stale = $this->acts->batch($this->owner, [['id' => $variant->id, 'payload_hash' => $a->payload_hash]]);
    expect($stale->results[0]['result'])->toBe('payload_changed')->and(CustomerMessageTool::$sent)->toBe(['hello']);
});

it('reports an approval of another business as not found and leaves it untouched', function () {
    $restricted = $this->hqUser('operator');
    UserBusinessScope::create(['user_id' => $restricted->id, 'business_id' => $this->lld]);
    $mine = ocMessage('mine', $this->asker, $this->lld);
    $theirs = ocMessage('theirs', $this->asker, $this->bnc);

    $batch = $this->acts->batch($restricted, [['id' => $mine->id, 'payload_hash' => $mine->payload_hash], ['id' => $theirs->id, 'payload_hash' => $theirs->payload_hash]]);

    expect(collect($batch->results)->pluck('result', 'id')->all())->toBe([$mine->id => 'executed', $theirs->id => 'not_found'])
        ->and($theirs->fresh()->status->value)->toBe('pending')->and(CustomerMessageTool::$sent)->toBe(['mine']);
});

it('refuses a non-customer tool in a batch, and the person who asked when the tool bars self-approval', function () {
    $demo = Approval::findOrFail(app(ToolGateway::class)->execute(ToolRequest::forOperator($this->asker, 'demo.request_approval', ['note' => 'x']))->approvalId);
    CustomerMessageTool::$requesterMayDecide = false;
    $own = ocMessage('own', $this->asker, $this->lld);

    $batch = $this->acts->batch($this->asker, [['id' => $demo->id, 'payload_hash' => $demo->payload_hash], ['id' => $own->id, 'payload_hash' => $own->payload_hash]]);

    expect(collect($batch->results)->pluck('result', 'id')->all())->toBe([$demo->id => 'not_eligible', $own->id => 'refused'])
        ->and(CustomerMessageTool::$sent)->toBe([])->and($own->fresh()->status->value)->toBe('pending');
});

it('needs the decide permission on the route and bounds the batch', function () {
    $this->actingAsHq($this->hqUser('finance_viewer'))->post('/approvals/batch', ['items' => [['id' => 1, 'payload_hash' => 'x']]])->assertForbidden();
    $this->actingAsHq($this->owner)->post('/approvals/batch', ['items' => []])->assertSessionHasErrors('items');
    expect(fn () => $this->acts->batch($this->owner, array_fill(0, ApprovalActs::MAX_BATCH + 1, ['id' => 1, 'payload_hash' => 'x'])))->toThrow(Refused::class);

    $a = ocMessage('via route', $this->asker, $this->lld);
    $this->actingAsHq($this->owner)->post('/approvals/batch', ['items' => [['id' => $a->id, 'payload_hash' => $a->payload_hash]]])->assertRedirect();
    expect(CustomerMessageTool::$sent)->toBe(['via route']);
});

it('lists only pending customer messages the person may see on the review page', function () {
    $restricted = $this->hqUser('operator');
    UserBusinessScope::create(['user_id' => $restricted->id, 'business_id' => $this->lld]);
    ocMessage('visible', $this->asker, $this->lld);
    ocMessage('hidden', $this->asker, $this->bnc);

    $this->actingAsHq($restricted)->get('/approvals/batch')->assertOk()->assertInertia(fn ($page) => $page->where('enabled', true)->has('messages', 1));
});

it('approves one card with the shown hash through the same guards, and refuses a wrong hash or a stale click', function () {
    $a = ocMessage('card', $this->asker, $this->lld);

    $wrong = $this->acts->oneClick($this->owner, $a->id, 'stale');
    expect($wrong['result'])->toBe('payload_changed')->and(CustomerMessageTool::$sent)->toBe([]);

    $ok = $this->acts->oneClick($this->owner, $a->id, $a->payload_hash);
    expect($ok['result'])->toBe('executed')->and(CustomerMessageTool::$sent)->toBe(['card']);
    expect($this->acts->oneClick($this->owner, $a->id, $a->payload_hash)['result'])->toBe('not_pending')->and(CustomerMessageTool::$sent)->toBe(['card']);
});

it('keeps separation of duties and the permission on one click, and sends a material action to its own page', function () {
    CustomerMessageTool::$requesterMayDecide = false;
    $own = ocMessage('mine', $this->asker, $this->lld);
    expect($this->acts->oneClick($this->asker, $own->id, $own->payload_hash)['result'])->toBe('refused')->and(CustomerMessageTool::$sent)->toBe([]);

    $nobody = $this->hqUser('finance_viewer');
    expect($this->acts->oneClick($nobody, $own->id, $own->payload_hash)['result'])->toBe('refused');

    CustomerMessageTool::$tier = RiskTier::R5;
    $heavy = ocMessage('heavy', $this->asker, $this->lld);
    expect($this->acts->oneClick($this->owner, $heavy->id, $heavy->payload_hash)['result'])->toBe('not_eligible');
});

it('adds the payload and hash to a console card only when the flag is on, below R5 and decidable', function () {
    $a = ocMessage('card', $this->asker, $this->lld);
    $this->actingAsHq($this->owner)->get('/dashboard')->assertInertia(fn ($page) => $page->where('console.decisions.cards.0.quick.hash', $a->payload_hash));

    FeatureFlag::query()->where('key', Flag::ONE_CLICK_APPROVE)->update(['enabled' => false]);
    $this->actingAsHq($this->owner)->get('/dashboard')->assertInertia(fn ($page) => $page->where('console.decisions.cards.0.quick', null));
});

it('posts one click through the route and shows the result as a form error when refused', function () {
    $a = ocMessage('route', $this->asker, $this->lld);

    $this->actingAsHq($this->owner)->post("/approvals/{$a->id}/quick-approve", ['payload_hash' => 'bad'])->assertSessionHasErrors('approval');
    $this->actingAsHq($this->owner)->post("/approvals/{$a->id}/quick-approve", ['payload_hash' => $a->payload_hash])->assertSessionHasNoErrors();
    expect(CustomerMessageTool::$sent)->toBe(['route']);
});
