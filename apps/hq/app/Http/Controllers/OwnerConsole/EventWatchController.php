<?php

namespace App\Http\Controllers\OwnerConsole;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\EventWatch;
use App\OwnerConsole\Flag;
use App\OwnerConsole\Refused;
use App\OwnerConsole\Watches\EventWatchService;
use App\Support\Authorization\BusinessScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Event watches (docs/143 F). A watch is proposed, then enabled by a named person, and can only ever
 * propose an action to the Approval Center. A watch of a business the person may not see is not there (404).
 */
final class EventWatchController extends Controller
{
    public function __construct(private readonly EventWatchService $watches, private readonly BusinessScope $scope) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $watches = $this->scope->restrict(EventWatch::query(), $user)->with('business:id,name')->latest('id')->limit(100)->get();

        return Inertia::render('watches/index', [
            'enabled' => Flag::on(Flag::EVENT_WATCHES),
            'watches' => $watches->map(fn (EventWatch $w) => [
                'uuid' => $w->uuid, 'name' => $w->name, 'business' => $w->business?->name, 'event_type' => $w->event_type, 'conditions' => $w->conditions,
                'action' => $w->action === null ? null : ['tool_key' => $w->action['tool_key']], 'state' => $w->state, 'max_per_hour' => $w->max_per_hour,
                'max_per_day' => $w->max_per_day, 'fire_count' => $w->fire_count, 'last_fired_at' => $w->last_fired_at?->toIso8601String(),
                'proposer_kind' => $w->proposer_kind, 'note' => $w->note,
            ])->all(),
            'businesses' => $this->scope->restrict(Business::query(), $user, 'id')->orderBy('name')->get(['id', 'name']),
            'ops' => EventWatchService::OPS,
            'limits' => ['per_hour' => EventWatchService::MAX_PER_HOUR, 'per_day' => EventWatchService::MAX_PER_DAY, 'conditions' => EventWatchService::MAX_CONDITIONS],
            'can' => ['propose' => $user->can(EventWatchService::PERMISSION_PROPOSE), 'enable' => $user->can(EventWatchService::PERMISSION_ENABLE)],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'business_id' => ['required', 'integer', 'exists:businesses,id'],
            'name' => ['required', 'string', 'max:160'],
            'event_type' => ['required', 'string', 'max:96'],
            'conditions' => ['nullable', 'array', 'max:'.EventWatchService::MAX_CONDITIONS],
            'conditions.*.path' => ['required', 'string', 'max:64'],
            'conditions.*.op' => ['required', 'string', 'max:16'],
            'conditions.*.value' => ['required'],
            'action_tool_key' => ['nullable', 'string', 'max:96'],
            'action_args' => ['nullable', 'array'],
            'max_per_hour' => ['nullable', 'integer'],
            'max_per_day' => ['nullable', 'integer'],
        ]);

        try {
            $this->watches->propose(
                $request->user(), (int) $data['business_id'], $data['name'], $data['event_type'], $data['conditions'] ?? [],
                empty($data['action_tool_key']) ? null : ['tool_key' => $data['action_tool_key'], 'args' => $data['action_args'] ?? []],
                (int) ($data['max_per_hour'] ?? 5), (int) ($data['max_per_day'] ?? 20),
            );
        } catch (Refused $e) {
            throw ValidationException::withMessages(['watch' => $e->getMessage()]);
        }

        return back()->with('status', 'watch-proposed');
    }

    public function enable(Request $request, EventWatch $watch): RedirectResponse
    {
        return $this->act($request, $watch, fn () => $this->watches->enable($watch, $request->user()), 'watch-enabled');
    }

    public function disable(Request $request, EventWatch $watch): RedirectResponse
    {
        return $this->act($request, $watch, fn () => $this->watches->disable($watch, $request->user()), 'watch-disabled');
    }

    public function reject(Request $request, EventWatch $watch): RedirectResponse
    {
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:500']])['reason'] ?? null;

        return $this->act($request, $watch, fn () => $this->watches->reject($watch, $request->user(), $reason), 'watch-rejected');
    }

    private function act(Request $request, EventWatch $watch, callable $do, string $status): RedirectResponse
    {
        if (! $this->scope->restrict(EventWatch::query()->whereKey($watch->getKey()), $request->user())->exists()) {
            abort(404);
        }
        try {
            $do();
        } catch (Refused $e) {
            throw ValidationException::withMessages(['watch' => $e->getMessage()]);
        }

        return back()->with('status', $status);
    }
}
