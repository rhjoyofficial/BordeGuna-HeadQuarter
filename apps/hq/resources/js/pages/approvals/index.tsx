import { approvalStatus, type ApprovalOutcome as Outcome, OutcomeBanner, PreviewList, riskTone } from '@/components/approvals/shared';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { EmptyState } from '@/components/empty-state';
import { Field, NativeSelect } from '@/components/form/field';
import { JsonBlock, PageHeader, TechnicalDetail, Toolbar } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, humanKey, timeAgo } from '@/lib/format';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Inbox } from 'lucide-react';
import { useState } from 'react';

type Approval = {
    id: number;
    uuid: string;
    tool_key: string;
    tool_version: string;
    tool_title: string | null;
    tool_purpose: string | null;
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
    business: string | null;
    store: string | null;
    agent_key: string | null;
    sla_due_at: string | null;
    expires_at: string | null;
    escalated_at: string | null;
    decided_at: string | null;
    decision_note: string | null;
    consumed_at: string | null;
    created_at: string | null;
    requested_by: string | null;
    /** The enabling user of the agent that parked it; null for a person, a job, and a row from before the split. */
    enabled_by: string | null;
    requester_kind: string | null;
    /** False for an agent's row whose requester column still holds the enabling user (docs/81 P0-4). */
    requester_recorded: boolean;
    decided_by: string | null;
    delegated_to: string | null;
    decidable: boolean;
    execution_status: string | null;
    execution_error: string | null;
};

type Decision = { kind: 'approve' | 'reject' | 'cancel' | 'delegate'; approval: Approval } | null;

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Approvals', href: '/approvals' }];

const STATUS_FILTERS: { key: string; label: string }[] = [
    { key: 'pending', label: 'Waiting' },
    { key: 'consumed', label: 'Done' },
    { key: 'rejected', label: 'Rejected' },
    { key: 'expired', label: 'Expired' },
    { key: 'cancelled', label: 'Withdrawn' },
    { key: 'all', label: 'All' },
];

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
    const { auth, flash, errors } = usePage<{
        auth: { permissions: string[] };
        flash: { status?: string | null; outcome?: Outcome | null };
        errors: Record<string, string>;
    }>().props;
    const canDecide = auth.permissions.includes('hq.approvals.decide');
    const canDelegate = auth.permissions.includes('hq.approvals.delegate');
    const [busy, setBusy] = useState<number | null>(null);
    const [decision, setDecision] = useState<Decision>(null);
    const [delegateTo, setDelegateTo] = useState<string>(approvers[0]?.id.toString() ?? '');

    const submit = (a: Approval, action: 'approve' | 'reject' | 'cancel' | 'delegate', body: Record<string, string | number> = {}) => {
        setBusy(a.id);
        router.post(`/approvals/${a.id}/${action}`, body, {
            preserveScroll: true,
            onFinish: () => {
                setBusy(null);
                setDecision(null);
            },
        });
    };

    const activeChannels = Object.entries(channels)
        .filter(([, on]) => on)
        .map(([name]) => name);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Approvals" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Approvals"
                    description={
                        <>
                            Actions that need a person before they run. Ignoring one lets it expire; nothing here is ever approved by default.
                            {activeChannels.length > 0 ? (
                                <> You are notified by {activeChannels.join(' and ')}.</>
                            ) : (
                                <> No notification channel is configured, so check this screen yourself.</>
                            )}
                        </>
                    }
                    actions={
                        <div className="flex flex-wrap items-center gap-2">
                            <StatusBadge tone="pending">{counts.pending} waiting</StatusBadge>
                            {counts.overdue > 0 && <StatusBadge tone="warn">{counts.overdue} past deadline</StatusBadge>}
                        </div>
                    }
                >
                    <Toolbar label="Filter by status">
                        {STATUS_FILTERS.map((s) => (
                            <Button
                                key={s.key}
                                size="sm"
                                variant={filters.status === s.key ? 'default' : 'outline'}
                                onClick={() => router.get('/approvals', { status: s.key }, { preserveState: true, replace: true })}
                            >
                                {s.label}
                            </Button>
                        ))}
                    </Toolbar>
                </PageHeader>

                <OutcomeBanner status={flash?.status} outcome={flash?.outcome} error={errors?.approval} />

                {approvals.length === 0 ? (
                    <EmptyState
                        icon={Inbox}
                        title={filters.status === 'pending' ? 'Nothing is waiting for a decision' : 'Nothing here'}
                        description={
                            filters.status === 'pending'
                                ? 'When a tool needs a human before it may run, the request appears here with what it would do.'
                                : 'No approval has this status. Try another filter.'
                        }
                    />
                ) : (
                    <div className="flex flex-col gap-3">
                        {approvals.map((a) => {
                            const st = approvalStatus(a);
                            const overdue = a.status === 'pending' && a.sla_due_at && new Date(a.sla_due_at).getTime() < Date.now();
                            return (
                                <article key={a.id} className="hq-card p-4">
                                    <div className="flex flex-wrap items-start justify-between gap-4">
                                        <div className="min-w-0 flex-1">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <Link href={`/approvals/${a.id}`} className="font-semibold hover:underline">
                                                    {a.tool_title ?? humanKey(a.tool_key)}
                                                </Link>
                                                <StatusBadge tone={st.tone}>{st.label}</StatusBadge>
                                                {a.risk_label && (
                                                    <StatusBadge tone={riskTone(a.risk_tier)} dot={false}>
                                                        {a.risk_label}
                                                    </StatusBadge>
                                                )}
                                                {a.escalated_at && <StatusBadge status="escalated" />}
                                                {overdue && <StatusBadge status="overdue">past deadline</StatusBadge>}
                                            </div>
                                            {a.reason && <p className="mt-1 text-sm">{a.reason}</p>}

                                            <div className="text-muted-foreground mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs">
                                                {(a.business || a.store) && (
                                                    <span>
                                                        {a.business ?? ''}
                                                        {a.store ? ` · ${a.store}` : ''}
                                                    </span>
                                                )}
                                                {a.agent_key && (
                                                    <span className="text-tone-pending">proposed by the {a.agent_key} agent</span>
                                                )}
                                                {a.requested_by && !a.agent_key && <span>asked by {a.requested_by}</span>}
                                                {a.requested_by && a.agent_key && a.requester_recorded && <span>asked by {a.requested_by}</span>}
                                                {a.delegated_to && (
                                                    <span className="text-tone-info">delegated to {a.delegated_to}</span>
                                                )}
                                                {a.decided_by && (
                                                    <span>
                                                        decided by {a.decided_by} {a.decided_at ? timeAgo(a.decided_at) : ''}
                                                    </span>
                                                )}
                                                {a.created_at && <span title={formatDateTime(a.created_at)}>requested {timeAgo(a.created_at)}</span>}
                                                {a.status === 'pending' && a.sla_due_at && (
                                                    <span
                                                        title={formatDateTime(a.sla_due_at)}
                                                        className={overdue ? 'text-tone-warn' : ''}
                                                    >
                                                        decide {timeAgo(a.sla_due_at)}
                                                    </span>
                                                )}
                                                {a.status === 'pending' && a.expires_at && (
                                                    <span title={formatDateTime(a.expires_at)}>expires {timeAgo(a.expires_at)}</span>
                                                )}
                                            </div>

                                            {a.preview && (
                                                <div className="bg-muted mt-3 rounded-lg p-3">
                                                    <div className="hq-eyebrow mb-1">Would do</div>
                                                    <PreviewList preview={a.preview} />
                                                </div>
                                            )}
                                            {a.decision_note && (
                                                <p className="text-muted-foreground mt-2 text-xs italic">Reason given: {a.decision_note}</p>
                                            )}
                                            {a.execution_error && (
                                                <p className="mt-2 text-xs text-tone-warn">
                                                    Refused at execution: {a.execution_error}
                                                </p>
                                            )}

                                            <div className="mt-3">
                                                <TechnicalDetail summary={`Technical detail · ${a.tool_key} v${a.tool_version}`}>
                                                    <JsonBlock value={a.payload} />
                                                </TechnicalDetail>
                                            </div>
                                        </div>

                                        {a.status === 'pending' && (
                                            <div className="flex flex-wrap gap-2 sm:flex-col sm:items-stretch">
                                                {canDecide && a.decidable && (
                                                    <>
                                                        <Button
                                                            size="sm"
                                                            disabled={busy === a.id}
                                                            onClick={() => setDecision({ kind: 'approve', approval: a })}
                                                        >
                                                            Approve
                                                        </Button>
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            disabled={busy === a.id}
                                                            onClick={() => setDecision({ kind: 'reject', approval: a })}
                                                        >
                                                            Reject
                                                        </Button>
                                                    </>
                                                )}
                                                {canDelegate && (
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        disabled={busy === a.id}
                                                        onClick={() => setDecision({ kind: 'delegate', approval: a })}
                                                    >
                                                        Delegate
                                                    </Button>
                                                )}
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    disabled={busy === a.id}
                                                    onClick={() => setDecision({ kind: 'cancel', approval: a })}
                                                >
                                                    Withdraw
                                                </Button>
                                            </div>
                                        )}
                                    </div>
                                </article>
                            );
                        })}
                    </div>
                )}
            </div>

            <ConfirmDialog
                open={decision?.kind === 'approve'}
                onOpenChange={(o) => !o && setDecision(null)}
                title={`Approve and run: ${decision?.approval.tool_title ?? humanKey(decision?.approval.tool_key)}`}
                description={
                    decision?.approval.reason ?? 'The action runs once, exactly as shown on the card. Authority is checked again at execution time.'
                }
                confirmLabel="Approve and run"
                busy={busy !== null}
                onConfirm={() => decision && submit(decision.approval, 'approve')}
            >
                {decision?.approval.preview?.available && (
                    <div className="bg-muted rounded-lg p-3">
                        <PreviewList preview={decision.approval.preview} />
                    </div>
                )}
            </ConfirmDialog>

            <ConfirmDialog
                open={decision?.kind === 'reject'}
                onOpenChange={(o) => !o && setDecision(null)}
                title={`Reject: ${decision?.approval.tool_title ?? humanKey(decision?.approval.tool_key)}`}
                description="Nothing runs. Whoever proposed this receives your reason as feedback."
                confirmLabel="Reject"
                destructive
                busy={busy !== null}
                reason={{ label: 'Why', placeholder: 'What is wrong with it, or what should happen instead.', required: true }}
                onConfirm={(reason) => decision && submit(decision.approval, 'reject', { reason })}
            />

            <ConfirmDialog
                open={decision?.kind === 'cancel'}
                onOpenChange={(o) => !o && setDecision(null)}
                title="Withdraw this request?"
                description="The request is closed without a decision. Nothing runs, and a fresh request is needed to try again."
                confirmLabel="Withdraw"
                busy={busy !== null}
                onConfirm={() => decision && submit(decision.approval, 'cancel')}
            />

            <ConfirmDialog
                open={decision?.kind === 'delegate'}
                onOpenChange={(o) => !o && setDecision(null)}
                title="Delegate to another approver"
                description="They decide instead of you. The delegation is recorded on the approval."
                confirmLabel="Delegate"
                busy={busy !== null || delegateTo === ''}
                onConfirm={() => decision && submit(decision.approval, 'delegate', { user_id: Number(delegateTo) })}
            >
                <Field label="Approver" htmlFor="delegate-approver">
                    <NativeSelect id="delegate-approver" value={delegateTo} onChange={(e) => setDelegateTo(e.target.value)} aria-label="Approver">
                        {approvers.map((u) => (
                            <option key={u.id} value={u.id}>
                                {u.email}
                            </option>
                        ))}
                    </NativeSelect>
                </Field>
            </ConfirmDialog>
        </AppLayout>
    );
}
