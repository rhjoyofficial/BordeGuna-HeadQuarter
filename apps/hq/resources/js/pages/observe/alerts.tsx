import { EmptyState } from '@/components/empty-state';
import { DataTable, Note, Panel } from '@/components/observe/panels';
import { JsonBlock, PageHeader, TechnicalDetail } from '@/components/page-header';
import { StatusBadge, type Tone } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, humanKey, timeAgo } from '@/lib/format';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import { BellOff } from 'lucide-react';

type AlertRow = {
    id: number;
    rule: string;
    fingerprint: string;
    severity: string;
    subject: string;
    body: string;
    context: Record<string, unknown> | null;
    paged_by: string | null;
    occurrences: number;
    first_seen_at: string;
    last_seen_at: string;
    notified_at: string | null;
    resolved_at: string | null;
};

type Props = {
    open: AlertRow[];
    resolved: AlertRow[];
    rules: { key: string; paged_by: string | null }[];
};

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Alerts', href: '/observe/alerts' }];

const SEVERITY_TONE: Record<string, Tone> = { critical: 'danger', warning: 'warn', info: 'info' };

function Delivery({ alert }: { alert: AlertRow }) {
    if (alert.paged_by) {
        return <span className="text-muted-foreground text-xs">paged by {alert.paged_by}</span>;
    }
    return <span className="text-muted-foreground text-xs">{alert.notified_at ? `sent ${timeAgo(alert.notified_at)}` : 'not sent'}</span>;
}

export default function Alerts({ open, resolved, rules }: Props) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Alerts" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Alerts"
                    description="Conditions HQ checks every five minutes. An alert opens when its condition is first seen and clears by itself once the condition has been gone for two checks; nothing here is acknowledged by hand."
                    actions={<StatusBadge tone={open.length > 0 ? 'warn' : 'ok'}>{open.length} open</StatusBadge>}
                />

                {open.length === 0 ? (
                    <EmptyState
                        icon={BellOff}
                        title="Nothing is wrong right now"
                        description="Every rule found its condition clear on the last check."
                    />
                ) : (
                    <div className="flex flex-col gap-3">
                        {open.map((a) => (
                            <div key={a.id} className="hq-card flex flex-col gap-2 p-4">
                                <div className="flex flex-wrap items-center gap-2">
                                    <StatusBadge tone={SEVERITY_TONE[a.severity] ?? 'neutral'}>{a.severity}</StatusBadge>
                                    <span className="font-medium">{a.subject}</span>
                                </div>
                                <p className="text-sm whitespace-pre-line">{a.body}</p>
                                <div className="text-muted-foreground flex flex-wrap gap-x-4 gap-y-1 text-xs">
                                    <span>since {formatDateTime(a.first_seen_at)}</span>
                                    <span>
                                        seen on {a.occurrences} check(s), last {timeAgo(a.last_seen_at)}
                                    </span>
                                    <span>{humanKey(a.rule)}</span>
                                    <Delivery alert={a} />
                                </div>
                                {a.context && (
                                    <TechnicalDetail summary="Details">
                                        <JsonBlock value={a.context} />
                                    </TechnicalDetail>
                                )}
                            </div>
                        ))}
                    </div>
                )}

                <Panel title="Cleared in the last 7 days">
                    <DataTable
                        rowKey={(a) => String(a.id)}
                        rows={resolved}
                        empty="Nothing cleared in the last week."
                        columns={[
                            {
                                key: 'severity',
                                label: 'Severity',
                                render: (a) => <StatusBadge tone={SEVERITY_TONE[a.severity] ?? 'neutral'}>{a.severity}</StatusBadge>,
                            },
                            { key: 'subject', label: 'Alert', render: (a) => a.subject },
                            { key: 'from', label: 'Opened', render: (a) => formatDateTime(a.first_seen_at) },
                            { key: 'to', label: 'Cleared', render: (a) => formatDateTime(a.resolved_at) },
                            { key: 'delivery', label: 'Delivery', render: (a) => <Delivery alert={a} /> },
                        ]}
                    />
                </Panel>

                <Panel title="What HQ checks">
                    <DataTable
                        rowKey={(r) => r.key}
                        rows={rules}
                        empty="No rule is configured."
                        columns={[
                            { key: 'rule', label: 'Rule', render: (r) => humanKey(r.key) },
                            { key: 'key', label: 'Key', render: (r) => <span className="font-mono text-xs">{r.key}</span> },
                            { key: 'paged', label: 'Who sends it', render: (r) => r.paged_by ?? 'HQ (in-app and Telegram)' },
                        ]}
                    />
                </Panel>

                <Note>
                    A condition another component already pages (the host monitor, the model runtime, the approvals sweep) is recorded here and not
                    sent a second time. Backups are watched by the host monitor alone.
                </Note>
            </div>
        </AppLayout>
    );
}
