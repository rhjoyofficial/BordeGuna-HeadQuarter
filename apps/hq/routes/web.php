<?php

use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\ToolRegistryController;
use App\Http\Controllers\Agents\AgentController;
use App\Http\Controllers\Approvals\ApprovalController;
use App\Http\Controllers\Ask\AskController;
use App\Http\Controllers\Identity\CustomerController;
use App\Http\Controllers\Inbox\InboxController;
use App\Http\Controllers\Observe\BriefingController;
use App\Http\Controllers\Observe\ObserveController;
use App\Http\Controllers\Scheduling\NlScheduleController;
use App\Http\Controllers\Scheduling\ScheduledJobController;
use App\Http\Controllers\Security\SessionsController;
use App\Http\Controllers\Security\TwoFactorChallengeController;
use App\Http\Controllers\Security\TwoFactorController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('dashboard'))->name('home');

// Authenticated but not yet past 2FA: only the challenge and the mandatory setup are reachable.
Route::middleware(['auth'])->group(function () {
    Route::get('two-factor/challenge', [TwoFactorChallengeController::class, 'show'])->name('two-factor.challenge');
    Route::post('two-factor/challenge', [TwoFactorChallengeController::class, 'verify'])->middleware('throttle:10,1')->name('two-factor.challenge.verify');

    Route::middleware(['2fa.verified'])->group(function () {
        Route::get('security/two-factor', [TwoFactorController::class, 'show'])->name('two-factor.setup');
        Route::post('security/two-factor/enable', [TwoFactorController::class, 'enable'])->name('two-factor.enable');
        Route::post('security/two-factor/confirm', [TwoFactorController::class, 'confirm'])->middleware('throttle:10,1')->name('two-factor.confirm');
        Route::post('security/two-factor/recovery-codes', [TwoFactorController::class, 'regenerateRecoveryCodes'])->name('two-factor.recovery');
        Route::delete('security/two-factor', [TwoFactorController::class, 'disable'])->name('two-factor.disable');
    });
});

// Everything else requires password + confirmed 2FA + a verified challenge this session.
Route::middleware(['auth', '2fa.enabled', '2fa.verified'])->group(function () {
    Route::get('dashboard', [AdminController::class, 'dashboard'])->middleware('permission:hq.dashboard.view')->name('dashboard');

    Route::get('settings/sessions', [SessionsController::class, 'index'])->name('sessions.index');
    Route::delete('settings/sessions/others', [SessionsController::class, 'destroyOthers'])->name('sessions.destroy-others');

    Route::prefix('scheduler')->name('scheduler.')->group(function () {
        Route::get('/', [ScheduledJobController::class, 'index'])->middleware('permission:hq.jobs.view')->name('index');
        Route::post('/', [ScheduledJobController::class, 'store'])->middleware('permission:hq.jobs.manage')->name('store');
        // Phase 2 M6, inert (docs/48 L, docs/78 B.3): a job described in words, behind the flag `scheduler.nl`.
        // Registered before `{job}` so the word `nl` is never read as a job id.
        Route::get('nl', [NlScheduleController::class, 'create'])->middleware('permission:hq.jobs.manage')->name('nl.create');
        Route::post('nl/preview', [NlScheduleController::class, 'preview'])->middleware(['permission:hq.jobs.manage', 'throttle:20,1'])->name('nl.preview');
        Route::post('nl/confirm', [NlScheduleController::class, 'confirm'])->middleware('permission:hq.jobs.manage')->name('nl.confirm');
        Route::get('{job}', [ScheduledJobController::class, 'show'])->middleware('permission:hq.jobs.view')->name('show');
        Route::post('{job}/approve', [ScheduledJobController::class, 'approve'])->middleware('permission:hq.jobs.manage')->name('approve');
        Route::post('{job}/reject', [ScheduledJobController::class, 'reject'])->middleware('permission:hq.jobs.manage')->name('reject');
        Route::post('{job}/pause', [ScheduledJobController::class, 'pause'])->middleware('permission:hq.jobs.manage')->name('pause');
        Route::post('{job}/resume', [ScheduledJobController::class, 'resume'])->middleware('permission:hq.jobs.manage')->name('resume');
        Route::post('{job}/cancel', [ScheduledJobController::class, 'cancel'])->middleware('permission:hq.jobs.manage')->name('cancel');
        Route::post('{job}/run-now', [ScheduledJobController::class, 'runNow'])->middleware('permission:hq.jobs.manage')->name('run-now');
        Route::post('runs/{run}/retry', [ScheduledJobController::class, 'retryRun'])->middleware('permission:hq.jobs.manage')->name('runs.retry');
    });

    // Phase 2 M1: what HQ can do, and the audited runtime kill switch (docs/48 D.2).
    Route::get('tools', [ToolRegistryController::class, 'index'])->middleware('permission:hq.tools.view')->name('tools.index');
    Route::post('tools/{key}/toggle', [ToolRegistryController::class, 'toggle'])->middleware('permission:hq.tools.manage')->name('tools.toggle');

    // Phase 2 M3: the Approval Center (docs/48 G, docs/51).
    //
    // ⚠ The browser supplies an approval id and a decision. There is deliberately NO endpoint here
    // that accepts a tool key and arguments: the server loads the persisted approval and executes
    // the stored, hashed payload a human read.
    Route::prefix('approvals')->name('approvals.')->group(function () {
        Route::get('/', [ApprovalController::class, 'index'])->middleware('permission:hq.approvals.view')->name('index');
        Route::get('{approval}', [ApprovalController::class, 'show'])->middleware('permission:hq.approvals.view')->name('show');
        Route::post('{approval}/approve', [ApprovalController::class, 'approve'])->middleware('permission:hq.approvals.decide')->name('approve');
        Route::post('{approval}/reject', [ApprovalController::class, 'reject'])->middleware('permission:hq.approvals.decide')->name('reject');
        Route::post('{approval}/cancel', [ApprovalController::class, 'cancel'])->middleware('permission:hq.approvals.view')->name('cancel');
        Route::post('{approval}/delegate', [ApprovalController::class, 'delegate'])->middleware('permission:hq.approvals.delegate')->name('delegate');
    });

    // Phase 2 M5: the agents, and the shadow review the seven-day period consists of (docs/48 M5).
    //
    // ⛔ There is deliberately NO route here that starts a run, and none that activates, pauses or
    // re-tools an agent. The browser reads runs and records a verdict; a run is started from the
    // console, and changing what an agent may do is a deploy. `Ask HQ` is M6.
    Route::prefix('agents')->name('agents.')->group(function () {
        Route::get('/', [AgentController::class, 'index'])->middleware('permission:hq.agents.view')->name('index');
        Route::get('runs/{run}', [AgentController::class, 'show'])->middleware('permission:hq.agents.view')->name('runs.show');
        Route::post('runs/{run}/review', [AgentController::class, 'review'])->middleware('permission:hq.agents.manage')->name('runs.review');
    });

    // Phase 2 M6, inert (docs/48 K, docs/78 B.3): Ask HQ, behind the flag `ask_hq`. The only write is a
    // question; there is no route that takes a tool key, arguments or a model.
    Route::prefix('ask')->name('ask.')->middleware('permission:hq.ask.use')->group(function () {
        Route::get('/', [AskController::class, 'index'])->name('index');
        Route::post('/', [AskController::class, 'store'])->middleware('throttle:20,1')->name('store');
        Route::get('{conversation:uuid}', [AskController::class, 'show'])->name('show');
    });

    // Phase 3 (docs/72 C): the operator inbox and the handoff actions. Every state change is a
    // transition the state machine allows and audits; the composer sends only through the gateway.
    Route::prefix('inbox')->name('inbox.')->group(function () {
        Route::get('/', [InboxController::class, 'index'])->middleware('permission:hq.inbox.view')->name('index');
        Route::get('{conversation:uuid}', [InboxController::class, 'show'])->middleware('permission:hq.inbox.view')->name('show');
        Route::post('{conversation:uuid}/take-over', [InboxController::class, 'takeOver'])->middleware('permission:hq.inbox.manage')->name('take-over');
        Route::post('{conversation:uuid}/resolve', [InboxController::class, 'resolve'])->middleware('permission:hq.inbox.manage')->name('resolve');
        Route::post('{conversation:uuid}/return-to-agent', [InboxController::class, 'returnToAgent'])->middleware('permission:hq.inbox.manage')->name('return-to-agent');
        Route::post('{conversation:uuid}/close', [InboxController::class, 'close'])->middleware('permission:hq.inbox.manage')->name('close');
        Route::post('{conversation:uuid}/assign', [InboxController::class, 'assign'])->middleware('permission:hq.inbox.manage')->name('assign');
        Route::post('{conversation:uuid}/toggle-ai', [InboxController::class, 'toggleAi'])->middleware('permission:hq.inbox.manage')->name('toggle-ai');
        Route::post('{conversation:uuid}/note', [InboxController::class, 'note'])->middleware('permission:hq.inbox.manage')->name('note');
        Route::post('{conversation:uuid}/reply', [InboxController::class, 'reply'])->middleware('permission:hq.inbox.manage')->name('reply');
        Route::post('{conversation:uuid}/proposals/{run}/decide', [InboxController::class, 'decide'])->middleware('permission:hq.inbox.manage')->name('decide');
        // docs/78 B.1: a person remembers a fact about the customer, or closes one. Never the agent.
        Route::post('{conversation:uuid}/remember', [InboxController::class, 'remember'])->middleware('permission:hq.inbox.manage')->name('remember');
        Route::post('{conversation:uuid}/memory/{memory}/forget', [InboxController::class, 'forget'])->middleware('permission:hq.inbox.manage')->name('forget');
        // P3-G3 pairing (docs/78 B.1): a person's verdict on a proposal beside what was actually sent.
        Route::post('{conversation:uuid}/comparisons/{comparison}/verdict', [InboxController::class, 'verdict'])->middleware('permission:hq.inbox.manage')->name('verdict');
    });

    // Phase 2 M7 (docs/48 N.3 and N.5, docs/78 B.2): the four dashboards and the alert record. Read-only,
    // each behind a permission that already exists, so no role needs reseeding for them.
    Route::prefix('observe')->name('observe.')->group(function () {
        Route::get('alerts', [ObserveController::class, 'alerts'])->middleware('permission:hq.audit.view')->name('alerts');
        Route::get('ai-health', [ObserveController::class, 'aiHealth'])->middleware('permission:hq.agents.view')->name('ai-health');
        Route::get('cost', [ObserveController::class, 'cost'])->middleware('permission:hq.ai.cost.view')->name('cost');
        Route::get('control-plane', [ObserveController::class, 'controlPlane'])->middleware('permission:hq.tools.view')->name('control-plane');
        Route::get('platform', [ObserveController::class, 'platform'])->middleware('permission:hq.horizon.view')->name('platform');

        // docs/80 C.1: stored briefings and the evidence behind each, read-only. Composing one is the
        // tool `briefing.compose` through the gateway; nothing on these pages composes or narrates.
        Route::get('briefings', [BriefingController::class, 'index'])->middleware('permission:hq.tools.facts.read')->name('briefings');
        Route::get('briefings/{uuid}', [BriefingController::class, 'show'])->whereUuid('uuid')->middleware('permission:hq.tools.facts.read')->name('briefings.show');
    });

    Route::get('audit', [AdminController::class, 'audit'])->middleware('permission:hq.audit.view')->name('audit.index');

    Route::get('flags', [AdminController::class, 'flags'])->middleware('permission:hq.flags.view')->name('flags.index');
    Route::post('flags', [AdminController::class, 'storeFlag'])->middleware('permission:hq.flags.manage')->name('flags.store');
    Route::post('flags/{flag}/toggle', [AdminController::class, 'toggleFlag'])->middleware('permission:hq.flags.manage')->name('flags.toggle');

    Route::get('credentials', [AdminController::class, 'credentials'])->middleware('permission:hq.credentials.view')->name('credentials.index');
    Route::post('credentials', [AdminController::class, 'storeCredential'])->middleware('permission:hq.credentials.manage')->name('credentials.store');
    Route::post('credentials/{credential}/revoke', [AdminController::class, 'revokeCredential'])->middleware('permission:hq.credentials.manage')->name('credentials.revoke');

    Route::get('businesses', [AdminController::class, 'businesses'])->middleware('permission:hq.businesses.view')->name('businesses.index');

    Route::get('customers', [CustomerController::class, 'index'])->middleware('permission:hq.customers.view')->name('customers.index');
    Route::get('customers/{customer}', [CustomerController::class, 'show'])->middleware('permission:hq.customers.view')->name('customers.show');
    Route::post('customers/merge', [CustomerController::class, 'merge'])->middleware('permission:hq.customers.manage')->name('customers.merge');
    Route::post('customers/candidates/{candidate}/dismiss', [CustomerController::class, 'dismiss'])->middleware('permission:hq.customers.manage')->name('customers.candidates.dismiss');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
