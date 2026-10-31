<?php

use App\Http\Controllers\Completion\AutonomyController;
use App\Http\Controllers\Completion\AvatarController;
use App\Http\Controllers\Completion\ContentController;
use App\Http\Controllers\Completion\ResearchDigestController;
use App\Http\Controllers\Completion\SkillController;
use Illuminate\Support\Facades\Route;

/*
 | docs/145 (HQ completion, agent M4): Phases 6, 8, 9 and 10. Loaded from web.php INSIDE the group that needs
 | a password, confirmed 2FA and a verified challenge. Every write is a named person's act behind its own
 | permission and the business scope; none takes a tool key, a provider, a model or an account, and none
 | publishes, generates, starts an agent run or grants autonomy by itself.
 */

// Phase 6: the content calendar, its drafts and the owner's claims rules.
Route::prefix('content')->name('content.')->group(function () {
    Route::get('/', [ContentController::class, 'index'])->middleware('permission:hq.content.view')->name('index');
    Route::post('calendar', [ContentController::class, 'plan'])->middleware('permission:hq.content.plan')->name('plan');
    Route::post('calendar/{entry}/draft', [ContentController::class, 'draft'])->middleware('permission:hq.content.plan')->name('draft');
    Route::post('calendar/{entry}/ai-draft', [ContentController::class, 'aiDraft'])->middleware('permission:hq.content.plan')->name('ai-draft');
    Route::post('calendar/{entry}/review', [ContentController::class, 'review'])->middleware('permission:hq.content.review')->name('review');
    Route::post('calendar/{entry}/skip', [ContentController::class, 'skip'])->middleware('permission:hq.content.plan')->name('skip');
    Route::post('claims-rules', [ContentController::class, 'storeRule'])->middleware('permission:hq.content.claims.manage')->name('claims.store');
    Route::post('claims-rules/{rule}/retire', [ContentController::class, 'retireRule'])->middleware('permission:hq.content.claims.manage')->name('claims.retire');
});

// Phase 6: research digests, assembled from filed, cited research.
Route::get('research/digests', [ResearchDigestController::class, 'index'])->middleware('permission:hq.research.view')->name('research.digests');
Route::post('research/digests', [ResearchDigestController::class, 'store'])->middleware('permission:hq.research.digest')->name('research.digests.store');

// Phase 8: the avatar consent registry; generation is a gateway request (R4, approval, registered nowhere).
Route::prefix('avatar')->name('avatar.')->group(function () {
    Route::get('/', [AvatarController::class, 'index'])->middleware('permission:hq.avatar.view')->name('index');
    Route::post('consents', [AvatarController::class, 'store'])->middleware('permission:hq.avatar.consent.manage')->name('consents.store');
    Route::post('consents/{consent}/revoke', [AvatarController::class, 'revoke'])->middleware('permission:hq.avatar.consent.manage')->name('consents.revoke');
    Route::post('generate', [AvatarController::class, 'generate'])->middleware(['permission:hq.avatar.generate', 'throttle:10,1'])->name('generate');
});

// Phase 9: the skill registry and the delegation record.
Route::prefix('skills')->name('skills.')->group(function () {
    Route::get('/', [SkillController::class, 'index'])->middleware('permission:hq.skills.view')->name('index');
    Route::post('/', [SkillController::class, 'store'])->middleware('permission:hq.skills.propose')->name('store');
    Route::post('{skill}/{action}', [SkillController::class, 'transition'])->whereIn('action', ['approve', 'activate', 'suspend', 'retire'])
        ->middleware('permission:hq.skills.propose|hq.skills.activate')->name('transition');
});

// Phase 10: the evidence ladder. Thresholds and grants need group-wide authority; revoking does not.
Route::prefix('autonomy')->name('autonomy.')->group(function () {
    Route::get('/', [AutonomyController::class, 'index'])->middleware('permission:hq.autonomy.view')->name('index');
    Route::post('thresholds', [AutonomyController::class, 'storeThreshold'])->middleware(['permission:hq.autonomy.grant', 'group.wide:hq.autonomy.grant'])->name('thresholds.store');
    Route::post('thresholds/{threshold}/retire', [AutonomyController::class, 'retireThreshold'])->middleware(['permission:hq.autonomy.grant', 'group.wide:hq.autonomy.grant'])->name('thresholds.retire');
    Route::post('grants', [AutonomyController::class, 'grant'])->middleware(['permission:hq.autonomy.grant', 'group.wide:hq.autonomy.grant'])->name('grants.store');
    Route::post('grants/{grant:uuid}/revoke', [AutonomyController::class, 'revoke'])->middleware('permission:hq.autonomy.revoke')->name('grants.revoke');
});
