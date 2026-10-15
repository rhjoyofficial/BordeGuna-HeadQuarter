<?php

use App\Consent\ConsentBasis;
use App\Consent\ConsentLedger;
use App\Consent\ConsentPurpose;
use App\Consent\ConsentState;
use App\FollowUps\FollowUpKind;
use App\FollowUps\FollowUpOrigin;
use App\FollowUps\FollowUpProposal;
use App\FollowUps\FollowUpProposalService;
use App\FollowUps\FollowUpState;
use App\FollowUps\Proposer;
use App\Gateway\Descriptor\Channel;
use App\Models\Alert;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\FollowUp;
use App\Models\User;
use App\Observe\Alerts\AlertEngine;
use App\Observe\Alerts\Rules\FollowUpsOverdue;
use App\Support\Navigation\NavBadges;
use Carbon\CarbonImmutable;
use Database\Seeders\DomainSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
 | docs/120 item 16, docs/123 section 3: the documented overdue rule. A follow-up still scheduled a grace
 | period after its due time raises `followups.overdue`, addressed to the person responsible for it (who
 | approved it) and to nobody else: an in-HQ record naming them and their own sidebar count, never the
 | shared Telegram chat, never a customer, never a change to the follow-up or its run.
 */

beforeEach(function () {
    $this->seed(DomainSeeder::class);
    $this->lld = Brand::where('code', 'LLD')->sole();
    $this->approver = $this->hqUser('operator');
    $this->other = $this->hqUser('owner');
    $this->customer = Customer::create(['business_id' => $this->lld->business_id, 'name' => 'Rahima Overdue', 'primary_phone_canonical' => '01711000444']);
    app(ConsentLedger::class)->record($this->customer->id, $this->customer->business_id, ConsentPurpose::Service, ConsentState::Granted,
        ConsentBasis::Operator, 'whatsapp', now()->subDays(10), ['evidence_ref' => 'test', 'actor_type' => 'human', 'actor_label' => 'test']);
    // Telegram is configured, so a broadcast would be visible: the overdue alert must never use it.
    config(['hq.alerts.telegram_bot_token' => 'bot-token-under-test', 'hq.alerts.telegram_chat_id' => '-100123']);
    Http::fake();
});

function ovdScheduled(object $t, CarbonImmutable $due, ?User $approver = null): FollowUp
{
    $service = app(FollowUpProposalService::class);
    $f = $service->proposeFollowUp(Proposer::person($t->other), new FollowUpProposal(
        customer: $t->customer, kind: FollowUpKind::Service, channel: Channel::Whatsapp, dueAt: $due,
        intent: 'SECRET INTENT about the pickle jar', origin: FollowUpOrigin::Promise,
    ));

    return $service->approveFollowUp($f, $approver ?? $t->approver);
}

/** Every table a customer-facing effect or a follow-up move would change. */
function ovdSnapshot(): array
{
    return collect(['tool_calls', 'conversation_messages', 'message_deliveries', 'scheduled_jobs', 'approvals', 'consent_events', 'outcome_attributions'])
        ->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all()
        + ['follow_ups' => DB::table('follow_ups')->orderBy('id')->get(['id', 'state', 'updated_at'])->toJson(),
            'workflow_instances' => DB::table('workflow_instances')->orderBy('id')->get(['id', 'status', 'current_step', 'updated_at'])->toJson(),
            'workflow_signals' => DB::table('workflow_signals')->count()];
}

it('is listed beside the other alert rules and is never paged on the shared channel', function () {
    expect(config('hq.alerts.rules'))->toContain(FollowUpsOverdue::class)->toHaveCount(17)
        ->and(app(FollowUpsOverdue::class)->key())->toBe('followups.overdue')
        ->and(app(FollowUpsOverdue::class)->pagedBy())->not->toBeNull()
        ->and(config('hq.followups.overdue_after_hours'))->toBe(24);
});

it('tells only the responsible person, in HQ, and changes nothing else', function () {
    $f = ovdScheduled($this, CarbonImmutable::now()->subDays(2));
    $notYet = ovdScheduled($this, CarbonImmutable::now()->subHours(2));
    $before = ovdSnapshot();

    app(AlertEngine::class)->check(only: ['followups.overdue']);

    $alert = Alert::open()->where('rule', 'followups.overdue')->sole();
    expect($alert->fingerprint)->toBe('followups.overdue:person:'.$this->approver->id)
        ->and($alert->context['count'])->toBe(1)
        ->and($alert->context['recipient_user_id'])->toBe($this->approver->id)
        ->and($alert->paged_by)->not->toBeNull()
        ->and($alert->notified_at)->not->toBeNull();

    // One in-HQ notification, addressed to the approver; nothing on Telegram.
    $notes = AuditLog::where('action', 'notification.raised')->get()->filter(fn ($a) => ($a->after['rule'] ?? null) === 'followups.overdue');
    expect($notes)->toHaveCount(1)
        ->and($notes->first()->after['recipient_user_id'])->toBe($this->approver->id)
        ->and($notes->first()->after['channel'])->toBe('in_app');
    Http::assertNothingSent();

    // No customer data in what a person reads.
    foreach ([$alert->subject, $alert->body, json_encode($notes->first()->after)] as $text) {
        expect($text)->not->toContain('Rahima')->not->toContain('01711000444')->not->toContain('SECRET INTENT');
    }

    // Each person's sidebar shows their own count only.
    expect(app(NavBadges::class)->for($this->approver)['followups'])->toBe(1)
        ->and(app(NavBadges::class)->for($this->other)['followups'])->toBe(0)
        ->and(app(NavBadges::class)->for($this->hqUser('finance_viewer')))->not->toHaveKey('followups');

    // Nothing moved, nothing sent, nothing signalled: the alert informs, it never acts.
    expect(ovdSnapshot())->toBe($before)
        ->and($f->fresh()->state)->toBe(FollowUpState::Scheduled)
        ->and($notYet->fresh()->state)->toBe(FollowUpState::Scheduled);

    // A second check counts the same alert, and tells nobody again.
    app(AlertEngine::class)->check(only: ['followups.overdue']);
    expect(Alert::where('rule', 'followups.overdue')->count())->toBe(1)
        ->and(AuditLog::where('action', 'notification.raised')->get()->filter(fn ($a) => ($a->after['rule'] ?? null) === 'followups.overdue'))->toHaveCount(1);
});

it('clears when the person completes the follow-up, and tells that person it cleared', function () {
    $f = ovdScheduled($this, CarbonImmutable::now()->subDays(3));
    app(AlertEngine::class)->check(only: ['followups.overdue']);

    app(FollowUpProposalService::class)->completeFollowUp($f, $this->approver, 'Called her today.');
    app(AlertEngine::class)->check(only: ['followups.overdue']);
    app(AlertEngine::class)->check(only: ['followups.overdue']);

    expect(Alert::open()->where('rule', 'followups.overdue')->count())->toBe(0);
    $cleared = AuditLog::where('action', 'notification.raised')->get()->filter(fn ($a) => str_starts_with((string) ($a->after['subject'] ?? ''), 'HQ alert cleared'));
    expect($cleared)->toHaveCount(1)->and($cleared->first()->after['recipient_user_id'])->toBe($this->approver->id);
    Http::assertNothingSent();
});

it('raises one unaddressed condition when the approver can no longer act, and tells no one in particular', function () {
    ovdScheduled($this, CarbonImmutable::now()->subDays(2));
    $this->approver->forceFill(['is_active' => false])->save();

    app(AlertEngine::class)->check(only: ['followups.overdue']);

    $alert = Alert::open()->where('rule', 'followups.overdue')->sole();
    expect($alert->fingerprint)->toBe('followups.overdue:unassigned')
        ->and($alert->context)->not->toHaveKey('recipient_user_id')
        ->and($alert->notified_at)->toBeNull()
        ->and(AuditLog::where('action', 'notification.raised')->count())->toBe(0);
    Http::assertNothingSent();
});
