<?php

use App\Http\Controllers\Admin\AdminController;
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
        Route::get('{job}', [ScheduledJobController::class, 'show'])->middleware('permission:hq.jobs.view')->name('show');
        Route::post('{job}/pause', [ScheduledJobController::class, 'pause'])->middleware('permission:hq.jobs.manage')->name('pause');
        Route::post('{job}/resume', [ScheduledJobController::class, 'resume'])->middleware('permission:hq.jobs.manage')->name('resume');
        Route::post('{job}/cancel', [ScheduledJobController::class, 'cancel'])->middleware('permission:hq.jobs.manage')->name('cancel');
        Route::post('{job}/run-now', [ScheduledJobController::class, 'runNow'])->middleware('permission:hq.jobs.manage')->name('run-now');
        Route::post('runs/{run}/retry', [ScheduledJobController::class, 'retryRun'])->middleware('permission:hq.jobs.manage')->name('runs.retry');
    });

    Route::get('audit', [AdminController::class, 'audit'])->middleware('permission:hq.audit.view')->name('audit.index');

    Route::get('flags', [AdminController::class, 'flags'])->middleware('permission:hq.flags.view')->name('flags.index');
    Route::post('flags', [AdminController::class, 'storeFlag'])->middleware('permission:hq.flags.manage')->name('flags.store');
    Route::post('flags/{flag}/toggle', [AdminController::class, 'toggleFlag'])->middleware('permission:hq.flags.manage')->name('flags.toggle');

    Route::get('credentials', [AdminController::class, 'credentials'])->middleware('permission:hq.credentials.view')->name('credentials.index');
    Route::post('credentials', [AdminController::class, 'storeCredential'])->middleware('permission:hq.credentials.manage')->name('credentials.store');
    Route::post('credentials/{credential}/revoke', [AdminController::class, 'revokeCredential'])->middleware('permission:hq.credentials.manage')->name('credentials.revoke');

    Route::get('businesses', [AdminController::class, 'businesses'])->middleware('permission:hq.businesses.view')->name('businesses.index');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
