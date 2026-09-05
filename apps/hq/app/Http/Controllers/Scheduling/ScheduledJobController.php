<?php

namespace App\Http\Controllers\Scheduling;

use App\Http\Controllers\Controller;
use App\Models\JobRun;
use App\Models\ScheduledJob;
use App\Scheduling\ScheduledJobService;
use App\Scheduling\Tools\ToolRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ScheduledJobController extends Controller
{
    public function __construct(private readonly ScheduledJobService $service, private readonly ToolRegistry $tools) {}

    public function index(Request $request): Response
    {
        $jobs = ScheduledJob::query()->with(['business:id,name', 'brand:id,name'])
            ->when($request->string('state')->isNotEmpty(), fn ($q) => $q->where('state', $request->string('state')))
            ->orderByRaw('next_run_at nulls last')->orderByDesc('id')->paginate(25)->withQueryString();

        return Inertia::render('scheduler/index', [
            'jobs' => $jobs,
            'tools' => $this->tools->keys(),
            'filters' => ['state' => $request->string('state')->toString()],
        ]);
    }

    public function show(ScheduledJob $job): Response
    {
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
        if (! empty($data['run_at'])) {
            // The form gives a local time in the job's timezone; store UTC.
            $data['run_at'] = CarbonImmutable::parse($data['run_at'], $data['timezone'] ?? 'Asia/Dhaka')->utc();
        }
        $job = $this->service->create(array_filter($data, fn ($v) => $v !== null && $v !== ''), $request->user(), 'ui');

        return redirect()->route('scheduler.show', $job)->with('status', 'job-created');
    }

    public function pause(ScheduledJob $job): RedirectResponse
    {
        $this->service->pause($job);

        return back()->with('status', 'job-paused');
    }

    public function resume(ScheduledJob $job): RedirectResponse
    {
        $this->service->resume($job);

        return back()->with('status', 'job-resumed');
    }

    public function cancel(ScheduledJob $job): RedirectResponse
    {
        $this->service->cancel($job);

        return back()->with('status', 'job-cancelled');
    }

    public function runNow(Request $request, ScheduledJob $job): RedirectResponse
    {
        $this->service->runNow($job, $request->user());

        return back()->with('status', 'job-run-queued');
    }

    public function retryRun(Request $request, JobRun $run): RedirectResponse
    {
        $this->service->retry($run, $request->user());

        return back()->with('status', 'run-retry-queued');
    }
}
