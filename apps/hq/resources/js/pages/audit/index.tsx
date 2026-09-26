import { EmptyState } from '@/components/empty-state';
import { JsonBlock, PageHeader, TechnicalDetail } from '@/components/page-header';
import { Pagination, type PageLink } from '@/components/pagination';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, humanKey, shortClass, timeAgo } from '@/lib/format';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { ScrollText } from 'lucide-react';
import { useState } from 'react';

type Log = {
    id: number;
    occurred_at: string;
    actor_type: string;
    actor_label: string | null;
    action: string;
    target_type: string | null;
    target_id: string | null;
    reason: string | null;
    correlation_id: string | null;
    before: unknown;
    after: unknown;
};
type Paginated<T> = { data: T[]; links: PageLink[]; total: number };

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Audit log', href: '/audit' }];

const ACTORS: { key: string; label: string }[] = [
    { key: '', label: 'Everyone' },
    { key: 'user', label: 'People' },
    { key: 'system', label: 'System' },
    { key: 'agent', label: 'Agents' },
];

export default function AuditIndex({ logs, filters }: { logs: Paginated<Log>; filters?: { action?: string; actor_type?: string } }) {
    const [action, setAction] = useState(filters?.action ?? '');
    const actor = filters?.actor_type ?? '';

    const apply = (next: { action?: string; actor_type?: string }) => {
        const params = { action: next.action ?? action, actor_type: next.actor_type ?? actor };
        router.get('/audit', Object.fromEntries(Object.entries(params).filter(([, v]) => v !== '')), { preserveState: true, replace: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Audit log" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Audit log"
                    description="Everything that changed, who changed it, and why. Append-only: nothing here can be edited or deleted, by database rule."
                    actions={<span className="text-muted-foreground text-sm">{logs.total.toLocaleString('en-US')} entries</span>}
                >
                    <div className="flex flex-wrap items-center gap-2">
                        <Input
                            className="w-64"
                            placeholder="Action starts with, e.g. approval"
                            value={action}
                            onChange={(e) => setAction(e.target.value)}
                            onKeyDown={(e) => e.key === 'Enter' && apply({ action })}
                        />
                        {ACTORS.map((a) => (
                            <Button
                                key={a.key || 'all'}
                                size="sm"
                                variant={actor === a.key ? 'default' : 'outline'}
                                onClick={() => apply({ actor_type: a.key })}
                            >
                                {a.label}
                            </Button>
                        ))}
                    </div>
                </PageHeader>

                {logs.data.length === 0 ? (
                    <EmptyState icon={ScrollText} title="Nothing recorded for that filter" description="Clear the action or pick another actor." />
                ) : (
                    <div className="bg-card overflow-x-auto rounded-xl border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-muted-foreground text-left text-xs">
                                <tr>
                                    <th className="px-3 py-2 font-medium">When</th>
                                    <th className="px-3 py-2 font-medium">What happened</th>
                                    <th className="px-3 py-2 font-medium">Who</th>
                                    <th className="px-3 py-2 font-medium">On</th>
                                    <th className="px-3 py-2 font-medium">Why</th>
                                    <th className="px-3 py-2 font-medium">Change</th>
                                </tr>
                            </thead>
                            <tbody>
                                {logs.data.map((l) => (
                                    <tr key={l.id} className="border-t align-top">
                                        <td className="px-3 py-2 whitespace-nowrap" title={formatDateTime(l.occurred_at)}>
                                            {timeAgo(l.occurred_at)}
                                            <div className="text-muted-foreground text-xs">{formatDateTime(l.occurred_at)}</div>
                                        </td>
                                        <td className="px-3 py-2">
                                            <div>{humanKey(l.action)}</div>
                                            <div className="text-muted-foreground font-mono text-[11px]">{l.action}</div>
                                        </td>
                                        <td className="px-3 py-2 text-xs">
                                            <div>{l.actor_label ?? humanKey(l.actor_type)}</div>
                                            {l.actor_label && <div className="text-muted-foreground">{l.actor_type}</div>}
                                        </td>
                                        <td className="px-3 py-2 text-xs whitespace-nowrap">
                                            {l.target_type ? `${shortClass(l.target_type)} #${l.target_id ?? ''}` : ''}
                                        </td>
                                        <td className="max-w-xs px-3 py-2 text-xs">{l.reason ?? ''}</td>
                                        <td className="px-3 py-2 text-xs">
                                            {l.before || l.after ? (
                                                <TechnicalDetail summary="before and after">
                                                    <JsonBlock value={{ before: l.before, after: l.after }} className="max-w-md" />
                                                </TechnicalDetail>
                                            ) : (
                                                ''
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                <Pagination links={logs.links} total={logs.total} noun="entries" />
            </div>
        </AppLayout>
    );
}
