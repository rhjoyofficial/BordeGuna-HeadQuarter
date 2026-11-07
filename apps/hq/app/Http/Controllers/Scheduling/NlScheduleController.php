<?php

namespace App\Http\Controllers\Scheduling;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Scheduling\Nl\NlScheduling;
use App\Support\Authorization\BusinessScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Throwable;

/**
 * Scheduling in words (docs/48 L, docs/78 B.3), behind the flag `scheduler.nl` and `hq.jobs.manage`.
 *
 * Two posts: a sentence becomes a preview or questions; a preview token becomes a job. The confirm
 * post carries only the token, so what is created is exactly what was validated and shown.
 */
final class NlScheduleController extends Controller
{
    public function __construct(private readonly NlScheduling $nl) {}

    public function create(Request $request, BusinessScope $scope): Response
    {
        abort_unless($this->nl->enabled(), 404);
        // docs/136: the picker offers only the businesses this person may schedule for.
        $permitted = $scope->all($request->user());

        return Inertia::render('scheduler/nl', [
            'runtime_on' => $this->nl->runtimeOn(),
            'result' => $request->session()->get('nl_result'),
            'text' => $request->session()->get('nl_text', ''),
            'businesses' => Business::query()->where('is_active', true)
                ->when($permitted !== null, fn ($q) => $q->whereIn('id', $permitted))
                ->orderBy('name')->get(['id', 'name'])
                ->map(fn (Business $b) => ['id' => $b->id, 'name' => $b->name])->all(),
            'max_text' => NlScheduling::MAX_TEXT,
        ]);
    }

    public function preview(Request $request): RedirectResponse
    {
        abort_unless($this->nl->enabled(), 404);

        $data = $request->validate([
            'text' => ['required', 'string', 'max:'.NlScheduling::MAX_TEXT],
            'business_id' => ['nullable', 'integer', 'exists:businesses,id'],
        ]);

        try {
            $result = $this->nl->preview($request->user(), $data['text'], $data['business_id'] ?? null);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['text' => $e->getMessage()]);
        } catch (Throwable $e) {
            // A model failure is shown as a failure, never as a question the model did not ask.
            report($e);
            throw ValidationException::withMessages(['text' => 'HQ could not read that sentence ('.class_basename($e).'). Try again, or use the form.']);
        }

        return redirect()->route('scheduler.nl.create')
            ->with('nl_result', $result)
            ->with('nl_text', $data['text']);
    }

    public function confirm(Request $request): RedirectResponse
    {
        abort_unless($this->nl->enabled(), 404);

        $data = $request->validate(['token' => ['required', 'uuid']]);

        try {
            $job = $this->nl->confirm($request->user(), $data['token']);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['token' => $e->getMessage()]);
        }

        return redirect()->route('scheduler.show', $job)->with('status', $job->approval_state->value === 'pending' ? 'job-created-pending' : 'job-created');
    }
}
