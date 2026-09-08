import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

type Approval = {
    id: number;
    uuid: string;
    tool_key: string;
    tool_version: string;
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
    agent_key: string | null;
    correlation_id: string | null;
    policy_version: number | null;
    sla_due_at: string | null;
    expires_at: string | null;
    decided_at: string | null;
    decision_note: string | null;
    consumed_at: string | null;
    created_at: string | null;
    requested_by: string | null;
    decided_by: string | null;
    delegated_to: string | null;
    decidable: boolean;
    payload_intact: boolean;
    supersedes_id: number | null;
    superseded_by: number | null;
    execution_status: string | null;
    execution_error: string | null;
};

type Outcome = { status?: string; error?: string | null; output?: Record<string, unknown> | null; tool_call_id?: number | null };

/**
 * What the decision actually did.
 *
 * A decision on an R4 action must never be answered with a blank page. Clicking "approve" and being
 * shown nothing is indistinguishable from a broken button, and the natural response is to click it
 * again. This is the banner the M3 production UI check found missing.
 */
function DecisionOutcome({ status, outcome, error }: { status?: string | null; outcome?: Outcome | null; error?: string }) {
    if (error) {
        return (
            <div className="rounded-xl border border-red-400 bg-red-50 p-4 text-sm text-red-900">
                <strong>The decision could not be applied.</strong> {error}
            </div>
        );
    }
    if (!status || !outcome) return null;

    if (status === 'approval-executed' && outcome.status === 'succeeded') {
        return (
            <div className="rounded-xl border border-green-500 bg-green-50 p-4 text-sm text-green-900">
                <strong>Approved and executed.</strong> The action ran once
                {outcome.tool_call_id ? ` and was recorded as tool call #${outcome.tool_call_id}` : ''}.
                {outcome.output && <pre className="mt-2 max-h-40 overflow-auto rounded bg-white/60 p-2 text-xs">{JSON.stringify(outcome.output, null, 2)}</pre>}
            </div>
        );
    }

    return (
        <div className="rounded-xl border border-amber-500 bg-amber-50 p-4 text-sm text-amber-900">
            <strong>Nothing ran.</strong> The approval was not executed: {outcome.error ?? 'the gateway refused the call.'}
            <div className="mt-1 text-xs">Authority is re-checked at execution time, so a change since the request was made can refuse it here.</div>
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
    const [busy, setBusy] = useState(false);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Approvals', href: '/approvals' },
        { title: `#${approval.id}`, href: `/approvals/${approval.id}` },
    ];

    const approve = (edited: boolean) => {
        // Inertia types the body as RequestPayload; a bare Record<string, unknown> is not one.
        let payload: Record<string, unknown> = {};
        if (edited) {
            try {
                payload = { edited_payload: JSON.parse(draft) };
            } catch {
                window.alert('That is not valid JSON.');
                return;
            }
            if (!window.confirm('Approve the EDITED action? The original stays on the record unchanged, and a new approval is created for what you edited.')) return;
        } else if (!window.confirm(`Approve and execute ${approval.tool_key}?`)) {
            return;
        }
        setBusy(true);
        router.post(`/approvals/${approval.id}/approve`, payload as Parameters<typeof router.post>[1], {
            preserveScroll: true,
            onFinish: () => setBusy(false),
        });
    };

    const reject = () => {
        const reason = window.prompt('Reject. Why? The proposer receives this as feedback.');
        if (!reason || reason.trim() === '') return;
        setBusy(true);
        router.post(`/approvals/${approval.id}/reject`, { reason }, { preserveScroll: true, onFinish: () => setBusy(false) });
    };

    const field = (label: string, value: React.ReactNode) => (
        <div className="flex gap-2 py-1 text-sm">
            <div className="text-muted-foreground w-40 shrink-0">{label}</div>
            <div className="min-w-0 break-words">{value}</div>
        </div>
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Approval ${approval.id}`} />
            <div className="flex max-w-3xl flex-col gap-4 p-4">
                <DecisionOutcome status={flash?.status} outcome={flash?.outcome} error={errors?.approval} />

                {approval.status === 'consumed' && approval.execution_status !== 'succeeded' && (
                    <div className="rounded-xl border border-amber-500 bg-amber-50 p-4 text-sm text-amber-900">
                        <strong>This approval was spent without the action running.</strong> It was approved, but the gateway refused the call at
                        execution time{approval.execution_error ? `: ${approval.execution_error}` : '.'} An approval is single use, so a fresh request
                        is needed rather than a retry.
                    </div>
                )}

                {!approval.payload_intact && (
                    <div className="rounded-xl border border-red-400 bg-red-50 p-4 text-sm text-red-900">
                        <strong>Integrity failure.</strong> The stored payload no longer matches the hash that was recorded when this approval was
                        created. This cannot happen through the application, so the row was changed underneath it. Execution is refused and nothing
                        will be repaired.
                    </div>
                )}

                <div className="rounded-xl border p-4">
                    <div className="font-mono text-sm">
                        {approval.tool_key} <span className="text-muted-foreground">v{approval.tool_version}</span>
                    </div>
                    <div className="text-muted-foreground mt-1 text-sm">{approval.reason}</div>

                    <div className="mt-4">
                        {field('Status', `${approval.status_label}`)}
                        {field('Risk', approval.risk_label ?? '-')}
                        {field('Requires', approval.action_class)}
                        {field('Asked by', approval.requested_by ?? 'system')}
                        {approval.agent_key && field('Proposed by agent', approval.agent_key)}
                        {field('Business / store', `${approval.business_id ?? '-'} / ${approval.store_id ?? '-'}`)}
                        {field('Policy version', approval.policy_version ?? 'tier baseline')}
                        {field('Correlation', <span className="font-mono text-xs">{approval.correlation_id ?? '-'}</span>)}
                        {field('Decide by', approval.sla_due_at ? new Date(approval.sla_due_at).toLocaleString() : '-')}
                        {field('Expires', approval.expires_at ? new Date(approval.expires_at).toLocaleString() : 'never')}
                        {approval.decided_by && field('Decided by', `${approval.decided_by} at ${new Date(approval.decided_at ?? '').toLocaleString()}`)}
                        {approval.decision_note && field('Note', approval.decision_note)}
                        {approval.consumed_at && field('Executed', new Date(approval.consumed_at).toLocaleString())}
                        {approval.supersedes_id && field('Supersedes', `#${approval.supersedes_id}`)}
                        {approval.superseded_by && field('Superseded by', `#${approval.superseded_by}`)}
                    </div>
                </div>

                <div className="rounded-xl border p-4">
                    <div className="text-muted-foreground mb-2 text-xs uppercase">What will run</div>
                    {editing ? (
                        <textarea
                            className="border-input bg-background h-56 w-full rounded-md border p-2 font-mono text-xs"
                            value={draft}
                            onChange={(e) => setDraft(e.target.value)}
                        />
                    ) : (
                        <pre className="bg-muted/40 max-h-72 overflow-auto rounded p-2 text-xs">{JSON.stringify(approval.payload, null, 2)}</pre>
                    )}
                    <div className="text-muted-foreground mt-2 text-xs">
                        This exact payload is what executes. It is hashed, and the hash is checked again at execution time, so what you read here is
                        what runs.
                    </div>
                </div>

                {approval.preview && (
                    <div className="rounded-xl border p-4">
                        <div className="text-muted-foreground mb-2 text-xs uppercase">Preview</div>
                        {approval.preview.available ? (
                            <pre className="bg-muted/40 max-h-48 overflow-auto rounded p-2 text-xs">
                                {JSON.stringify(approval.preview.result, null, 2)}
                            </pre>
                        ) : (
                            <div className="text-muted-foreground text-sm">No preview available: {approval.preview.reason}</div>
                        )}
                    </div>
                )}

                {approval.evidence && (
                    <div className="rounded-xl border p-4">
                        <div className="text-muted-foreground mb-2 text-xs uppercase">Evidence</div>
                        <pre className="bg-muted/40 max-h-48 overflow-auto rounded p-2 text-xs">{JSON.stringify(approval.evidence, null, 2)}</pre>
                    </div>
                )}

                {approval.status === 'pending' && canDecide && approval.decidable && (
                    <div className="flex flex-wrap gap-2">
                        {!editing && (
                            <>
                                <Button disabled={busy} onClick={() => approve(false)}>
                                    Approve and execute
                                </Button>
                                <Button variant="outline" disabled={busy} onClick={() => setEditing(true)}>
                                    Approve with edit
                                </Button>
                                <Button variant="outline" disabled={busy} onClick={reject}>
                                    Reject
                                </Button>
                            </>
                        )}
                        {editing && (
                            <>
                                <Button disabled={busy} onClick={() => approve(true)}>
                                    Approve the edited action
                                </Button>
                                <Button variant="outline" disabled={busy} onClick={() => setEditing(false)}>
                                    Cancel edit
                                </Button>
                            </>
                        )}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
