<?php

namespace App\Http\Controllers\Security;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Support\Audit\Auditor;
use BaconQrCode\Renderer\Color\Rgb;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\Fill;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use PragmaRX\Google2FA\Google2FA;

/**
 * Mandatory TOTP setup and management. Flow: enable (generate a pending secret) -> the user
 * scans the QR -> confirm with a valid code (only then is 2FA considered enabled) -> recovery
 * codes shown once. Disabling requires the current password and re-arms the mandatory gate.
 */
final class TwoFactorController extends Controller
{
    public function __construct(private readonly Google2FA $google2fa, private readonly Auditor $auditor) {}

    public function show(Request $request): Response
    {
        $user = $request->user();
        $pending = $user->two_factor_secret !== null && $user->two_factor_confirmed_at === null;

        return Inertia::render('security/two-factor', [
            'enabled' => $user->hasTwoFactorEnabled(),
            'pending' => $pending,
            'qrSvg' => $pending ? $this->qrSvg($user->email, $user->two_factor_secret) : null,
            'secret' => $pending ? $user->two_factor_secret : null,
            'recoveryCodes' => $request->session()->get('two_factor.recovery_codes'),
            'confirmedAt' => $user->two_factor_confirmed_at?->toIso8601String(),
        ]);
    }

    public function enable(Request $request): RedirectResponse
    {
        $user = $request->user();
        if ($user->hasTwoFactorEnabled()) {
            return back()->withErrors(['code' => 'Two-factor authentication is already enabled.']);
        }
        $user->forceFill(['two_factor_secret' => $this->google2fa->generateSecretKey(32), 'two_factor_confirmed_at' => null])->save();

        return redirect()->route('two-factor.setup');
    }

    public function confirm(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'digits:6']]);
        $user = $request->user();
        if ($user->two_factor_secret === null) {
            return back()->withErrors(['code' => 'Start setup first.']);
        }
        if (! $this->google2fa->verifyKey($user->two_factor_secret, $request->string('code'), 1)) {
            return back()->withErrors(['code' => 'The code is not valid. Check the time on your phone and try again.']);
        }

        $codes = $this->generateRecoveryCodes();
        $user->forceFill(['two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => $codes->all()])->save();
        $request->session()->put(EnsureTwoFactorVerified::SESSION_KEY, $user->getKey());
        $request->session()->flash('two_factor.recovery_codes', $codes->all());
        $this->auditor->record('auth.two_factor.enabled', $user);

        return redirect()->route('two-factor.setup')->with('status', 'two-factor-enabled');
    }

    public function regenerateRecoveryCodes(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['required', 'current_password']]);
        $codes = $this->generateRecoveryCodes();
        $request->user()->forceFill(['two_factor_recovery_codes' => $codes->all()])->save();
        $request->session()->flash('two_factor.recovery_codes', $codes->all());
        $this->auditor->record('auth.two_factor.recovery_codes_regenerated', $request->user());

        return back();
    }

    public function disable(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['required', 'current_password']]);
        $user = $request->user();
        $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
        $request->session()->forget(EnsureTwoFactorVerified::SESSION_KEY);
        $this->auditor->record('auth.two_factor.disabled', $user, reason: 'user request with password');

        return redirect()->route('two-factor.setup')->with('status', 'two-factor-disabled');
    }

    private function generateRecoveryCodes(): Collection
    {
        return Collection::times(8, fn () => Str::upper(Str::random(5)).'-'.Str::upper(Str::random(5)));
    }

    private function qrSvg(string $email, string $secret): string
    {
        $url = $this->google2fa->getQRCodeUrl(config('app.name'), $email, $secret);
        $renderer = new ImageRenderer(new RendererStyle(220, 2, null, null, Fill::uniformColor(new Rgb(255, 255, 255), new Rgb(20, 20, 20))), new SvgImageBackEnd);

        return (new Writer($renderer))->writeString($url);
    }
}
