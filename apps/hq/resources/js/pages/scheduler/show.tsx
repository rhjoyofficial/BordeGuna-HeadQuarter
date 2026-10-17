import { ConfirmDialog } from '@/components/confirm-dialog';
import { EmptyState } from '@/components/empty-state';
import { JsonBlock, PageHeader, SectionHeader, TechnicalDetail } from '@/components/page-header';
import { jobState } from '@/components/scheduler/job-state';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, humanDuration, humanKey, humanMillis, humanSchedule, timeAgo } from '@/lib/format';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { type ReactNode, useState } from 'react';

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

const MISSED: Record<string, string> = {
    skip: 'skip it',
    catch_up: 'run every missed slot',
    run_latest_only: 'run the latest slot only',
};

function Fact({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="min-w-0">
            <dt className="text-muted-foreground text-xs">{label}</dt>
            <dd className="text-sm break-words">{children}</dd>
        </div>
    );
}

export default function SchedulerShow({ job, runs }: { job: Job; runs: Paginated<Run> }) {
    const { auth } = usePage<{ auth: { permissions: string[] } }>().props;
    const canManage = auth.permissions.includes('hq.jobs.manage');
    const [confirmCancel, setConfirmCancel] = useState(false);
    const [busy, setBusy] = useState(false);
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Scheduler', href: '/scheduler' },
        { title: job.name, href: `/scheduler/${job.id}` },
    ];
    const act = (action: string) => {
        setBusy(true);
        router.post(`/scheduler/${job.id}/${action}`, {}, { preserveScroll: true, onFinish: () => setBusy(false) });
    };
    const st = jobState(job);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={job.name} />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={
                        <span className="flex flex-wrap items-center gap-2">
                            {job.name}
                            <StatusBadge tone={st.tone}>{st.label}</StatusBadge>
                        </span>
                    }
                    description={
                        <>
                            {humanSchedule(job)} ({job.timezone}) · runs <span title={job.tool_key}>{humanKey(job.tool_key)}</span>
                            {job.business ? ` · ${job.business.name}` : ''}
                            {job.brand ? ` · ${job.brand.name}` : ''}
                        </>
                    }
                    actions={
                        canManage ? (
                            <>
                                {job.approval_state === 'pending' && (
                                    <>
                                        <Button disabled={busy} onClick={() => act('approve')}>
                                            Approve job
                                        </Button>
                                        <Button variant="outline" disabled={busy} onClick={() => act('reject')}>
                                            Reject
                                        </Button>
                                    </>
                                )}
                                {job.state !== 'cancelled' && job.approval_state === 'approved' && (
                                    <Button variant="outline" disabled={busy} onClick={() => act('run-now')}>
                                        Run now
                                    </Button>
                                )}
                                {job.state === 'active' && (
                                    <Button variant="outline" disabled={busy} onClick={() => act('pause')}>
                                        Pause
                                    </Button>
                                )}
                                {job.state === 'paused' && (
                                    <Button disabled={busy} onClick={() => act('resume')}>
                                        Resume
                                    </Button>
                                )}
                                {(job.state === 'active' || job.state === 'paused') && (
                                    <Button variant="destructive" disabled={busy} onClick={() => setConfirmCancel(true)}>
                                        Cancel job
                                    </Button>
                                )}
                            </>
                        ) : undefined
                    }
                />

                <section className="hq-card p-4">
                    <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <Fact label="Next run">
                            {job.next_run_at ? (
                                <>
                                    {timeAgo(job.next_run_at)}
                                    <div className="text-muted-foreground text-xs">{formatDateTime(job.next_run_at)}</div>
                                </>
                            ) : (
                                <span className="text-muted-foreground">not scheduled</span>
                            )}
                        </Fact>
                        <Fact label="Last run">
                            {job.last_run_at ? (
                                <div className="flex flex-wrap items-center gap-2">
                                    <StatusBadge status={job.last_status ?? 'unknown'} />
                                    <span className="text-muted-foreground text-xs" title={formatDateTime(job.last_run_at)}>
                                        {timeAgo(job.last_run_at)}
                                    </span>
                                </div>
                            ) : (
                                <span className="text-muted-foreground">never</span>
                            )}
                        </Fact>
                        <Fact label="If a slot is missed">{MISSED[job.missed_policy] ?? job.missed_policy}</Fact>
                        <Fact label="Retries">
                            {job.retry_max === 0 ? 'none' : `up to ${job.retry_max}, ${humanDuration(job.retry_backoff_seconds)} apart`}
                        </Fact>
                        <Fact label="Created">
                            {humanKey(job.created_from).toLowerCase()}
                            {job.owner ? ` by ${job.owner.name || job.owner.email}` : ''}
                        </Fact>
                        <Fact label="Approval">{humanKey(job.approval_state)}</Fact>
                    </dl>
                    <div className="mt-4">
                        <TechnicalDetail summary={`Technical detail · ${job.tool_key} · ${job.uuid}`}>
                            <dl className="text-muted-foreground mb-2 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 font-mono text-xs">
                                <dt>kind</dt>
                                <dd>{job.kind}</dd>
                                <dt>expression</dt>
                                <dd>
                                    {job.cron_expression ??
                                        job.event_type ??
                                        (job.interval_seconds ? `${job.interval_seconds}s` : (job.run_at ?? '-'))}
                                </dd>
                            </dl>
                            <div className="text-muted-foreground mb-1 text-xs">Payload</div>
                            <JsonBlock value={job.payload ?? {}} />
                        </TechnicalDetail>
                    </div>
                </section>

                <section className="flex flex-col gap-2">
                    <SectionHeader title="Run history" aside={`${runs.total} ${runs.total === 1 ? 'run' : 'runs'}`} />
                    {runs.data.length === 0 ? (
                        <EmptyState
                            title="No runs yet"
                            description="The first run appears here when the schedule fires, or when you press Run now."
                        />
                    ) : (
                        <div className="hq-table-box">
                            <table className="w-full text-sm">
                                <thead className="text-left text-xs">
                                    <tr>
                                        <th className="px-3 py-2 font-medium">Slot</th>
                                        <th className="px-3 py-2 font-medium">Started by</th>
                                        <th className="px-3 py-2 font-medium">Outcome</th>
                                        <th className="px-3 py-2 font-medium">Attempts</th>
                                        <th className="px-3 py-2 font-medium">Result</th>
                                        <th className="px-3 py-2"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {runs.data.map((r) => (
                                        <tr key={r.id} className="border-t align-top">
                                            <td className="px-3 py-2 whitespace-nowrap" title={r.scheduled_for}>
                                                {formatDateTime(r.scheduled_for)}
                                                <div className="text-muted-foreground text-xs">{timeAgo(r.scheduled_for)}</div>
                                            </td>
                                            <td className="px-3 py-2">{humanKey(r.trigger).toLowerCase()}</td>
                                            <td className="px-3 py-2">
                                                <StatusBadge status={r.status} />
                                            </td>
                                            <td className="px-3 py-2 text-xs">
                                                {r.attempts && r.attempts.length > 1 ? (
                                                    <ul className="space-y-0.5">
                                                        {r.attempts.map((a) => (
                                                            <li key={a.attempt} className="flex items-center gap-1.5">
                                                                <span className="text-muted-foreground">#{a.attempt}</span>
                                                                <StatusBadge status={a.status} dot={false} />
                                                                <span className="text-muted-foreground">{humanMillis(a.duration_ms)}</span>
                                                            </li>
                                                        ))}
                                                    </ul>
                                                ) : (
                                                    <span>
                                                        {r.attempt}{' '}
                                                        {r.attempts?.[0]?.duration_ms !== undefined
                                                            ? `· ${humanMillis(r.attempts[0].duration_ms)}`
                                                            : ''}
                                                    </span>
                                                )}
                                            </td>
                                            <td className="max-w-md px-3 py-2 text-xs">
                                                {r.error ? (
                                                    <span className="text-tone-danger">{r.error}</span>
                                                ) : r.output?.output && Object.keys(r.output.output).length > 0 ? (
                                                    <TechnicalDetail summary="Output">
                                                        <JsonBlock value={r.output.output} />
                                                    </TechnicalDetail>
                                                ) : (
                                                    <span className="text-muted-foreground">-</span>
                                                )}
                                            </td>
                                            <td className="px-3 py-2 text-right">
                                                {canManage && (r.status === 'failed' || r.status === 'dead') && (
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        disabled={busy}
                                                        onClick={() => {
                                                            setBusy(true);
                                                            router.post(
                                                                `/scheduler/runs/${r.id}/retry`,
                                                                {},
                                                                { preserveScroll: true, onFinish: () => setBusy(false) },
                                                            );
                                                        }}
                                                    >
                                                        Retry
                                                    </Button>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>
            </div>

            <ConfirmDialog
                open={confirmCancel}
                onOpenChange={setConfirmCancel}
                title={`Cancel ${job.name}?`}
                description="The job stops for good and cannot be resumed; its run history stays. To stop it for a while, use Pause instead."
                confirmLabel="Cancel the job"
                destructive
                busy={busy}
                onConfirm={() => {
                    setConfirmCancel(false);
                    act('cancel');
                }}
            />
        </AppLayout>
    );
}
