import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';

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
type Paginated<T> = { data: T[]; links: { url: string | null; label: string; active: boolean }[]; total: number };

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Scheduler', href: '/scheduler' }];

export default function SchedulerIndex({ jobs, tools, filters }: { jobs: Paginated<Job>; tools: string[]; filters: { state: string } }) {
    const { auth } = usePage<{ auth: { permissions: string[] } }>().props;
    const canManage = auth.permissions.includes('hq.jobs.manage');
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
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Scheduler" />
            <div className="flex flex-col gap-6 p-4">
                {canManage && (
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post('/scheduler', { onSuccess: () => form.reset() });
                        }}
                        className="grid gap-3 rounded-xl border p-4 md:grid-cols-4"
                    >
                        <div className="text-sm font-medium md:col-span-4">New scheduled job</div>
                        <div>
                            <Label htmlFor="name">Name</Label>
                            <Input id="name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required />
                        </div>
                        <div>
                            <Label htmlFor="kind">Kind</Label>
                            <select
                                id="kind"
                                className="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                                value={form.data.kind}
                                onChange={(e) => form.setData('kind', e.target.value)}
                            >
                                {['once', 'cron', 'interval', 'event'].map((k) => (
                                    <option key={k} value={k}>
                                        {k}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <Label htmlFor="tool">Tool</Label>
                            <select
                                id="tool"
                                className="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                                value={form.data.tool_key}
                                onChange={(e) => form.setData('tool_key', e.target.value)}
                            >
                                {tools.map((t) => (
                                    <option key={t} value={t}>
                                        {t}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <Label htmlFor="tz">Timezone</Label>
                            <Input id="tz" value={form.data.timezone} onChange={(e) => form.setData('timezone', e.target.value)} />
                        </div>
                        {form.data.kind === 'once' && (
                            <div>
                                <Label htmlFor="run_at">Run at (local to timezone)</Label>
                                <Input
                                    id="run_at"
                                    type="datetime-local"
                                    value={form.data.run_at}
                                    onChange={(e) => form.setData('run_at', e.target.value)}
                                    required
                                />
                            </div>
                        )}
                        {form.data.kind === 'cron' && (
                            <div>
                                <Label htmlFor="cron">Cron expression</Label>
                                <Input
                                    id="cron"
                                    placeholder="0 9 * * *"
                                    value={form.data.cron_expression}
                                    onChange={(e) => form.setData('cron_expression', e.target.value)}
                                    required
                                />
                            </div>
                        )}
                        {form.data.kind === 'interval' && (
                            <div>
                                <Label htmlFor="interval">Interval (seconds, min 60)</Label>
                                <Input
                                    id="interval"
                                    type="number"
                                    min={60}
                                    value={form.data.interval_seconds}
                                    onChange={(e) => form.setData('interval_seconds', Number(e.target.value))}
                                    required
                                />
                            </div>
                        )}
                        {form.data.kind === 'event' && (
                            <div>
                                <Label htmlFor="event">Event type</Label>
                                <Input
                                    id="event"
                                    placeholder="order.created"
                                    value={form.data.event_type}
                                    onChange={(e) => form.setData('event_type', e.target.value)}
                                    required
                                />
                            </div>
                        )}
                        <div>
                            <Label htmlFor="missed">Missed-slot policy</Label>
                            <select
                                id="missed"
                                className="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                                value={form.data.missed_policy}
                                onChange={(e) => form.setData('missed_policy', e.target.value)}
                            >
                                {['skip', 'catch_up', 'run_latest_only'].map((k) => (
                                    <option key={k} value={k}>
                                        {k}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <Label htmlFor="retry">Retries</Label>
                            <Input
                                id="retry"
                                type="number"
                                min={0}
                                max={10}
                                value={form.data.retry_max}
                                onChange={(e) => form.setData('retry_max', Number(e.target.value))}
                            />
                        </div>
                        <div>
                            <Label htmlFor="msg">Payload message</Label>
                            <Input
                                id="msg"
                                value={form.data.payload.message}
                                onChange={(e) => form.setData('payload', { message: e.target.value })}
                            />
                        </div>
                        <div className="flex items-end">
                            <Button type="submit" disabled={form.processing}>
                                Create
                            </Button>
                        </div>
                        {Object.values(form.errors).length > 0 && (
                            <div className="text-sm text-red-600 md:col-span-4">{Object.values(form.errors).join(' ')}</div>
                        )}
                    </form>
                )}

                <div className="flex items-center gap-2 text-sm">
                    <span className="text-muted-foreground">Filter:</span>
                    {['', 'active', 'paused', 'cancelled', 'done'].map((s) => (
                        <Link
                            key={s || 'all'}
                            href={s ? `/scheduler?state=${s}` : '/scheduler'}
                            className={`rounded-md border px-2 py-1 ${filters.state === s ? 'bg-accent' : ''}`}
                        >
                            {s || 'all'}
                        </Link>
                    ))}
                    <span className="text-muted-foreground ml-auto">{jobs.total} jobs</span>
                </div>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead className="text-muted-foreground text-left text-xs uppercase">
                            <tr>
                                <th className="px-4 py-2">Name</th>
                                <th className="px-4 py-2">Kind</th>
                                <th className="px-4 py-2">Tool</th>
                                <th className="px-4 py-2">State</th>
                                <th className="px-4 py-2">Next run</th>
                                <th className="px-4 py-2">Last</th>
                            </tr>
                        </thead>
                        <tbody>
                            {jobs.data.map((j) => (
                                <tr key={j.id} className="border-t">
                                    <td className="px-4 py-2">
                                        <Link href={`/scheduler/${j.id}`} className="underline">
                                            {j.name}
                                        </Link>
                                        <div className="text-muted-foreground text-xs">
                                            {j.business?.name ?? ''}
                                            {j.brand ? ` · ${j.brand.name}` : ''}
                                        </div>
                                    </td>
                                    <td className="px-4 py-2 font-mono text-xs">
                                        {j.kind}
                                        {j.cron_expression ? ` ${j.cron_expression}` : ''}
                                        {j.interval_seconds ? ` ${j.interval_seconds}s` : ''}
                                        {j.event_type ? ` ${j.event_type}` : ''}
                                    </td>
                                    <td className="px-4 py-2 font-mono text-xs">{j.tool_key}</td>
                                    <td className="px-4 py-2">
                                        {j.state}
                                        {j.approval_state !== 'approved' ? ` (${j.approval_state})` : ''}
                                    </td>
                                    <td className="px-4 py-2 whitespace-nowrap">{j.next_run_at ? new Date(j.next_run_at).toLocaleString() : '-'}</td>
                                    <td className="px-4 py-2 whitespace-nowrap">
                                        {j.last_run_at ? `${new Date(j.last_run_at).toLocaleString()} · ${j.last_status ?? ''}` : '-'}
                                    </td>
                                </tr>
                            ))}
                            {jobs.data.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="text-muted-foreground px-4 py-6 text-center">
                                        No scheduled jobs.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
                <div className="flex flex-wrap gap-1 text-sm">
                    {jobs.links.map((l, i) =>
                        l.url ? (
                            <Link
                                key={i}
                                href={l.url}
                                className={`rounded border px-2 py-1 ${l.active ? 'bg-accent' : ''}`}
                                dangerouslySetInnerHTML={{ __html: l.label }}
                            />
                        ) : (
                            <span key={i} className="text-muted-foreground px-2 py-1" dangerouslySetInnerHTML={{ __html: l.label }} />
                        ),
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
