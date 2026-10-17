import { EmptyState } from '@/components/empty-state';
import { Field, FormRow, NativeSelect } from '@/components/form/field';
import { PageHeader } from '@/components/page-header';
import { Pagination, type PageLink } from '@/components/pagination';
import { jobState } from '@/components/scheduler/job-state';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, humanKey, humanSchedule, timeAgo } from '@/lib/format';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { CalendarClock, Plus } from 'lucide-react';
import { useState } from 'react';

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
    next_run_at: string | null;
    last_run_at: string | null;
    last_status: string | null;
    business?: { name: string } | null;
    brand?: { name: string } | null;
};
type Paginated<T> = { data: T[]; links: PageLink[]; total: number };

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Scheduler', href: '/scheduler' }];

const STATES: { key: string; label: string }[] = [
    { key: '', label: 'All' },
    { key: 'active', label: 'Active' },
    { key: 'paused', label: 'Paused' },
    { key: 'done', label: 'Done' },
    { key: 'cancelled', label: 'Cancelled' },
];

const KINDS: { key: string; label: string; hint: string }[] = [
    { key: 'once', label: 'Once', hint: 'at a date and time' },
    { key: 'cron', label: 'On a timetable', hint: 'a cron expression' },
    { key: 'interval', label: 'Every so often', hint: 'a fixed number of seconds' },
    { key: 'event', label: 'When something happens', hint: 'an event type' },
];

const MISSED: Record<string, string> = {
    skip: 'Skip it',
    catch_up: 'Run every missed slot',
    run_latest_only: 'Run the latest slot only',
};

function NewJobDialog({ tools, open, onOpenChange }: { tools: string[]; open: boolean; onOpenChange: (o: boolean) => void }) {
    const form = useForm({
        name: '',
        kind: 'once',
        tool_key: tools[0] ?? 'noop',
        run_at: '',
        cron_expression: '',
        interval_seconds: 3600,
        event_type: '',
        timezone: 'Asia/Dhaka',
        missed_policy: 'skip',
        retry_max: 3,
        payload: { message: '' },
    });

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-2xl">
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/scheduler', {
                            onSuccess: () => {
                                form.reset();
                                onOpenChange(false);
                            },
                        });
                    }}
                    className="flex flex-col gap-5"
                >
                    <DialogHeader>
                        <DialogTitle>New scheduled job</DialogTitle>
                        <DialogDescription>
                            A job runs one tool on a schedule, through the same gateway as everything else. A tool that needs approval still needs it
                            on every run.
                        </DialogDescription>
                    </DialogHeader>

                    <FormRow>
                        <Field label="Name" htmlFor="name" size="grow" error={form.errors.name}>
                            <Input id="name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required autoFocus />
                        </Field>
                        <Field label="Tool" htmlFor="tool" size="auto" error={form.errors.tool_key}>
                            <NativeSelect id="tool" value={form.data.tool_key} onChange={(e) => form.setData('tool_key', e.target.value)}>
                                {tools.map((t) => (
                                    <option key={t} value={t}>
                                        {humanKey(t)}
                                    </option>
                                ))}
                            </NativeSelect>
                        </Field>
                    </FormRow>

                    <div className="grid gap-1.5">
                        <div className="text-sm font-medium">When</div>
                        <div className="grid gap-2 sm:grid-cols-4">
                            {KINDS.map((k) => (
                                <button
                                    key={k.key}
                                    type="button"
                                    onClick={() => form.setData('kind', k.key)}
                                    aria-pressed={form.data.kind === k.key}
                                    className={`rounded-lg border p-2.5 text-left text-sm transition-colors ${
                                        form.data.kind === k.key ? 'border-primary bg-accent' : 'hover:bg-accent/50'
                                    }`}
                                >
                                    <div className="font-medium">{k.label}</div>
                                    <div className="text-muted-foreground text-xs">{k.hint}</div>
                                </button>
                            ))}
                        </div>
                    </div>

                    <FormRow>
                        {form.data.kind === 'once' && (
                            <Field label="Run at" htmlFor="run_at" size="auto" hint="Local to the timezone" error={form.errors.run_at}>
                                <Input
                                    id="run_at"
                                    type="datetime-local"
                                    value={form.data.run_at}
                                    onChange={(e) => form.setData('run_at', e.target.value)}
                                    required
                                />
                            </Field>
                        )}
                        {form.data.kind === 'cron' && (
                            <Field
                                label="Cron expression"
                                htmlFor="cron"
                                size="sm"
                                hint={
                                    form.data.cron_expression
                                        ? humanSchedule({ kind: 'cron', cron_expression: form.data.cron_expression })
                                        : 'minute hour day month weekday'
                                }
                                error={form.errors.cron_expression}
                            >
                                <Input
                                    id="cron"
                                    placeholder="0 9 * * *"
                                    className="font-mono"
                                    value={form.data.cron_expression}
                                    onChange={(e) => form.setData('cron_expression', e.target.value)}
                                    required
                                />
                            </Field>
                        )}
                        {form.data.kind === 'interval' && (
                            <Field label="Every (seconds)" htmlFor="interval" size="sm" hint="At least 60" error={form.errors.interval_seconds}>
                                <Input
                                    id="interval"
                                    type="number"
                                    min={60}
                                    value={form.data.interval_seconds}
                                    onChange={(e) => form.setData('interval_seconds', Number(e.target.value))}
                                    required
                                />
                            </Field>
                        )}
                        {form.data.kind === 'event' && (
                            <Field label="Event type" htmlFor="event" size="md" error={form.errors.event_type}>
                                <Input
                                    id="event"
                                    placeholder="order.created"
                                    className="font-mono"
                                    value={form.data.event_type}
                                    onChange={(e) => form.setData('event_type', e.target.value)}
                                    required
                                />
                            </Field>
                        )}
                        <Field label="Timezone" htmlFor="tz" size="md" error={form.errors.timezone}>
                            <Input id="tz" value={form.data.timezone} onChange={(e) => form.setData('timezone', e.target.value)} />
                        </Field>
                    </FormRow>

                    <FormRow>
                        <Field label="If a slot is missed" htmlFor="missed" size="auto" error={form.errors.missed_policy}>
                            <NativeSelect id="missed" value={form.data.missed_policy} onChange={(e) => form.setData('missed_policy', e.target.value)}>
                                {Object.entries(MISSED).map(([k, label]) => (
                                    <option key={k} value={k}>
                                        {label}
                                    </option>
                                ))}
                            </NativeSelect>
                        </Field>
                        <Field label="Retries" htmlFor="retry" size="xs" error={form.errors.retry_max}>
                            <Input
                                id="retry"
                                type="number"
                                min={0}
                                max={10}
                                className="w-full"
                                value={form.data.retry_max}
                                onChange={(e) => form.setData('retry_max', Number(e.target.value))}
                            />
                        </Field>
                        <Field label="Message to the tool" htmlFor="msg" size="grow" hint="Optional">
                            <Input
                                id="msg"
                                value={form.data.payload.message}
                                onChange={(e) => form.setData('payload', { message: e.target.value })}
                            />
                        </Field>
                    </FormRow>

                    {Object.values(form.errors).length > 0 && (
                        <div className="text-sm text-tone-danger">{Object.values(form.errors).join(' ')}</div>
                    )}

                    <DialogFooter className="gap-2 sm:gap-0">
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            Create job
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function SchedulerIndex({ jobs, tools, filters }: { jobs: Paginated<Job>; tools: string[]; filters: { state: string } }) {
    const { auth, nav } = usePage<{ auth: { permissions: string[] }; nav?: { features?: Record<string, boolean> } }>().props;
    const canManage = auth.permissions.includes('hq.jobs.manage');
    const inWords = canManage && (nav?.features?.scheduler_nl ?? false);
    const [creating, setCreating] = useState(false);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Scheduler" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Scheduler"
                    description="Work HQ does on its own clock: polls, syncs and checks, each running one tool through the gateway. Pause a job to stop it without losing it."
                    actions={
                        canManage ? (
                            <>
                                {inWords && (
                                    <Button variant="outline" asChild>
                                        <Link href="/scheduler/nl">Describe a job</Link>
                                    </Button>
                                )}
                                <Button onClick={() => setCreating(true)}>
                                    <Plus className="size-4" aria-hidden /> New job
                                </Button>
                            </>
                        ) : undefined
                    }
                >
                    <div className="flex flex-wrap items-center gap-2">
                        {STATES.map((s) => (
                            <Button
                                key={s.key || 'all'}
                                size="sm"
                                variant={filters.state === s.key ? 'default' : 'outline'}
                                onClick={() => router.get('/scheduler', s.key ? { state: s.key } : {}, { preserveState: true, replace: true })}
                            >
                                {s.label}
                            </Button>
                        ))}
                        <span className="text-muted-foreground ml-auto text-sm">
                            {jobs.total} {jobs.total === 1 ? 'job' : 'jobs'}
                        </span>
                    </div>
                </PageHeader>

                {jobs.data.length === 0 ? (
                    <EmptyState
                        icon={CalendarClock}
                        title={filters.state ? `No ${filters.state} jobs` : 'No scheduled jobs'}
                        description={filters.state ? 'Try another filter.' : 'Create one to have HQ run a tool on a schedule.'}
                        action={
                            canManage && !filters.state ? (
                                <Button size="sm" onClick={() => setCreating(true)}>
                                    New job
                                </Button>
                            ) : undefined
                        }
                    />
                ) : (
                    <div className="hq-table-box">
                        <table className="w-full text-sm">
                            <thead className="text-left text-xs">
                                <tr>
                                    <th className="px-3 py-2 font-medium">Job</th>
                                    <th className="px-3 py-2 font-medium">Schedule</th>
                                    <th className="px-3 py-2 font-medium">Runs</th>
                                    <th className="px-3 py-2 font-medium">State</th>
                                    <th className="px-3 py-2 font-medium">Next run</th>
                                    <th className="px-3 py-2 font-medium">Last run</th>
                                </tr>
                            </thead>
                            <tbody>
                                {jobs.data.map((j) => {
                                    const st = jobState(j);
                                    return (
                                        <tr key={j.id} className="border-t align-top">
                                            <td className="px-3 py-2">
                                                <Link href={`/scheduler/${j.id}`} className="font-medium hover:underline">
                                                    {j.name}
                                                </Link>
                                                {(j.business || j.brand) && (
                                                    <div className="text-muted-foreground text-xs">
                                                        {j.business?.name ?? ''}
                                                        {j.brand ? ` · ${j.brand.name}` : ''}
                                                    </div>
                                                )}
                                            </td>
                                            <td className="px-3 py-2">
                                                <div>{humanSchedule(j)}</div>
                                                <div className="text-muted-foreground font-mono text-[11px]">
                                                    {j.cron_expression ?? (j.interval_seconds ? `${j.interval_seconds}s` : (j.event_type ?? ''))}{' '}
                                                    {j.timezone}
                                                </div>
                                            </td>
                                            <td className="px-3 py-2" title={j.tool_key}>
                                                {humanKey(j.tool_key)}
                                            </td>
                                            <td className="px-3 py-2">
                                                <StatusBadge tone={st.tone}>{st.label}</StatusBadge>
                                            </td>
                                            <td className="px-3 py-2 whitespace-nowrap" title={formatDateTime(j.next_run_at)}>
                                                {j.next_run_at ? timeAgo(j.next_run_at) : <span className="text-muted-foreground">-</span>}
                                            </td>
                                            <td className="px-3 py-2 whitespace-nowrap">
                                                {j.last_run_at ? (
                                                    <div className="flex items-center gap-2">
                                                        <StatusBadge status={j.last_status ?? 'unknown'} />
                                                        <span className="text-muted-foreground text-xs" title={formatDateTime(j.last_run_at)}>
                                                            {timeAgo(j.last_run_at)}
                                                        </span>
                                                    </div>
                                                ) : (
                                                    <span className="text-muted-foreground">never</span>
                                                )}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}
                <Pagination links={jobs.links} total={jobs.total} noun="jobs" />
            </div>

            {canManage && <NewJobDialog tools={tools} open={creating} onOpenChange={setCreating} />}
        </AppLayout>
    );
}
