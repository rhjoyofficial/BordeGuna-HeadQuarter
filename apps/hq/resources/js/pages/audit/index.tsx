import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';

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
type Paginated<T> = { data: T[]; links: { url: string | null; label: string; active: boolean }[]; total: number };

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Audit log', href: '/audit' }];

export default function AuditIndex({ logs }: { logs: Paginated<Log> }) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Audit log" />
            <div className="flex flex-col gap-4 p-4">
                <div className="text-muted-foreground text-sm">
                    {logs.total} entries. Append-only: nothing here can be edited or deleted, by database rule.
                </div>
                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead className="text-muted-foreground text-left text-xs uppercase">
                            <tr>
                                <th className="px-4 py-2">When</th>
                                <th className="px-4 py-2">Actor</th>
                                <th className="px-4 py-2">Action</th>
                                <th className="px-4 py-2">Target</th>
                                <th className="px-4 py-2">Reason</th>
                                <th className="px-4 py-2">Change</th>
                            </tr>
                        </thead>
                        <tbody>
                            {logs.data.map((l) => (
                                <tr key={l.id} className="border-t align-top">
                                    <td className="px-4 py-2 whitespace-nowrap">{new Date(l.occurred_at).toLocaleString()}</td>
                                    <td className="px-4 py-2">
                                        {l.actor_type}
                                        <div className="text-muted-foreground text-xs">{l.actor_label}</div>
                                    </td>
                                    <td className="px-4 py-2 font-mono text-xs">{l.action}</td>
                                    <td className="px-4 py-2 font-mono text-xs">
                                        {l.target_type ? `${l.target_type.split('\\').pop()}#${l.target_id ?? ''}` : ''}
                                    </td>
                                    <td className="px-4 py-2 text-xs">{l.reason ?? ''}</td>
                                    <td className="px-4 py-2 font-mono text-xs break-all">
                                        {l.before || l.after ? (
                                            <details>
                                                <summary className="cursor-pointer">diff</summary>
                                                <pre className="max-w-lg whitespace-pre-wrap">
                                                    {JSON.stringify({ before: l.before, after: l.after }, null, 1)}
                                                </pre>
                                            </details>
                                        ) : (
                                            ''
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <div className="flex flex-wrap gap-1 text-sm">
                    {logs.links.map((l, i) =>
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
