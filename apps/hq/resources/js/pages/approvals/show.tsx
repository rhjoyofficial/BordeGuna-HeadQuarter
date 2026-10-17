import { approvalStatus, type ApprovalOutcome as Outcome, OutcomeBanner, PreviewList, riskTone } from '@/components/approvals/shared';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { JsonBlock, PageHeader, SectionHeader, TechnicalDetail } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, humanKey, timeAgo } from '@/lib/format';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { type ReactNode, useState } from 'react';

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
    evidence: Record<string, unknown> | null;
    preview: { available?: boolean; reason?: string; result?: Record<string, unknown> } | null;
    estimated_cost: Record<string, unknown> | null;
    business_id: number | null;
    store_id: number | null;
    business: string | null;
    store: string | null;
    agent_key: string | null;
    correlation_id: string | null;
    policy_version: number | null;
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
    payload_intact: boolean;
    supersedes_id: number | null;
    superseded_by: number | null;
    execution_status: string | null;
    execution_error: string | null;
};

/** A flat payload as a two-column list. Nested values fall back to JSON on one line. */
function PayloadList({ payload }: { payload: Record<string, unknown> | null }) {
    const entries = Object.entries(payload ?? {});
    if (entries.length === 0) return <p className="text-muted-foreground text-sm">No arguments.</p>;
    return (
        <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
            {entries.map(([k, v]) => (
                <div key={k} className="contents">
                    <dt className="text-muted-foreground">{humanKey(k)}</dt>
                    <dd className="min-w-0 break-words">
                        {typeof v === 'object' && v !== null ? <code className="text-xs">{JSON.stringify(v)}</code> : String(v)}
                    </dd>
                </div>
            ))}
        </dl>
    );
}

function Fact({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="min-w-0">
            <dt className="text-muted-foreground text-xs">{label}</dt>
            <dd className="text-sm break-words">{children}</dd>
        </div>
    );
}

export default function ApprovalShow({ approval }: { approval: Approval }) {
    const { auth, flash, errors } = usePage<{
        auth: { permissions: string[] };
        flash: { status?: string | null; outcome?: Outcome | null };
        errors: Record<string, string>;
    }>().props;
    const canDecide = auth.permissions.includes('hq.approvals.decide');
    const [editing, setEditing] = useState(false);
    const [draft, setDraft] = useState(JSON.stringify(approval.payload ?? {}, null, 2));
    const [draftError, setDraftError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [dialog, setDialog] = useState<'approve' | 'approve-edited' | 'reject' | null>(null);

    const title = approval.tool_title ?? humanKey(approval.tool_key);
    const st = approvalStatus(approval);
    const overdue = approval.status === 'pending' && approval.sla_due_at && new Date(approval.sla_due_at).getTime() < Date.now();

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Approvals', href: '/approvals' },
        { title: `#${approval.id}`, href: `/approvals/${approval.id}` },
    ];

    const post = (action: 'approve' | 'reject', body: Record<string, unknown>) => {
        setBusy(true);
        router.post(`/approvals/${approval.id}/${action}`, body as Parameters<typeof router.post>[1], {
            preserveScroll: true,
            onFinish: () => {
                setBusy(false);
                setDialog(null);
            },
        });
    };

    const openEditedApproval = () => {
        try {
            JSON.parse(draft);
            setDraftError(null);
            setDialog('approve-edited');
        } catch {
            setDraftError('That is not valid JSON. Fix it before approving.');
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Approval ${approval.id}: ${title}`} />
            <div className="flex max-w-4xl flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={
                        <span className="flex flex-wrap items-center gap-2">
                            {title}
                            <StatusBadge tone={st.tone}>{st.label}</StatusBadge>
                            {approval.risk_label && (
                                <StatusBadge tone={riskTone(approval.risk_tier)} dot={false}>
                                    {approval.risk_label}
                                </StatusBadge>
                            )}
                            {overdue && <StatusBadge status="overdue">past deadline</StatusBadge>}
                        </span>
                    }
                    description={approval.reason ?? approval.tool_purpose ?? undefined}
                    actions={
                        approval.status === 'pending' && canDecide && approval.decidable && !editing ? (
                            <>
                                <Button disabled={busy} onClick={() => setDialog('approve')}>
                                    Approve and run
                                </Button>
                                <Button variant="outline" disabled={busy} onClick={() => setEditing(true)}>
                                    Edit, then approve
                                </Button>
                                <Button variant="outline" disabled={busy} onClick={() => setDialog('reject')}>
                                    Reject
                                </Button>
                            </>
                        ) : undefined
                    }
                />

                <OutcomeBanner status={flash?.status} outcome={flash?.outcome} error={errors?.approval} />
                {flash?.status === 'approval-executed' && flash.outcome?.status === 'succeeded' && flash.outcome.output && (
                    <TechnicalDetail summary="What the action returned">
                        <JsonBlock value={flash.outcome.output} />
                    </TechnicalDetail>
                )}

                {approval.status === 'consumed' && approval.execution_status !== 'succeeded' && (
                    <div className="rounded-xl border p-4 text-sm bg-tone-pending-bg border-tone-pending-line text-tone-pending">
                        <strong>This approval was spent without the action running.</strong> It was approved, but the gateway refused the call at
                        execution time{approval.execution_error ? `: ${approval.execution_error}` : '.'} An approval is single use, so a fresh request
                        is needed rather than a retry.
                    </div>
                )}

                {!approval.payload_intact && (
                    <div className="rounded-xl border p-4 text-sm bg-tone-danger-bg border-tone-danger-line text-tone-danger">
                        <strong>Integrity failure.</strong> The stored payload no longer matches the hash recorded when this approval was created.
                        This cannot happen through the application, so the row was changed underneath it. Execution is refused and nothing will be
                        repaired.
                    </div>
                )}

                <section className="hq-card p-4">
                    <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <Fact label="Business">
                            {approval.business ?? (approval.business_id ? `#${approval.business_id}` : 'not business-specific')}
                            {approval.store ? ` · ${approval.store}` : ''}
                        </Fact>
                        <Fact label={approval.agent_key ? 'Proposed by' : 'Asked by'}>
                            {approval.agent_key ? (
                                <span className="text-tone-pending">the {approval.agent_key} agent</span>
                            ) : (
                                (approval.requested_by ?? 'system')
                            )}
                        </Fact>
                        {approval.agent_key && (
                            <Fact label="Who asked the agent">
                                {!approval.requester_recorded ? (
                                    <span className="text-muted-foreground">
                                        Recorded before requester and enabler were kept apart, so the person who asked is not known.
                                    </span>
                                ) : approval.requested_by === approval.enabled_by ? (
                                    <>{approval.requested_by ?? 'nobody recorded'}, who also enabled the agent</>
                                ) : (
                                    <>
                                        {approval.requested_by ?? 'nobody recorded'}
                                        {approval.requester_kind === 'on_behalf_of' ? ' (a scheduled or system run on their behalf)' : ''}
                                        <span className="text-muted-foreground"> · agent enabled by {approval.enabled_by ?? 'nobody recorded'}</span>
                                    </>
                                )}
                            </Fact>
                        )}
                        <Fact label="Requested">
                            {approval.created_at ? `${timeAgo(approval.created_at)}, ${formatDateTime(approval.created_at)}` : '-'}
                        </Fact>
                        {approval.status === 'pending' && (
                            <Fact label="Decide by">
                                {approval.sla_due_at ? (
                                    <span className={overdue ? 'text-tone-warn' : ''}>
                                        {formatDateTime(approval.sla_due_at)} ({timeAgo(approval.sla_due_at)})
                                    </span>
                                ) : (
                                    'no deadline'
                                )}
                            </Fact>
                        )}
                        {approval.status === 'pending' && (
                            <Fact label="Expires">{approval.expires_at ? formatDateTime(approval.expires_at) : 'never'}</Fact>
                        )}
                        {approval.delegated_to && <Fact label="Delegated to">{approval.delegated_to}</Fact>}
                        {approval.decided_by && (
                            <Fact label="Decided by">
                                {approval.decided_by}
                                {approval.decided_at ? `, ${formatDateTime(approval.decided_at)}` : ''}
                            </Fact>
                        )}
                        {approval.decision_note && <Fact label="Reason given">{approval.decision_note}</Fact>}
                        {approval.consumed_at && <Fact label="Executed">{formatDateTime(approval.consumed_at)}</Fact>}
                        {approval.supersedes_id && (
                            <Fact label="Replaces">
                                <a href={`/approvals/${approval.supersedes_id}`} className="underline">
                                    approval #{approval.supersedes_id}
                                </a>
                            </Fact>
                        )}
                        {approval.superseded_by && (
                            <Fact label="Replaced by">
                                <a href={`/approvals/${approval.superseded_by}`} className="underline">
                                    approval #{approval.superseded_by}
                                </a>
                            </Fact>
                        )}
                    </dl>
                </section>

                <section className="hq-card flex flex-col gap-3 p-4">
                    <SectionHeader
                        title="What will run"
                        aside={
                            <span>
                                {approval.tool_key} v{approval.tool_version} · {humanKey(approval.action_class)}
                            </span>
                        }
                    />
                    {editing ? (
                        <>
                            <textarea
                                className="border-input hq-field h-56 w-full rounded-md border p-2 font-mono text-xs"
                                value={draft}
                                onChange={(e) => setDraft(e.target.value)}
                                aria-label="Edited arguments as JSON"
                            />
                            {draftError && <p className="text-sm text-tone-danger">{draftError}</p>}
                            <p className="text-muted-foreground text-xs">
                                The original stays on the record unchanged. Approving creates a new approval for what you edited, validated against
                                the tool's own schema.
                            </p>
                            <div className="flex flex-wrap gap-2">
                                <Button disabled={busy} onClick={openEditedApproval}>
                                    Approve the edited action
                                </Button>
                                <Button variant="outline" disabled={busy} onClick={() => setEditing(false)}>
                                    Cancel edit
                                </Button>
                            </div>
                        </>
                    ) : (
                        <>
                            <PayloadList payload={approval.payload} />
                            <TechnicalDetail summary="As JSON">
                                <JsonBlock value={approval.payload} />
                            </TechnicalDetail>
                            <p className="text-muted-foreground text-xs">
                                These exact arguments are what executes. They are hashed, and the hash is checked again at execution time, so what you
                                read here is what runs.
                            </p>
                        </>
                    )}
                </section>

                {approval.preview && (
                    <section className="hq-card flex flex-col gap-3 p-4">
                        <SectionHeader title="What it would do" />
                        <PreviewList preview={approval.preview} />
                        {approval.preview.available && (
                            <TechnicalDetail summary="As JSON">
                                <JsonBlock value={approval.preview.result} />
                            </TechnicalDetail>
                        )}
                    </section>
                )}

                {approval.evidence && (
                    <section className="hq-card flex flex-col gap-3 p-4">
                        <SectionHeader title="Evidence the request rests on" />
                        <JsonBlock value={approval.evidence} />
                    </section>
                )}

                <TechnicalDetail summary="Identifiers and policy">
                    <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 font-mono text-xs">
                        <dt className="text-muted-foreground">uuid</dt>
                        <dd className="break-all">{approval.uuid}</dd>
                        <dt className="text-muted-foreground">correlation</dt>
                        <dd className="break-all">{approval.correlation_id ?? '-'}</dd>
                        <dt className="text-muted-foreground">policy version</dt>
                        <dd>{approval.policy_version ?? 'tier baseline'}</dd>
                        <dt className="text-muted-foreground">business / store id</dt>
                        <dd>
                            {approval.business_id ?? '-'} / {approval.store_id ?? '-'}
                        </dd>
                    </dl>
                </TechnicalDetail>
            </div>

            <ConfirmDialog
                open={dialog === 'approve'}
                onOpenChange={(o) => !o && setDialog(null)}
                title={`Approve and run: ${title}`}
                description={approval.reason ?? 'The action runs once, exactly as shown. Authority is checked again at execution time.'}
                confirmLabel="Approve and run"
                busy={busy}
                onConfirm={() => post('approve', {})}
            >
                {approval.preview?.available && (
                    <div className="rounded-md border p-3">
                        <PreviewList preview={approval.preview} />
                    </div>
                )}
            </ConfirmDialog>

            <ConfirmDialog
                open={dialog === 'approve-edited'}
                onOpenChange={(o) => !o && setDialog(null)}
                title="Approve the edited action?"
                description="The original stays on the record unchanged. A new approval is created for what you edited and runs once."
                confirmLabel="Approve edited action"
                busy={busy}
                onConfirm={() => post('approve', { edited_payload: JSON.parse(draft) })}
            />

            <ConfirmDialog
                open={dialog === 'reject'}
                onOpenChange={(o) => !o && setDialog(null)}
                title={`Reject: ${title}`}
                description="Nothing runs. Whoever proposed this receives your reason as feedback."
                confirmLabel="Reject"
                destructive
                busy={busy}
                reason={{ label: 'Why', placeholder: 'What is wrong with it, or what should happen instead.', required: true }}
                onConfirm={(reason) => post('reject', { reason })}
            />
        </AppLayout>
    );
}
