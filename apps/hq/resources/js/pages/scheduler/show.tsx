import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';

type Job = {
    id: number;
    uuid: string;
    name: string;
    kind: string;
    tool_key: string;
    state: string;
    approval_state: string;
    timezone: string;
    cron_expression: string | null;
    run_at: string | null;
    interval_seconds: number | null;
    event_type: string | null;
    missed_policy: string;
    retry_max: number;
    retry_backoff_seconds: number;
    payload: Record<string, unknown> | null;
    next_run_at: string | null;
    last_run_at: string | null;
    last_status: string | null;
    created_from: string;
    owner?: { name: string; email: string } | null;
    business?: { name: string } | null;
    brand?: { name: string } | null;
};
type Run = {
    id: number;
    scheduled_for: string;
    trigger: string;
    attempt: number;
    status: string;
    started_at: string | null;
    finished_at: string | null;
    error: string | null;
    output: { output?: Record<string, unknown> } | null;
    attempts: { attempt: number; status: string; error: string | null; duration_ms: number }[] | null;
};
type Paginated<T> = { data: T[]; total: number };

export default function SchedulerShow({ job, runs }: { job: Job; runs: Paginated<Run> }) {
    const { auth } = usePage<{ auth: { permissions: string[] } }>().props;
    const canManage = auth.permissions.includes('hq.jobs.manage');
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Scheduler', href: '/scheduler' },
        { title: job.name, href: `/scheduler/${job.id}` },
    ];
    const act = (action: string) => router.post(`/scheduler/${job.id}/${action}`, {}, { preserveScroll: true });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={job.name} />
            <div className="flex flex-col gap-6 p-4">
                <div className="rounded-xl border p-4">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <h1 className="text-lg font-semibold">{job.name}</h1>
                            <div className="text-muted-foreground text-xs">
                                uuid {job.uuid} · created from {job.created_from}
                                {job.owner ? ` by ${job.owner.email}` : ''}
                            </div>
                        </div>
                        {canManage && (
                            <div className="flex flex-wrap gap-2">
                                {job.state !== 'cancelled' && (
                                    <Button variant="outline" onClick={() => act('run-now')}>
                                        Run now
                                    </Button>
                                )}
                                {job.state === 'active' && (
                                    <Button variant="outline" onClick={() => act('pause')}>
                                        Pause
                                    </Button>
                                )}
                                {job.state === 'paused' && (
                                    <Button variant="outline" onClick={() => act('resume')}>
                                        Resume
                                    </Button>
                                )}
                                {(job.state === 'active' || job.state === 'paused') && (
                                    <Button variant="destructive" onClick={() => act('cancel')}>
                                        Cancel
                                    </Button>
                                )}
                            </div>
                        )}
                    </div>
                    <dl className="mt-4 grid gap-2 text-sm sm:grid-cols-2 lg:grid-cols-4">
                        <div>
                            <dt className="text-muted-foreground">State</dt>
                            <dd>
                                {job.state} · {job.approval_state}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Kind</dt>
                            <dd className="font-mono text-xs">
                                {job.kind} {job.cron_expression ?? ''}
                                {job.interval_seconds ? `${job.interval_seconds}s` : ''}
                                {job.event_type ?? ''}
                                {job.run_at ? new Date(job.run_at).toLocaleString() : ''}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Tool</dt>
                            <dd className="font-mono text-xs">{job.tool_key}</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Timezone</dt>
                            <dd>{job.timezone}</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Next run (UTC)</dt>
                            <dd>{job.next_run_at ?? '-'}</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Last run</dt>
                            <dd>
                                {job.last_run_at ?? '-'} {job.last_status ?? ''}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Retries / backoff</dt>
                            <dd>
                                {job.retry_max} · {job.retry_backoff_seconds}s
                            </dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Missed policy</dt>
                            <dd>{job.missed_policy}</dd>
                        </div>
                        <div className="sm:col-span-2 lg:col-span-4">
                            <dt className="text-muted-foreground">Payload</dt>
                            <dd className="font-mono text-xs break-all">{JSON.stringify(job.payload ?? {})}</dd>
                        </div>
                    </dl>
                </div>

                <div className="overflow-x-auto rounded-xl border">
                    <div className="border-b px-4 py-3 text-sm font-medium">Run history ({runs.total})</div>
                    <table className="w-full text-sm">
                        <thead className="text-muted-foreground text-left text-xs uppercase">
                            <tr>
                                <th className="px-4 py-2">Slot (UTC)</th>
                                <th className="px-4 py-2">Trigger</th>
                                <th className="px-4 py-2">Status</th>
                                <th className="px-4 py-2">Attempts</th>
                                <th className="px-4 py-2">Result</th>
                                <th className="px-4 py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            {runs.data.map((r) => (
                                <tr key={r.id} className="border-t align-top">
                                    <td className="px-4 py-2 whitespace-nowrap">{r.scheduled_for}</td>
                                    <td className="px-4 py-2">{r.trigger}</td>
                                    <td
                                        className={`px-4 py-2 font-medium ${r.status === 'succeeded' ? 'text-green-700' : r.status === 'failed' || r.status === 'dead' ? 'text-red-600' : ''}`}
                                    >
                                        {r.status}
                                    </td>
                                    <td className="px-4 py-2 text-xs">
                                        {r.attempt}
                                        {r.attempts ? ` (${r.attempts.map((a) => `${a.attempt}:${a.status}`).join(', ')})` : ''}
                                    </td>
                                    <td className="px-4 py-2 font-mono text-xs break-all">{r.error ?? JSON.stringify(r.output?.output ?? {})}</td>
                                    <td className="px-4 py-2">
                                        {canManage && (r.status === 'failed' || r.status === 'dead') && (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() => router.post(`/scheduler/runs/${r.id}/retry`, {}, { preserveScroll: true })}
                                            >
                                                Retry
                                            </Button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                            {runs.data.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="text-muted-foreground px-4 py-6 text-center">
                                        No runs yet.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
        </AppLayout>
    );
}
