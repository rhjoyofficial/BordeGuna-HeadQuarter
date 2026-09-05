import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';

type Counts = Record<string, number>;
type Audit = {
    id: number;
    occurred_at: string;
    actor_type: string;
    actor_label: string | null;
    action: string;
    target_type: string | null;
    target_id: string | null;
};

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboard', href: '/dashboard' }];

const tiles: { key: keyof Counts & string; label: string }[] = [
    { key: 'businesses', label: 'Businesses' },
    { key: 'brands', label: 'Brands' },
    { key: 'stores', label: 'Stores' },
    { key: 'platform_accounts', label: 'Platform accounts' },
    { key: 'credentials', label: 'Credentials' },
    { key: 'jobs_active', label: 'Active scheduled jobs' },
    { key: 'runs_24h', label: 'Job runs (24 h)' },
    { key: 'runs_failed_24h', label: 'Failed / dead runs (24 h)' },
    { key: 'events_pending', label: 'Events pending' },
    { key: 'events_dead', label: 'Events dead' },
    { key: 'audit_24h', label: 'Audit entries (24 h)' },
];

export default function Dashboard({
    counts,
    scheduler,
    recentAudit,
}: {
    counts: Counts;
    scheduler: { last_tick: string | null };
    recentAudit: Audit[];
}) {
    const tickAge = scheduler.last_tick ? Math.round((Date.now() - new Date(scheduler.last_tick).getTime()) / 1000) : null;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />
            <div className="flex flex-col gap-6 p-4">
                <div className="rounded-xl border p-4 text-sm">
                    <span className="font-medium">Scheduler tick:</span>{' '}
                    {tickAge === null ? (
                        <span className="text-red-600">never recorded</span>
                    ) : tickAge <= 180 ? (
                        <span className="text-green-700">ok, {tickAge}s ago</span>
                    ) : (
                        <span className="text-red-600">stale, {tickAge}s ago</span>
                    )}
                </div>
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    {tiles.map((t) => (
                        <div key={t.key} className="rounded-xl border p-4">
                            <div className="text-muted-foreground text-xs tracking-wide uppercase">{t.label}</div>
                            <div className="mt-1 text-2xl font-semibold tabular-nums">{counts[t.key] ?? 0}</div>
                        </div>
                    ))}
                </div>
                <div className="rounded-xl border">
                    <div className="border-b px-4 py-3 text-sm font-medium">Recent audit entries</div>
                    <table className="w-full text-sm">
                        <tbody>
                            {recentAudit.map((a) => (
                                <tr key={a.id} className="border-b last:border-0">
                                    <td className="text-muted-foreground px-4 py-2 whitespace-nowrap">{new Date(a.occurred_at).toLocaleString()}</td>
                                    <td className="px-4 py-2">
                                        {a.actor_type}
                                        {a.actor_label ? ` · ${a.actor_label}` : ''}
                                    </td>
                                    <td className="px-4 py-2 font-mono text-xs">{a.action}</td>
                                    <td className="text-muted-foreground px-4 py-2 font-mono text-xs">
                                        {a.target_type ? `${a.target_type.split('\\').pop()}#${a.target_id ?? ''}` : ''}
                                    </td>
                                </tr>
                            ))}
                            {recentAudit.length === 0 && (
                                <tr>
                                    <td className="text-muted-foreground px-4 py-6 text-center" colSpan={4}>
                                        No audit entries yet.
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
