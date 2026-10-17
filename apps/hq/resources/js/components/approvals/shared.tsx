import { Notice } from '@/components/ui/notice';
import { type Tone } from '@/components/ui/status-badge';
import { humanKey } from '@/lib/format';

/** What both approval screens need to say the same way. */

export type ApprovalPreview = { available?: boolean; reason?: string; result?: Record<string, unknown> } | null;
export type ApprovalOutcome = { status?: string; error?: string | null; output?: Record<string, unknown> | null; tool_call_id?: number | null };

/** The risk tier as a badge tone: R4 and R5 are red, R3 orange, R2 blue, the rest quiet. */
export function riskTone(tier: string | null | undefined): Tone {
    switch (tier) {
        case 'r5':
        case 'r4':
            return 'danger';
        case 'r3':
            return 'warn';
        case 'r2':
            return 'info';
        default:
            return 'neutral';
    }
}

/**
 * The status word an operator reads. A consumed approval has been SPENT, which is not the same as
 * having executed: a policy denial at execution time spends it and produces a denied tool call, so
 * "done" is only said when the call succeeded.
 */
export function approvalStatus(a: { status: string; execution_status: string | null }): { tone: Tone; label: string } {
    if (a.status === 'consumed') {
        return a.execution_status === 'succeeded' ? { tone: 'ok', label: 'done' } : { tone: 'warn', label: 'approved, did not run' };
    }
    if (a.status === 'pending') return { tone: 'pending', label: 'waiting' };
    if (a.status === 'approved') return { tone: 'info', label: 'approved' };
    if (a.status === 'rejected') return { tone: 'danger', label: 'rejected' };
    if (a.status === 'expired') return { tone: 'neutral', label: 'expired' };
    if (a.status === 'cancelled') return { tone: 'neutral', label: 'withdrawn' };
    return { tone: 'neutral', label: a.status };
}

/** What the action would do, as a short list rather than a JSON block. */
export function PreviewList({ preview }: { preview: ApprovalPreview }) {
    if (!preview) return null;
    if (preview.available === false) {
        return <p className="text-muted-foreground text-xs">No preview: {preview.reason}</p>;
    }
    const entries = Object.entries(preview.result ?? {});
    if (entries.length === 0) return null;
    return (
        <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-0.5 text-xs">
            {entries.map(([k, v]) => (
                <div key={k} className="contents">
                    <dt className="text-muted-foreground">{humanKey(k)}</dt>
                    <dd className="min-w-0 break-words">{typeof v === 'object' && v !== null ? JSON.stringify(v) : String(v)}</dd>
                </div>
            ))}
        </dl>
    );
}

/**
 * What the decision actually did. A decision on an R4 action must never be answered with a blank
 * page: clicking "approve" and being shown nothing is indistinguishable from a broken button, and
 * the natural response is to click it again.
 */
export function OutcomeBanner({ status, outcome, error }: { status?: string | null; outcome?: ApprovalOutcome | null; error?: string }) {
    if (error) {
        return (
            <Notice tone="danger" alert>
                <strong>The decision could not be applied.</strong> {error}
            </Notice>
        );
    }
    if (!status || !outcome) return null;
    if (status === 'approval-executed' && outcome.status === 'succeeded') {
        return (
            <Notice tone="ok">
                <strong>Approved and executed.</strong> The action ran once
                {outcome.tool_call_id ? ` and was recorded as tool call #${outcome.tool_call_id}` : ''}.
            </Notice>
        );
    }
    return (
        <Notice tone="pending">
            <strong>Nothing ran.</strong> {outcome.error ?? 'The gateway refused the call.'}
            <div className="mt-1 text-xs">Authority is re-checked at execution time, so a change since the request was made can refuse it here.</div>
        </Notice>
    );
}
