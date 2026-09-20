<?php

namespace App\Http\Controllers\Auth;

use App\Auth\ActiveUserProvider;
use App\Http\Controllers\Controller;
use App\Support\Audit\Auditor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PasswordResetLinkController extends Controller
{
    /**
     * Show the password reset link request page.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('auth/forgot-password', [
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Handle an incoming password reset link request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        // docs/81 P0-1: the broker finds nobody for a disabled account, so no link is sent and the
        // answer below is the one every address gets. The record says what happened.
        if (ActiveUserProvider::isDisabledAccount($request->input('email'))) {
            app(Auditor::class)->recordSystem('auth.password_reset_refused', 'user', after: ['email' => $request->input('email'), 'step' => 'link'], reason: ActiveUserProvider::DISABLED_REASON);
        }

        Password::sendResetLink(
            $request->only('email')
        );

        return back()->with('status', __('A reset link will be sent if the account exists.'));
    }
}
