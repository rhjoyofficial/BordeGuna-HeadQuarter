<?php

namespace App\Ai\Pause;

use App\Models\User;
use App\Support\Authorization\GroupWideAuthority;
use Throwable;

/**
 * What every page is told about Pause All AI (docs/141): the state for the banner, and whether THIS person
 * may pause or turn AI back on, decided by the server (permission AND group-wide authority, ruling 13).
 * A restricted person sees the state and gets no control. Read-only.
 */
final class AiPauseScreen
{
    public function __construct(
        private readonly AiPause $pause,
        private readonly GroupWideAuthority $authority,
    ) {}

    /**
     * @return array{paused: bool, readable: bool, paused_at: string|null, paused_by: string|null, reason: string|null, can_pause: bool, can_resume: bool, refusal: string|null, refusal_code: string|null}
     */
    public function for(?User $user): array
    {
        $state = $this->pause->state();
        $pauseRefusal = $this->authority->refusalDetail($user, AiPauseSwitch::PAUSE_PERMISSION);
        $resumeRefusal = $this->authority->refusal($user, AiPauseSwitch::RESUME_PERMISSION);
        $holdsEither = $user !== null && ($this->holds($user, AiPauseSwitch::PAUSE_PERMISSION) || $this->holds($user, AiPauseSwitch::RESUME_PERMISSION));

        return [
            'paused' => $state['paused'],
            'readable' => $state['readable'],
            'paused_at' => $state['paused'] ? $state['paused_at'] : null,
            'paused_by' => $state['paused'] && $state['paused_by_user_id'] !== null
                ? User::query()->whereKey($state['paused_by_user_id'])->value('name')
                : null,
            'reason' => $state['paused'] ? $state['pause_reason'] : null,
            'can_pause' => $pauseRefusal === null,
            'can_resume' => $resumeRefusal === null,
            // Only for a person who holds one of the permissions and is still refused (a business restriction):
            // they are told why there is no button. Everyone else simply sees the state.
            'refusal' => $holdsEither && $pauseRefusal !== null ? $pauseRefusal['message'] : null,
            'refusal_code' => $holdsEither && $pauseRefusal !== null ? $pauseRefusal['code'] : null,
        ];
    }

    private function holds(User $user, string $permission): bool
    {
        try {
            return $user->hasPermissionTo($permission);
        } catch (Throwable) {
            return false;
        }
    }
}
