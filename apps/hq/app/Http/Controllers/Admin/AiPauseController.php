<?php

namespace App\Http\Controllers\Admin;

use App\Ai\Pause\AiPauseSwitch;
use App\Http\Controllers\Controller;
use App\Support\Authorization\GroupWideAuthority;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Pause All AI from the browser (docs/138 ruling 7, docs/141). Two routes, two permissions, never a toggle:
 *
 *   - `POST /ai/pause` (`hq.ai.pause`) can only pause. It reads nothing from the request but the reason, so
 *     no field sent to it can mean "turn AI back on".
 *   - `POST /ai/resume` (`hq.ai.resume`) is the distinct re-enable.
 *
 * Each route carries `group.wide:<permission>` after `permission:`, this controller asserts the authority
 * again, and `AiPauseSwitch` asserts it a third time (ruling 13: no single layer is the only guard).
 * The state is shared to every page by `HandleInertiaRequests` (`aiPause`), so this controller renders nothing.
 */
final class AiPauseController extends Controller
{
    private const REASON_MESSAGE = 'Say why. Pause All AI acts on every business, and the reason is kept in the audit log with who did it.';

    public function __construct(
        private readonly GroupWideAuthority $authority,
        private readonly AiPauseSwitch $switch,
    ) {}

    public function pause(Request $request): RedirectResponse
    {
        $this->authority->assert($request->user(), AiPauseSwitch::PAUSE_PERMISSION);

        $this->switch->pause($request->user(), $this->reason($request));

        return back()->with('status', 'ai-paused');
    }

    public function resume(Request $request): RedirectResponse
    {
        $this->authority->assert($request->user(), AiPauseSwitch::RESUME_PERMISSION);

        try {
            $this->switch->resume($request->user(), $this->reason($request));
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['reason' => $e->getMessage()]);
        }

        return back()->with('status', 'ai-resumed');
    }

    private function reason(Request $request): string
    {
        $data = $request->validate(
            ['reason' => ['required', 'string', 'max:500', 'regex:/\S/']],
            ['reason.required' => self::REASON_MESSAGE, 'reason.regex' => self::REASON_MESSAGE],
        );

        return trim($data['reason']);
    }
}
