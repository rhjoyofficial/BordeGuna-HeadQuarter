<?php

namespace App\Http\Controllers\Scheduling;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\JobRun;
use App\Models\ScheduledJob;
use App\Scheduling\ScheduledJobService;
use App\Support\Authorization\BusinessScope;
use App\Tools\ToolRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class ScheduledJobController extends Controller
{
    public function __construct(
        private readonly ScheduledJobService $service,
        private readonly ToolRegistry $tools,
        private readonly BusinessScope $scope,
    ) {}

    public function index(Request $request): Response
    {
        // docs/136: only the businesses this person may see; a job with no business only for a group-wide person.
        $jobs = ScheduledJob::query()->visibleTo($request->user())->with(['business:id,name', 'brand:id,name'])
            ->when($request->string('state')->isNotEmpty(), fn ($q) => $q->where('state', $request->string('state')))
            ->orderByRaw('next_run_at nulls last')->orderByDesc('id')->paginate(25)->withQueryString();

        return Inertia::render('scheduler/index', [
            'jobs' => $jobs,
            'tools' => $this->tools->keys(),
            'filters' => ['state' => $request->string('state')->toString()],
        ]);
    }

    public function show(Request $request, ScheduledJob $job): Response
    {
        $this->assertVisible($request, $job);

        return Inertia::render('scheduler/show', [
            'job' => $job->load(['business:id,name', 'brand:id,name', 'owner:id,name,email']),
            'runs' => $job->runs()->paginate(25),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'kind' => ['required', 'in:once,cron,interval,event'],
            'tool_key' => ['required', 'string', 'in:'.implode(',', $this->tools->keys())],
            'cron_expression' => ['nullable', 'string', 'max:96', 'required_if:kind,cron'],
            'run_at' => ['nullable', 'date', 'required_if:kind,once'],
            'interval_seconds' => ['nullable', 'integer', 'min:60', 'required_if:kind,interval'],
            'event_type' => ['nullable', 'string', 'max:96', 'required_if:kind,event'],
            'timezone' => ['nullable', 'timezone:all'],
            'payload' => ['nullable', 'array'],
            'missed_policy' => ['nullable', 'in:skip,catch_up,run_latest_only'],
            'retry_max' => ['nullable', 'integer', 'min:0', 'max:10'],
            'business_id' => ['nullable', 'exists:businesses,id'],
            'brand_id' => ['nullable', 'exists:brands,id'],
        ]);
        $this->assertMayCreateFor($request, isset($data['business_id']) ? (int) $data['business_id'] : null, isset($data['brand_id']) ? (int) $data['brand_id'] : null);
        if (! empty($data['run_at'])) {
            // The form gives a local time in the job's timezone; store UTC.
            $data['run_at'] = CarbonImmutable::parse($data['run_at'], $data['timezone'] ?? 'Asia/Dhaka')->utc();
        }
        $job = $this->service->create(array_filter($data, fn ($v) => $v !== null && $v !== ''), $request->user(), 'ui');

        return redirect()->route('scheduler.show', $job)->with('status', 'job-created');
    }

    /** docs/48 L.5: a job that runs an R3 or higher tool waits here until a person approves it. */
    public function approve(Request $request, ScheduledJob $job): RedirectResponse
    {
        $this->assertVisible($request, $job);
        $this->service->approve($job, $request->user(), $request->string('reason')->toString() ?: null);

        return back()->with('status', 'job-approved');
    }

    public function reject(Request $request, ScheduledJob $job): RedirectResponse
    {
        $this->assertVisible($request, $job);
        $this->service->reject($job, $request->user(), $request->string('reason')->toString() ?: null);

        return back()->with('status', 'job-rejected');
    }

    public function pause(Request $request, ScheduledJob $job): RedirectResponse
    {
        $this->assertVisible($request, $job);
        $this->service->pause($job);

        return back()->with('status', 'job-paused');
    }

    public function resume(Request $request, ScheduledJob $job): RedirectResponse
    {
        $this->assertVisible($request, $job);
        $this->service->resume($job);

        return back()->with('status', 'job-resumed');
    }

    public function cancel(Request $request, ScheduledJob $job): RedirectResponse
    {
        $this->assertVisible($request, $job);
        $this->service->cancel($job);

        return back()->with('status', 'job-cancelled');
    }

    public function runNow(Request $request, ScheduledJob $job): RedirectResponse
    {
        $this->assertVisible($request, $job);
        $this->service->runNow($job, $request->user());

        return back()->with('status', 'job-run-queued');
    }

    public function retryRun(Request $request, JobRun $run): RedirectResponse
    {
        if (! JobRun::query()->visibleTo($request->user())->whereKey($run->getKey())->exists()) {
            abort(404);
        }
        $this->service->retry($run, $request->user());

        return back()->with('status', 'run-retry-queued');
    }

    /**
     * docs/136 (rulings docs/120 A4, docs/131 §1): a job of a business this person may not see is not
     * there, for reading and for every action. 404, not 403, as on the agent and inbox screens.
     */
    private function assertVisible(Request $request, ScheduledJob $job): void
    {
        if (! ScheduledJob::query()->visibleTo($request->user())->whereKey($job->getKey())->exists()) {
            abort(404);
        }
    }

    /**
     * A restricted person schedules work only for one of their businesses: a job with no business, or
     * another business's, would be one they could not see afterwards (docs/136). A brand must belong
     * to the business named.
     */
    private function assertMayCreateFor(Request $request, ?int $businessId, ?int $brandId): void
    {
        $scope = $this->scope->all($request->user());
        if ($scope !== null && ($businessId === null || ! in_array($businessId, $scope, true))) {
            throw ValidationException::withMessages(['business_id' => 'Choose one of your businesses: your access is limited to some businesses.']);
        }
        if ($scope !== null && $brandId !== null && (int) Brand::query()->whereKey($brandId)->value('business_id') !== $businessId) {
            throw ValidationException::withMessages(['brand_id' => 'That brand does not belong to the business chosen.']);
        }
    }
}
