<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Support\Audit\Auditor;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function __construct(private readonly Auditor $auditor) {}

    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's profile settings.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        $user->fill($request->validated());

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        // docs/90 A.6: the language preference is the person's own, durable and auditable. It is a
        // profile mutation like the name, never an AI capability: only this request writes it, as the
        // person, and the audit row names them as the actor.
        $localeChanged = $user->isDirty('locale');
        $before = $localeChanged ? ['locale' => $user->getOriginal('locale')] : null;

        $user->save();

        if ($localeChanged) {
            $this->auditor->record('user.locale_changed', $user, $before, ['locale' => $user->locale],
                'the person chose the language their HQ screens read in', actorUserId: $user->id);
        }

        return to_route('profile.edit');
    }

    /*
     * There is deliberately no destroy() here. The starter kit shipped a self-service "Delete
     * account" on this screen; on a control plane with a single owner that is a button that locks
     * the whole group out. Removed 12 Sep 2026 (docs/64 section D, decided in the ChatGPT thread).
     * Account lifecycle, if it is ever needed, will be a governed capability with its own audit,
     * not a form on the profile page. ProfileUpdateTest holds the route absent.
     */
}
