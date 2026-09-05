<?php

namespace App\Http\Controllers\Security;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Support\Audit\Auditor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use Inertia\Response;
use PragmaRX\Google2FA\Google2FA;

/** The per-session TOTP challenge after password login. Rate limited per user. */
final class TwoFactorChallengeController extends Controller
{
    public function __construct(private readonly Google2FA $google2fa, private readonly Auditor $auditor) {}

    public function show(Request $request): Response|RedirectResponse
    {
        if ($request->session()->get(EnsureTwoFactorVerified::SESSION_KEY) === $request->user()->getKey()) {
            return redirect()->intended(route('dashboard'));
        }

        return Inertia::render('auth/two-factor-challenge');
    }

    public function verify(Request $request): RedirectResponse
    {
        $request->validate([
            'code' => ['nullable', 'digits:6', 'required_without:recovery_code'],
            'recovery_code' => ['nullable', 'string', 'required_without:code'],
        ]);
        $user = $request->user();
        $key = 'two-factor:'.$user->getKey();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->withErrors(['code' => 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.']);
        }

        $ok = false;
        if ($request->filled('code')) {
            $ok = $this->google2fa->verifyKey($user->two_factor_secret, $request->string('code'), 1);
        } elseif ($request->filled('recovery_code')) {
            $codes = collect($user->two_factor_recovery_codes ?? []);
            $given = strtoupper(trim((string) $request->input('recovery_code')));
            if ($codes->contains($given)) {
                $ok = true;
                $user->forceFill(['two_factor_recovery_codes' => $codes->reject(fn ($c) => $c === $given)->values()->all()])->save();
                $this->auditor->record('auth.two_factor.recovery_code_used', $user);
            }
        }

        if (! $ok) {
            RateLimiter::hit($key, 300);
            $this->auditor->record('auth.two_factor.failed', $user);

            return back()->withErrors(['code' => 'That code was not accepted.']);
        }

        RateLimiter::clear($key);
        $request->session()->put(EnsureTwoFactorVerified::SESSION_KEY, $user->getKey());
        $this->auditor->record('auth.two_factor.verified', $user);

        return redirect()->intended(route('dashboard'));
    }
}
