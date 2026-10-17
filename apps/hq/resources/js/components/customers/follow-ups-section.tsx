import { EmptyState } from '@/components/empty-state';
import { DataTable, Note } from '@/components/observe/panels';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateTime, humanKey } from '@/lib/format';
import { useT } from '@/lib/i18n';

/**
 * Customer 360's follow-ups section (docs/120 item 14, docs/123 section 2). READ-ONLY: no form, no
 * button, no link that changes anything. Deciding stays on the follow-ups screen behind its own
 * permission. Promises are follow-ups whose origin is `promise`. What the outcome ledger credits is
 * shown as references only, never an amount.
 */

export type LedgerCredit = {
    attribution_uuid: string;
    outcome_uuid: string;
    outcome_type: string;
    occurred_at: string | null;
    role: string;
    method: string;
    confidence: number;
    results: { result_type: string; role: string; ref: string }[];
};

export type CustomerFollowUp = {
    uuid: string;
    state: string;
    kind: string;
    origin: string;
    promise: boolean;
    channel: string;
    due_at: string;
    due: boolean;
    intent: string;
    consent_refusal: string | null;
    decided_at: string | null;
    closed_at: string | null;
    close_note: string | null;
    ledger_credits: LedgerCredit[];
};

export type CustomerOpportunity = {
    uuid: string;
    state: string;
    kind: string;
    subject: string;
    expected_at: string | null;
    close_reason: string | null;
    closed_at: string | null;
    conversion_supported: boolean | null;
};

export type CustomerFollowUps = {
    counts: { open: number; open_promises: number; due: number; active_opportunities: number };
    follow_ups: CustomerFollowUp[];
    opportunities: CustomerOpportunity[];
};

function Credits({ credits }: { credits: LedgerCredit[] }) {
    const t = useT();
    if (credits.length === 0) {
        return <span className="text-muted-foreground">-</span>;
    }
    return (
        <ul className="space-y-1">
            {credits.map((c) => (
                <li key={c.attribution_uuid} className="text-xs">
                    {humanKey(c.outcome_type)} ({t('as asserted by a person')}, {humanKey(c.role)}){' '}
                    <span className="text-muted-foreground">{c.results.map((r) => r.ref).join(', ')}</span>
                </li>
            ))}
        </ul>
    );
}

export function FollowUpsBody({ data }: { data: CustomerFollowUps | null }) {
    const t = useT();

    if (data === null) {
        return (
            <EmptyState title={t('Follow-ups need the follow-ups permission (hq.followups.view).')} className="py-6" />
        );
    }

    return (
        <div className="space-y-3">
            <Note>
                {t('Open: {open}. Open promises: {promises}. Due now: {due}. Active opportunities: {opportunities}.', {
                    open: data.counts.open,
                    promises: data.counts.open_promises,
                    due: data.counts.due,
                    opportunities: data.counts.active_opportunities,
                })}
            </Note>
            <DataTable
                rowKey={(f) => f.uuid}
                rows={data.follow_ups}
                empty="No follow-ups or promises recorded for this customer."
                columns={[
                    {
                        key: 'state',
                        label: 'State',
                        render: (f) => (
                            <StatusBadge tone={f.due ? 'warn' : f.state === 'refused' ? 'danger' : 'neutral'} dot={false}>
                                {f.due ? t('due') : humanKey(f.state)}
                            </StatusBadge>
                        ),
                    },
                    { key: 'origin', label: 'Origin', render: (f) => (f.promise ? t('Promise') : humanKey(f.origin)) },
                    { key: 'kind', label: 'Kind', render: (f) => humanKey(f.kind) },
                    { key: 'channel', label: 'Channel', render: (f) => humanKey(f.channel) },
                    { key: 'due_at', label: 'Due', render: (f) => formatDateTime(f.due_at) },
                    { key: 'intent', label: 'Intent', render: (f) => <span className="whitespace-pre-wrap">{f.intent}</span> },
                    {
                        key: 'consent',
                        label: 'Consent',
                        render: (f) => (f.consent_refusal ? t('refused: {class}', { class: humanKey(f.consent_refusal) }) : '-'),
                    },
                    { key: 'note', label: 'Completion note', render: (f) => (f.close_note ? <span className="whitespace-pre-wrap">{f.close_note}</span> : '-') },
                    { key: 'ledger', label: 'Outcome ledger', render: (f) => <Credits credits={f.ledger_credits} /> },
                ]}
            />
            <DataTable
                rowKey={(o) => o.uuid}
                rows={data.opportunities}
                empty="No opportunities recorded for this customer."
                columns={[
                    {
                        key: 'state',
                        label: 'State',
                        render: (o) => (
                            <StatusBadge status={o.state} dot={false}>
                                {humanKey(o.state)}
                            </StatusBadge>
                        ),
                    },
                    { key: 'kind', label: 'Kind', render: (o) => humanKey(o.kind) },
                    { key: 'subject', label: 'Subject', render: (o) => o.subject },
                    { key: 'expected', label: 'Expected', render: (o) => o.expected_at ?? '-' },
                    { key: 'close', label: 'Closed as', render: (o) => (o.close_reason ? humanKey(o.close_reason) : '-') },
                    {
                        key: 'supported',
                        label: 'Outcome ledger',
                        render: (o) =>
                            o.conversion_supported === null
                                ? '-'
                                : o.conversion_supported
                                  ? t('supported by a credited order')
                                  : t("a person's word only"),
                    },
                ]}
            />
        </div>
    );
}
