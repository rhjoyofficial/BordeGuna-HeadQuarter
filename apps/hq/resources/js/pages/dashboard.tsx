import { EmptyState } from '@/components/empty-state';
import { PageHeader, SectionHeader } from '@/components/page-header';
import { StatusBadge, type Tone } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, humanKey, shortClass, timeAgo } from '@/lib/format';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowRight, CheckCircle2 } from 'lucide-react';

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
type Attention = { key: string; label: string; count: number; href: string; permission: string; tone: Tone };
type Health = {
    scheduler: { last_tick: string | null; stale_after_seconds: number };
    ai_runtime: boolean;
    agents: { key: string; name: string; status: string; autonomy: string | null }[];
    events: { pending: number; dead: number; last_at: string | null };
    connectors_24h: { total: number; ok: number };
};
type BusinessCard = {
    id: number;
    name: string;
    slug: string;
    is_active: boolean;
    timezone: string;
    brands: string[];
    stores: { name: string; adapter: string; is_active: boolean }[];
    orders_24h: number;
    last_order_at: string | null;
};

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Today', href: '/dashboard' }];

const tiles: { key: keyof Counts & string; label: string }[] = [
    { key: 'businesses', label: 'Businesses' },
    { key: 'brands', label: 'Brands' },
    { key: 'stores', label: 'Stores' },
    { key: 'platform_accounts', label: 'Platform accounts' },
    { key: 'credentials', label: 'Credentials' },
    { key: 'jobs_active', label: 'Active scheduled jobs' },
    { key: 'runs_24h', label: 'Job runs, last 24 h' },
    { key: 'runs_failed_24h', label: 'Failed runs, last 24 h' },
    { key: 'events_pending', label: 'Events waiting' },
    { key: 'events_dead', label: 'Events dead' },
    { key: 'audit_24h', label: 'Audit entries, last 24 h' },
];

function HealthItem({ label, value, tone, detail }: { label: string; value: string; tone: Tone; detail?: string }) {
    return (
        <div className="bg-card flex min-w-0 flex-col gap-1 rounded-xl border p-4">
            <div className="text-muted-foreground text-xs tracking-wide uppercase">{label}</div>
            <div className="flex flex-wrap items-center gap-2">
                <StatusBadge tone={tone}>{value}</StatusBadge>
                {detail && <span className="text-muted-foreground truncate text-xs">{detail}</span>}
            </div>
        </div>
    );
}

export default function Dashboard({
    attention,
    health,
    businesses,
    counts,
    recentAudit,
}: {
    attention: Attention[];
    health: Health;
    businesses: BusinessCard[];
    counts: Counts;
    scheduler: { last_tick: string | null };
    recentAudit: Audit[];
}) {
    const { auth } = usePage<{ auth: { permissions: string[]; user: { name?: string } | null } }>().props;
    const permissions = auth?.permissions ?? [];

    const visible = attention.filter((a) => permissions.includes(a.permission));
    const open = visible.filter((a) => a.count > 0);

    const tickAge = health.scheduler.last_tick ? Math.round((Date.now() - new Date(health.scheduler.last_tick).getTime()) / 1000) : null;
    const schedulerTone: Tone = tickAge === null ? 'danger' : tickAge <= health.scheduler.stale_after_seconds ? 'ok' : 'danger';
    const schedulerValue = tickAge === null ? 'never ticked' : tickAge <= health.scheduler.stale_after_seconds ? 'running' : 'stale';

    const connectorsTone: Tone =
        health.connectors_24h.total === 0 ? 'neutral' : health.connectors_24h.ok === health.connectors_24h.total ? 'ok' : 'warn';
    const eventsTone: Tone = health.events.dead > 0 ? 'danger' : health.events.pending > 0 ? 'pending' : 'ok';

    const greeting = (() => {
        const h = new Date().getHours();
        return h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : 'Good evening';
    })();

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Today" />
            <div className="flex flex-col gap-8 p-4 md:p-6">
                <PageHeader
                    title={`${greeting}${auth?.user?.name ? `, ${auth.user.name.split(' ')[0]}` : ''}`}
                    description={
                        open.length === 0
                            ? 'Nothing is waiting for you. Everything below is for checking that the machinery is running.'
                            : `${open.length} ${open.length === 1 ? 'thing needs' : 'things need'} your attention.`
                    }
                />

                <section className="flex flex-col gap-3">
                    <SectionHeader title="Needs you" />
                    {open.length === 0 ? (
                        <EmptyState
                            icon={CheckCircle2}
                            title="All clear"
                            description="No approvals waiting, no shadow runs to read, no failed runs in the last day."
                        />
                    ) : (
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            {open.map((a) => (
                                <Link
                                    key={a.key}
                                    href={a.href}
                                    className="group bg-card hover:bg-accent/60 flex items-center justify-between gap-3 rounded-xl border p-4 transition-colors"
                                >
                                    <div className="min-w-0">
                                        <div className="text-2xl font-semibold tabular-nums">{a.count}</div>
                                        <div className="text-muted-foreground text-sm">{a.label}</div>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <StatusBadge tone={a.tone} dot={false}>
                                            {a.tone === 'danger' ? 'fix' : a.tone === 'warn' ? 'look' : 'decide'}
                                        </StatusBadge>
                                        <ArrowRight
                                            className="text-muted-foreground size-4 transition-transform group-hover:translate-x-0.5"
                                            aria-hidden
                                        />
                                    </div>
                                </Link>
                            ))}
                        </div>
                    )}
                </section>

                <section className="flex flex-col gap-3">
                    <SectionHeader title="Is HQ running" />
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <HealthItem
                            label="Scheduler"
                            value={schedulerValue}
                            tone={schedulerTone}
                            detail={tickAge === null ? undefined : `last tick ${timeAgo(health.scheduler.last_tick)}`}
                        />
                        <HealthItem
                            label="AI runtime"
                            value={health.ai_runtime ? 'on' : 'off'}
                            tone={health.ai_runtime ? 'ok' : 'neutral'}
                            detail={
                                health.agents.length
                                    ? health.agents
                                          .map((a) => `${a.name}: ${a.status}${a.autonomy ? ` ${a.autonomy.toUpperCase()}` : ''}`)
                                          .join(' · ')
                                    : 'no agents registered'
                            }
                        />
                        <HealthItem
                            label="Event inbox"
                            value={
                                health.events.dead > 0
                                    ? `${health.events.dead} dead`
                                    : health.events.pending > 0
                                      ? `${health.events.pending} waiting`
                                      : 'clear'
                            }
                            tone={eventsTone}
                            detail={health.events.last_at ? `last event ${timeAgo(health.events.last_at)}` : 'no events yet'}
                        />
                        <HealthItem
                            label="Connectors, 24 h"
                            value={
                                health.connectors_24h.total === 0 ? 'no calls' : `${health.connectors_24h.ok} of ${health.connectors_24h.total} ok`
                            }
                            tone={connectorsTone}
                        />
                    </div>
                </section>

                <section className="flex flex-col gap-3">
                    <SectionHeader
                        title="Businesses"
                        aside={
                            permissions.includes('hq.businesses.view') ? (
                                <Link href="/businesses" className="hover:underline">
                                    All details
                                </Link>
                            ) : undefined
                        }
                    />
                    {businesses.length === 0 ? (
                        <EmptyState title="No businesses yet" description="Businesses are seeded from config and mastered by the ERP." />
                    ) : (
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            {businesses.map((b) => (
                                <div key={b.id} className="bg-card flex flex-col gap-3 rounded-xl border p-4">
                                    <div className="flex items-start justify-between gap-2">
                                        <div className="min-w-0">
                                            <div className="truncate font-semibold">{b.name}</div>
                                            {b.brands.length > 0 && (
                                                <div className="text-muted-foreground truncate text-xs">{b.brands.join(', ')}</div>
                                            )}
                                        </div>
                                        <StatusBadge status={b.is_active ? 'active' : 'inactive'} />
                                    </div>
                                    <div className="flex items-baseline gap-4">
                                        <div>
                                            <div className="text-2xl font-semibold tabular-nums">{b.orders_24h}</div>
                                            <div className="text-muted-foreground text-xs">orders, last 24 h</div>
                                        </div>
                                        <div className="text-muted-foreground text-xs">
                                            {b.last_order_at ? `last order ${timeAgo(b.last_order_at)}` : 'no orders recorded'}
                                        </div>
                                    </div>
                                    {b.stores.length > 0 && (
                                        <ul className="text-muted-foreground flex flex-wrap gap-x-3 gap-y-1 text-xs">
                                            {b.stores.map((s) => (
                                                <li key={s.name} className="flex items-center gap-1">
                                                    <span
                                                        className={`size-1.5 rounded-full ${s.is_active ? 'bg-emerald-600' : 'bg-muted-foreground/50'}`}
                                                        aria-hidden
                                                    />
                                                    {s.name}
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </div>
                            ))}
                        </div>
                    )}
                </section>

                <section className="flex flex-col gap-3">
                    <SectionHeader title="By the numbers" />
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        {tiles.map((t) => (
                            <div key={t.key} className="bg-card rounded-xl border p-4">
                                <div className="text-muted-foreground text-xs tracking-wide uppercase">{t.label}</div>
                                <div className="mt-1 text-2xl font-semibold tabular-nums">{counts[t.key] ?? 0}</div>
                            </div>
                        ))}
                    </div>
                </section>

                <section className="flex flex-col gap-3">
                    <SectionHeader
                        title="Recent activity"
                        aside={
                            permissions.includes('hq.audit.view') ? (
                                <Link href="/audit" className="hover:underline">
                                    Full audit log
                                </Link>
                            ) : undefined
                        }
                    />
                    <div className="bg-card overflow-x-auto rounded-xl border">
                        <table className="w-full text-sm">
                            <tbody>
                                {recentAudit.map((a) => (
                                    <tr key={a.id} className="border-b last:border-0">
                                        <td className="text-muted-foreground px-4 py-2 whitespace-nowrap" title={formatDateTime(a.occurred_at)}>
                                            {timeAgo(a.occurred_at)}
                                        </td>
                                        <td className="px-4 py-2">{humanKey(a.action)}</td>
                                        <td className="text-muted-foreground px-4 py-2 text-xs">{a.actor_label ?? a.actor_type}</td>
                                        <td className="text-muted-foreground px-4 py-2 text-xs whitespace-nowrap">
                                            {a.target_type ? `${shortClass(a.target_type)} #${a.target_id ?? ''}` : ''}
                                        </td>
                                    </tr>
                                ))}
                                {recentAudit.length === 0 && (
                                    <tr>
                                        <td className="text-muted-foreground px-4 py-6 text-center" colSpan={4}>
                                            Nothing has happened yet.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </AppLayout>
    );
}
