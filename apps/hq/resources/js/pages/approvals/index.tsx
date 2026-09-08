import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

type Approval = {
    id: number;
    uuid: string;
    tool_key: string;
    tool_version: string;
    risk_tier: string | null;
    risk_label: string | null;
    action_class: string;
    status: string;
    status_label: string;
    payload: Record<string, unknown> | null;
    reason: string | null;
    preview: { available?: boolean; reason?: string; result?: Record<string, unknown> } | null;
    estimated_cost: Record<string, unknown> | null;
    business_id: number | null;
    store_id: number | null;
    agent_key: string | null;
    sla_due_at: string | null;
    expires_at: string | null;
    escalated_at: string | null;
    decided_at: string | null;
    decision_note: string | null;
    consumed_at: string | null;
    created_at: string | null;
    requested_by: string | null;
    decided_by: string | null;
    delegated_to: string | null;
    decidable: boolean;
};

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Approvals', href: '/approvals' }];

const statusTone: Record<string, string> = {
    pending: 'text-amber-700',
    approved: 'text-sky-700',
    consumed: 'text-green-700',
    rejected: 'text-red-700',
    cancelled: 'text-muted-foreground',
    expired: 'text-muted-foreground',
};

export default function ApprovalsIndex({
    approvals,
    filters,
    counts,
    channels,
    approvers,
}: {
    approvals: Approval[];
    filters: { status: string };
    counts: { pending: number; overdue: number };
    channels: Record<string, boolean>;
    approvers: { id: number; email: string }[];
}) {
    const { auth } = usePage<{ auth: { permissions: string[] } }>().props;
    const canDecide = auth.permissions.includes('hq.approvals.decide');
    const canDelegate = auth.permissions.includes('hq.approvals.delegate');
    const [busy, setBusy] = useState<number | null>(null);

    const decide = (a: Approval, action: 'approve' | 'reject' | 'cancel') => {
        // Inertia types the body as RequestPayload; a bare Record<string, unknown> is not one.
        let payload: Record<string, string> = {};
        if (action === 'reject') {
            const reason = window.prompt(`Reject ${a.tool_key}. Why? The proposer receives this as feedback.`);
            if (!reason || reason.trim() === '') return;
            payload = { reason };
        }
        if (action === 'approve' && !window.confirm(`Approve and execute ${a.tool_key}?\n\n${a.reason ?? ''}`)) return;
        if (action === 'cancel' && !window.confirm(`Withdraw this request for ${a.tool_key}?`)) return;

        setBusy(a.id);
        router.post(`/approvals/${a.id}/${action}`, payload, { preserveScroll: true, onFinish: () => setBusy(null) });
    };

    const delegate = (a: Approval) => {
        const list = approvers.map((u) => `${u.id}: ${u.email}`).join('\n');
        const id = window.prompt(`Delegate ${a.tool_key} to which approver?\n\n${list}`);
        if (!id) return;
        setBusy(a.id);
        router.post(`/approvals/${a.id}/delegate`, { user_id: Number(id) }, { preserveScroll: true, onFinish: () => setBusy(null) });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Approvals" />
            <div className="flex flex-col gap-4 p-4">
                <div className="text-muted-foreground text-sm">
                    Actions waiting for a human. <span className="font-medium">{counts.pending} pending</span>
                    {counts.overdue > 0 && <span className="text-orange-700">, {counts.overdue} past their deadline</span>}. Ignoring an approval
                    expires it; nothing here is ever approved by default.
                    <div className="mt-1 text-xs">
                        Notification channels:{' '}
                        {Object.entries(channels).map(([name, on]) => (
                            <span key={name} className={`mr-2 font-mono ${on ? 'text-green-700' : 'text-muted-foreground'}`}>
                                {name}
                                {on ? '' : ' (not configured)'}
                            </span>
                        ))}
                    </div>
                </div>

                <div className="flex flex-wrap gap-2">
                    {['pending', 'consumed', 'rejected', 'expired', 'cancelled', 'all'].map((s) => (
                        <Button
                            key={s}
                            size="sm"
                            variant={filters.status === s ? 'default' : 'outline'}
                            onClick={() => router.get('/approvals', { status: s }, { preserveState: true, replace: true })}
                        >
                            {s}
                        </Button>
                    ))}
                </div>

                <div className="flex flex-col gap-3">
                    {approvals.map((a) => (
                        <div key={a.id} className="rounded-xl border p-4">
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <Link href={`/approvals/${a.id}`} className="font-mono text-sm underline-offset-2 hover:underline">
                                        {a.tool_key} <span className="text-muted-foreground">v{a.tool_version}</span>
                                    </Link>
                                    <div className="text-muted-foreground text-xs">{a.reason}</div>
                                    <div className="mt-1 text-xs">
                                        <span className={statusTone[a.status] ?? ''}>{a.status_label}</span>
                                        {a.risk_label && <span className="text-muted-foreground"> · {a.risk_label}</span>}
                                        {a.requested_by && <span className="text-muted-foreground"> · asked by {a.requested_by}</span>}
                                        {a.agent_key && <span className="text-amber-700"> · proposed by agent {a.agent_key}</span>}
                                        {a.delegated_to && <span className="text-sky-700"> · delegated to {a.delegated_to}</span>}
                                        {a.escalated_at && <span className="text-orange-700"> · escalated</span>}
                                    </div>
                                    {a.payload && (
                                        <pre className="bg-muted/40 mt-2 max-h-32 max-w-xl overflow-auto rounded p-2 text-[11px]">
                                            {JSON.stringify(a.payload, null, 2)}
                                        </pre>
                                    )}
                                    {a.preview?.available === false && (
                                        <div className="text-muted-foreground mt-1 text-xs">No preview: {a.preview.reason}</div>
                                    )}
                                    {a.preview?.available && (
                                        <div className="mt-1 text-xs text-sky-800">Would do: {JSON.stringify(a.preview.result)}</div>
                                    )}
                                    <div className="text-muted-foreground mt-1 text-[11px]">
                                        {a.sla_due_at && <>decide by {new Date(a.sla_due_at).toLocaleString()} · </>}
                                        {a.expires_at && <>expires {new Date(a.expires_at).toLocaleString()}</>}
                                    </div>
                                </div>

                                {a.status === 'pending' && (
                                    <div className="flex flex-wrap gap-2">
                                        {canDecide && a.decidable && (
                                            <>
                                                <Button size="sm" disabled={busy === a.id} onClick={() => decide(a, 'approve')}>
                                                    Approve
                                                </Button>
                                                <Button size="sm" variant="outline" disabled={busy === a.id} onClick={() => decide(a, 'reject')}>
                                                    Reject
                                                </Button>
                                            </>
                                        )}
                                        {canDelegate && (
                                            <Button size="sm" variant="outline" disabled={busy === a.id} onClick={() => delegate(a)}>
                                                Delegate
                                            </Button>
                                        )}
                                        <Button size="sm" variant="outline" disabled={busy === a.id} onClick={() => decide(a, 'cancel')}>
                                            Cancel
                                        </Button>
                                    </div>
                                )}
                            </div>
                        </div>
                    ))}
                    {approvals.length === 0 && (
                        <div className="text-muted-foreground rounded-xl border p-6 text-center text-sm">Nothing is waiting for a decision.</div>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
