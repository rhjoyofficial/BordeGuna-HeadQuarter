<?php

namespace App\Http\Controllers\Security;

use App\Http\Controllers\Controller;
use App\Support\Audit\Auditor;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Device/session management on top of the database session driver. */
final class SessionsController extends Controller
{
    public function __construct(private readonly Auditor $auditor) {}

    public function index(Request $request): Response
    {
        $current = $request->session()->getId();
        $sessions = DB::table('sessions')->where('user_id', $request->user()->getKey())
            ->orderByDesc('last_activity')->get()
            ->map(fn ($s) => [
                'id' => substr(hash('sha256', $s->id), 0, 12),
                'ip' => $s->ip_address,
                'user_agent' => $s->user_agent,
                'last_active_at' => CarbonImmutable::createFromTimestamp($s->last_activity)->toIso8601String(),
                'is_current' => $s->id === $current,
            ]);

        return Inertia::render('settings/sessions', ['sessions' => $sessions]);
    }

    public function destroyOthers(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['required', 'current_password']]);
        Auth::logoutOtherDevices($request->input('password'));
        DB::table('sessions')->where('user_id', $request->user()->getKey())->where('id', '!=', $request->session()->getId())->delete();
        $this->auditor->record('auth.sessions.others_revoked', $request->user());

        return back()->with('status', 'other-sessions-revoked');
    }
}
