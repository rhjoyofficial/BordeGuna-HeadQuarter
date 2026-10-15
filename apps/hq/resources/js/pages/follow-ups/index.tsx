import { EmptyState } from '@/components/empty-state';
import { Field, FormRow, NativeSelect } from '@/components/form/field';
import { PageHeader, SectionHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { StatusBadge, type Tone } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

/**
 * Follow-ups and opportunities (docs/113, S4 part 1). Proposal-only: a person records a plan or an
 * interest, a person with the deciding permission approves, declines, completes, cancels or closes.
 * Approval starts a WorkflowEngine run that sleeps until the due time and then waits for a person.
 * Nothing on this page composes or sends a message; the consent column is the S7 ledger's own answer.
 */

type Customer = { id: number; name: string | null; phone: string | null };
type FollowUpRow = {
    uuid: string;
    state: string;
    kind: string;
    origin: string;
    channel: string;
    due_at: string;
    due: boolean;
    intent: string;
    customer: Customer;
    business: string | null;
    brand: string | null;
    opportunity: { uuid: string; subject: string } | null;
    proposer: string | null;
    proposer_type: string;
    consent: { permitted: boolean | null; refusal: string | null; event_id: number | null; checked_at: string | null };
    decided_by: string | null;
    decision_reason: string | null;
    close_note: string | null;
    workflow_instance_key: string | null;
    self_decided: boolean;
    ledger_credits: { attribution_uuid: string; outcome_type: string; role: string; results: { ref: string }[] }[];
    created_at: string | null;
};
type OpportunityRow = {
    uuid: string;
    state: string;
    kind: string;
    subject: string;
    expected_at: string | null;
    customer: Customer;
    business: string | null;
    brand: string | null;
    proposer: string | null;
    proposer_type: string;
    decided_by: string | null;
    decision_reason: string | null;
    close_reason: string | null;
    close_note: string | null;
    open_follow_ups: number;
    self_decided: boolean;
    conversion_supported: boolean | null;
    created_at: string | null;
};
type Props = {
    followUps: FollowUpRow[];
    opportunities: OpportunityRow[];
    filters: { state: string; opportunity_state: string };
    counts: { proposed: number; due: number; scheduled: number; opportunities_proposed: number };
    options: {
        kinds: string[];
        origins: string[];
        channels: string[];
        opportunity_kinds: string[];
        close_reasons: string[];
        brands: { id: number; business_id: number; name: string }[];
        open_opportunities: { id: number; customer_id: number; label: string }[];
    };
    prefill: { customer_id: number; customer_name: string | null; business_id: number } | null;
    can: { propose: boolean; decide: boolean };
};

const FOLLOW_UP_FILTERS = ['open', 'due', 'proposed', 'scheduled', 'refused', 'declined', 'done', 'cancelled', 'all'];
const OPPORTUNITY_FILTERS = ['active', 'proposed', 'open', 'declined', 'closed', 'all'];
const STATE_TONES: Record<string, Tone> = {
    proposed: 'pending',
    approved: 'info',
    scheduled: 'info',
    refused: 'danger',
    declined: 'neutral',
    done: 'ok',
    cancelled: 'neutral',
    open: 'info',
    closed: 'neutral',
};
const CHANNEL_NAMES: Record<string, string> = {
    messenger: 'Messenger',
    whatsapp: 'WhatsApp',
    instagram: 'Instagram',
    sms: 'SMS',
    email: 'Email',
    voice: 'Voice',
    web_chat: 'Web chat',
    comment: 'Comment',
};

type Pending = { uuid: string; action: string } | null;

export default function FollowUpsIndex({ followUps, opportunities, filters, counts, options, prefill, can }: Props) {
    const t = useT();
    const { flash } = usePage<{ flash?: { status?: string } }>().props;
    const breadcrumbs: BreadcrumbItem[] = [{ title: 'Follow-ups', href: '/follow-ups' }];
    const [pending, setPending] = useState<Pending>(null);
    const [text, setText] = useState('');
    const [closeReason, setCloseReason] = useState(options.close_reasons[0] ?? 'other');
    const [busy, setBusy] = useState<string | null>(null);
    const [showForms, setShowForms] = useState<boolean>(prefill !== null);

    const stateLabel = (s: string) =>
        ({
            proposed: t('Proposed'),
            approved: t('Approved'),
            scheduled: t('Scheduled'),
            refused: t('Refused by consent'),
            declined: t('Declined'),
            done: t('Done'),
            cancelled: t('Cancelled'),
            open: t('Open'),
            closed: t('Closed'),
        })[s] ?? s;
    const filterLabel = (s: string) =>
        ({
            open: t('Open'),
            due: t('Due now'),
            proposed: t('Proposed'),
            scheduled: t('Scheduled'),
            refused: t('Refused by consent'),
            declined: t('Declined'),
            done: t('Done'),
            cancelled: t('Cancelled'),
            all: t('All'),
            active: t('Active'),
            closed: t('Closed'),
        })[s] ?? s;
    const kindLabel = (k: string) => ({ service: t('Service'), marketing: t('Marketing') })[k] ?? k;
    const originLabel = (o: string) => ({ promise: t('Promise'), opportunity: t('Opportunity'), operator: t('Operator') })[o] ?? o;
    const opportunityKindLabel = (k: string) =>
        ({ reorder: t('Reorder'), quotation: t('Quotation'), wholesale: t('Wholesale'), product_interest: t('Product interest'), other: t('Other') })[k] ?? k;
    const closeReasonLabel = (r: string) =>
        ({ converted: t('Converted'), lost: t('Lost'), no_response: t('No response'), duplicate: t('Duplicate'), other: t('Other') })[r] ?? r;
    const channelLabel = (c: string) => t(CHANNEL_NAMES[c] ?? c);
    const refusalLabel = (r: string | null) =>
        (({
            consent_do_not_contact: t('Do not contact'),
            consent_withdrawn: t('Withdrawn'),
            consent_expired: t('Expired'),
            consent_opt_in_missing: t('No opt-in'),
            consent_not_recorded: t('Nothing recorded'),
            consent_subject_unresolved: t('Unknown person'),
        }) as Record<string, string>)[r ?? ''] ?? t('Refused');
    const customerLabel = (c: Customer) => c.name ?? c.phone ?? t('Customer #{id}', { id: c.id });
    const flashLabel = (status: string | undefined) => {
        if (!status) {
            return null;
        }
        if (status.startsWith('follow-up-refused:')) {
            return t('Recorded as refused: the consent ledger says {reason}. Nothing was scheduled.', { reason: refusalLabel(status.split(':')[1] ?? null) });
        }
        return (
            ({
                'follow-up-proposed': t('Follow-up proposed. A person with the deciding permission must approve it.'),
                'follow-up-scheduled': t('Approved and scheduled. HQ will wait until the due time, then wait for a person. Nothing is sent.'),
                'follow-up-declined': t('Follow-up declined.'),
                'follow-up-done': t('Follow-up recorded as done.'),
                'follow-up-cancelled': t('Follow-up cancelled.'),
                'opportunity-proposed': t('Opportunity proposed.'),
                'opportunity-opened': t('Opportunity opened.'),
                'opportunity-declined': t('Opportunity declined.'),
                'opportunity-closed': t('Opportunity closed.'),
            }) as Record<string, string>
        )[status];
    };

    const act = (url: string, body: Record<string, string> = {}) => {
        setBusy(url);
        router.post(url, body, {
            preserveScroll: true,
            onFinish: () => {
                setBusy(null);
                setPending(null);
                setText('');
            },
        });
    };
    const needsText = (action: string) => ['decline', 'cancel', 'complete', 'decline-opportunity'].includes(action);
    const submitPending = () => {
        if (!pending) {
            return;
        }
        const { uuid, action } = pending;
        if (action === 'approve') {
            act(`/follow-ups/${uuid}/approve`, text ? { reason: text } : {});
        } else if (action === 'decline' || action === 'cancel') {
            act(`/follow-ups/${uuid}/${action}`, { reason: text });
        } else if (action === 'complete') {
            act(`/follow-ups/${uuid}/complete`, { note: text });
        } else if (action === 'open-opportunity') {
            act(`/follow-ups/opportunities/${uuid}/open`, text ? { reason: text } : {});
        } else if (action === 'decline-opportunity') {
            act(`/follow-ups/opportunities/${uuid}/decline`, { reason: text });
        } else if (action === 'close-opportunity') {
            act(`/follow-ups/opportunities/${uuid}/close`, { close_reason: closeReason, ...(text ? { note: text } : {}) });
        }
    };
    const actionLabel = (action: string) =>
        ({
            approve: t('Approve'),
            decline: t('Decline'),
            complete: t('Mark done'),
            cancel: t('Cancel'),
            'open-opportunity': t('Open'),
            'decline-opportunity': t('Decline'),
            'close-opportunity': t('Close'),
        })[action] ?? action;
    const textLabel = (action: string) =>
        action === 'complete' ? t('What you did') : action === 'approve' || action === 'open-opportunity' || action === 'close-opportunity' ? t('Note (optional)') : t('Reason');

    const followUpForm = useForm({
        customer_id: prefill ? String(prefill.customer_id) : '',
        kind: 'service',
        origin: 'promise',
        channel: 'whatsapp',
        due_at: '',
        intent: '',
        brand_id: '',
        opportunity_id: '',
    });
    const opportunityForm = useForm({
        customer_id: prefill ? String(prefill.customer_id) : '',
        kind: 'reorder',
        subject: '',
        expected_at: '',
        brand_id: '',
    });

    const PendingBox = ({ uuid }: { uuid: string }) =>
        pending && pending.uuid === uuid ? (
            <div className="bg-muted/40 mt-2 flex flex-col gap-2 rounded-md border p-2">
                {pending.action === 'close-opportunity' && (
                    <Field label={t('Close reason')} htmlFor={`close-${uuid}`} size="auto">
                        <NativeSelect id={`close-${uuid}`} value={closeReason} onChange={(e) => setCloseReason(e.target.value)}>
                            {options.close_reasons.map((r) => (
                                <option key={r} value={r}>
                                    {closeReasonLabel(r)}
                                </option>
                            ))}
                        </NativeSelect>
                    </Field>
                )}
                <Field label={textLabel(pending.action)} htmlFor={`text-${uuid}`} size="lg">
                    <Input id={`text-${uuid}`} value={text} onChange={(e) => setText(e.target.value)} maxLength={1000} required={needsText(pending.action)} />
                </Field>
                <div className="flex gap-2">
                    <Button size="sm" onClick={submitPending} disabled={busy !== null || (needsText(pending.action) && text.trim() === '')}>
                        {actionLabel(pending.action)}
                    </Button>
                    <Button size="sm" variant="ghost" onClick={() => setPending(null)}>
                        {t('Back')}
                    </Button>
                </div>
            </div>
        ) : null;

    const ActionButton = ({ uuid, action, variant = 'outline' }: { uuid: string; action: string; variant?: 'outline' | 'default' | 'ghost' }) => (
        <Button size="sm" variant={variant} disabled={busy !== null} onClick={() => setPending({ uuid, action })}>
            {actionLabel(action)}
        </Button>
    );

    const flashText = flashLabel(flash?.status);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Follow-ups')} />
            <div className="flex max-w-7xl flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={t('Follow-ups and opportunities')}
                    description={t(
                        'A follow-up is a plan a person owns, never a message. Approving one starts a run that waits until the due time and then waits for a person; nothing is sent to a customer from here. Consent is the same ledger answer the Tool Gateway checks.',
                    )}
                    actions={
                        can.propose ? (
                            <Button variant={showForms ? 'ghost' : 'outline'} size="sm" onClick={() => setShowForms((v) => !v)}>
                                {showForms ? t('Hide forms') : t('Record a follow-up or an interest')}
                            </Button>
                        ) : undefined
                    }
                />

                {flashText && <div className="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-900">{flashText}</div>}

                <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                    {[
                        { label: t('Awaiting a decision'), value: counts.proposed },
                        { label: t('Due now'), value: counts.due },
                        { label: t('Scheduled'), value: counts.scheduled },
                        { label: t('Interests awaiting a decision'), value: counts.opportunities_proposed },
                    ].map((c) => (
                        <div key={c.label} className="bg-card rounded-xl border p-3">
                            <div className="text-muted-foreground text-xs">{c.label}</div>
                            <div className="text-2xl font-semibold tabular-nums">{c.value}</div>
                        </div>
                    ))}
                </div>

                {showForms && can.propose && (
                    <section className="grid gap-4 lg:grid-cols-2">
                        <form
                            className="bg-card flex flex-col gap-3 rounded-xl border p-4"
                            onSubmit={(e) => {
                                e.preventDefault();
                                followUpForm.transform((d) => ({
                                    ...d,
                                    customer_id: Number(d.customer_id),
                                    brand_id: d.brand_id === '' ? null : Number(d.brand_id),
                                    opportunity_id: d.opportunity_id === '' ? null : Number(d.opportunity_id),
                                    due_at: d.due_at === '' ? '' : new Date(d.due_at).toISOString(),
                                }));
                                followUpForm.post('/follow-ups', { preserveScroll: true, onSuccess: () => followUpForm.reset('intent', 'due_at', 'opportunity_id') });
                            }}
                        >
                            <SectionHeader title={t('Propose a follow-up')} aside={prefill?.customer_name ?? ''} />
                            <FormRow>
                                <Field label={t('Customer id')} htmlFor="fu-customer" size="auto" error={followUpForm.errors.customer_id}>
                                    <Input id="fu-customer" type="number" min={1} value={followUpForm.data.customer_id} onChange={(e) => followUpForm.setData('customer_id', e.target.value)} required />
                                </Field>
                                <Field label={t('Kind')} htmlFor="fu-kind" size="auto" hint={t('Marketing needs the customer\'s own opt-in; service needs recorded consent.')} error={followUpForm.errors.kind}>
                                    <NativeSelect id="fu-kind" value={followUpForm.data.kind} onChange={(e) => followUpForm.setData('kind', e.target.value)}>
                                        {options.kinds.map((k) => (
                                            <option key={k} value={k}>
                                                {kindLabel(k)}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </Field>
                                <Field label={t('Channel')} htmlFor="fu-channel" size="auto" error={followUpForm.errors.channel}>
                                    <NativeSelect id="fu-channel" value={followUpForm.data.channel} onChange={(e) => followUpForm.setData('channel', e.target.value)}>
                                        {options.channels.map((c) => (
                                            <option key={c} value={c}>
                                                {channelLabel(c)}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </Field>
                                <Field label={t('Origin')} htmlFor="fu-origin" size="auto" error={followUpForm.errors.origin}>
                                    <NativeSelect id="fu-origin" value={followUpForm.data.origin} onChange={(e) => followUpForm.setData('origin', e.target.value)}>
                                        {options.origins.map((o) => (
                                            <option key={o} value={o}>
                                                {originLabel(o)}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </Field>
                            </FormRow>
                            <FormRow>
                                <Field label={t('Due')} htmlFor="fu-due" size="auto" error={followUpForm.errors.due_at}>
                                    <Input id="fu-due" type="datetime-local" value={followUpForm.data.due_at} onChange={(e) => followUpForm.setData('due_at', e.target.value)} required />
                                </Field>
                                <Field label={t('Brand')} htmlFor="fu-brand" size="auto" error={followUpForm.errors.brand_id}>
                                    <NativeSelect id="fu-brand" value={followUpForm.data.brand_id} onChange={(e) => followUpForm.setData('brand_id', e.target.value)}>
                                        <option value="">{t('No brand')}</option>
                                        {options.brands.map((b) => (
                                            <option key={b.id} value={b.id}>
                                                {b.name}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </Field>
                                <Field label={t('Serves the interest')} htmlFor="fu-opportunity" size="lg" error={followUpForm.errors.opportunity_id}>
                                    <NativeSelect id="fu-opportunity" value={followUpForm.data.opportunity_id} onChange={(e) => followUpForm.setData('opportunity_id', e.target.value)}>
                                        <option value="">{t('None')}</option>
                                        {options.open_opportunities
                                            .filter((o) => followUpForm.data.customer_id === '' || o.customer_id === Number(followUpForm.data.customer_id))
                                            .map((o) => (
                                                <option key={o.id} value={o.id}>
                                                    {o.label}
                                                </option>
                                            ))}
                                    </NativeSelect>
                                </Field>
                            </FormRow>
                            <Field label={t('Intent')} htmlFor="fu-intent" hint={t('What the contact is for, in words. Not the message.')} error={followUpForm.errors.intent}>
                                <Input id="fu-intent" value={followUpForm.data.intent} onChange={(e) => followUpForm.setData('intent', e.target.value)} maxLength={500} required />
                            </Field>
                            <div>
                                <Button type="submit" size="sm" disabled={followUpForm.processing}>
                                    {t('Propose follow-up')}
                                </Button>
                            </div>
                        </form>

                        <form
                            className="bg-card flex flex-col gap-3 rounded-xl border p-4"
                            onSubmit={(e) => {
                                e.preventDefault();
                                opportunityForm.transform((d) => ({
                                    ...d,
                                    customer_id: Number(d.customer_id),
                                    brand_id: d.brand_id === '' ? null : Number(d.brand_id),
                                    expected_at: d.expected_at === '' ? null : d.expected_at,
                                }));
                                opportunityForm.post('/follow-ups/opportunities', { preserveScroll: true, onSuccess: () => opportunityForm.reset('subject', 'expected_at') });
                            }}
                        >
                            <SectionHeader title={t('Record an interest')} aside={prefill?.customer_name ?? ''} />
                            <FormRow>
                                <Field label={t('Customer id')} htmlFor="op-customer" size="auto" error={opportunityForm.errors.customer_id}>
                                    <Input id="op-customer" type="number" min={1} value={opportunityForm.data.customer_id} onChange={(e) => opportunityForm.setData('customer_id', e.target.value)} required />
                                </Field>
                                <Field label={t('Kind')} htmlFor="op-kind" size="auto" error={opportunityForm.errors.kind}>
                                    <NativeSelect id="op-kind" value={opportunityForm.data.kind} onChange={(e) => opportunityForm.setData('kind', e.target.value)}>
                                        {options.opportunity_kinds.map((k) => (
                                            <option key={k} value={k}>
                                                {opportunityKindLabel(k)}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </Field>
                                <Field label={t('Expected')} htmlFor="op-expected" size="auto" error={opportunityForm.errors.expected_at}>
                                    <Input id="op-expected" type="date" value={opportunityForm.data.expected_at} onChange={(e) => opportunityForm.setData('expected_at', e.target.value)} />
                                </Field>
                                <Field label={t('Brand')} htmlFor="op-brand" size="auto" error={opportunityForm.errors.brand_id}>
                                    <NativeSelect id="op-brand" value={opportunityForm.data.brand_id} onChange={(e) => opportunityForm.setData('brand_id', e.target.value)}>
                                        <option value="">{t('No brand')}</option>
                                        {options.brands.map((b) => (
                                            <option key={b.id} value={b.id}>
                                                {b.name}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </Field>
                            </FormRow>
                            <Field label={t('Subject')} htmlFor="op-subject" hint={t('What the customer wanted, in words. No price, no quantity.')} error={opportunityForm.errors.subject}>
                                <Input id="op-subject" value={opportunityForm.data.subject} onChange={(e) => opportunityForm.setData('subject', e.target.value)} maxLength={500} required />
                            </Field>
                            <div>
                                <Button type="submit" size="sm" disabled={opportunityForm.processing}>
                                    {t('Record interest')}
                                </Button>
                            </div>
                        </form>
                    </section>
                )}

                <section className="flex flex-col gap-2">
                    <SectionHeader
                        title={t('Follow-ups')}
                        aside={
                            <div className="flex flex-wrap gap-1">
                                {FOLLOW_UP_FILTERS.map((s) => (
                                    <Button
                                        key={s}
                                        size="sm"
                                        variant={filters.state === s ? 'default' : 'ghost'}
                                        onClick={() => router.get('/follow-ups', { state: s, opportunity_state: filters.opportunity_state }, { preserveState: true, replace: true })}
                                    >
                                        {filterLabel(s)}
                                    </Button>
                                ))}
                            </div>
                        }
                    />
                    {followUps.length === 0 ? (
                        <EmptyState title={t('No follow-ups')} description={t('Nothing here for this filter.')} />
                    ) : (
                        <div className="bg-card overflow-x-auto rounded-xl border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-muted-foreground text-left text-xs">
                                    <tr>
                                        <th className="px-3 py-2 font-medium">{t('Customer')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Due')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Intent')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Kind')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Consent')}</th>
                                        <th className="px-3 py-2 font-medium">{t('State')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Proposed by')}</th>
                                        <th className="px-3 py-2" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {followUps.map((f) => (
                                        <tr key={f.uuid} className="border-t align-top">
                                            <td className="px-3 py-2 text-xs whitespace-nowrap">
                                                <Link href={`/customers/${f.customer.id}`} className="font-medium hover:underline">
                                                    {customerLabel(f.customer)}
                                                </Link>
                                                <div className="text-muted-foreground">{[f.business, f.brand].filter(Boolean).join(' / ')}</div>
                                            </td>
                                            <td className="px-3 py-2 text-xs whitespace-nowrap">
                                                <div className={f.due ? 'font-semibold text-orange-700' : ''}>{formatDateTime(f.due_at)}</div>
                                                {f.due && (
                                                    <StatusBadge tone="warn" dot={false}>
                                                        {t('Due now')}
                                                    </StatusBadge>
                                                )}
                                            </td>
                                            <td className="max-w-md px-3 py-2 text-xs">
                                                <div>{f.intent}</div>
                                                {f.opportunity && <div className="text-muted-foreground">{t('Serves: {subject}', { subject: f.opportunity.subject })}</div>}
                                                {f.decision_reason && <div className="text-muted-foreground">{t('Decision: {reason}', { reason: f.decision_reason })}</div>}
                                                {f.close_note && <div className="text-muted-foreground">{t('Note: {note}', { note: f.close_note })}</div>}
                                                {f.ledger_credits.map((c) => (
                                                    <div key={c.attribution_uuid} className="text-muted-foreground">
                                                        {t('Credited by a person to: {outcome}', { outcome: `${c.outcome_type} ${c.results.map((r) => r.ref).join(', ')}` })}
                                                    </div>
                                                ))}
                                            </td>
                                            <td className="px-3 py-2 text-xs whitespace-nowrap">
                                                <div>{kindLabel(f.kind)}</div>
                                                <div className="text-muted-foreground">
                                                    {channelLabel(f.channel)} / {originLabel(f.origin)}
                                                </div>
                                            </td>
                                            <td className="px-3 py-2 text-xs">
                                                {f.consent.permitted ? (
                                                    <StatusBadge tone="ok" dot={false}>
                                                        {t('Allowed')}
                                                    </StatusBadge>
                                                ) : (
                                                    <StatusBadge tone={f.consent.refusal === 'consent_do_not_contact' ? 'danger' : 'warn'} dot={false}>
                                                        {refusalLabel(f.consent.refusal)}
                                                    </StatusBadge>
                                                )}
                                                {!f.consent.permitted && (
                                                    <div className="mt-1">
                                                        <Link href={`/customers/${f.customer.id}/consent`} className="text-muted-foreground hover:underline">
                                                            {t('Consent history')}
                                                        </Link>
                                                    </div>
                                                )}
                                            </td>
                                            <td className="px-3 py-2">
                                                <StatusBadge tone={STATE_TONES[f.state] ?? 'neutral'} dot={false}>
                                                    {stateLabel(f.state)}
                                                </StatusBadge>
                                                {f.decided_by && <div className="text-muted-foreground mt-1 text-xs">{f.decided_by}</div>}
                                                {f.self_decided && <div className="text-muted-foreground text-xs">{t('decided by its proposer')}</div>}
                                            </td>
                                            <td className="text-muted-foreground px-3 py-2 text-xs">{f.proposer_type === 'agent_run' ? t('Agent proposal') : f.proposer}</td>
                                            <td className="px-3 py-2 text-right">
                                                {can.decide && f.state === 'proposed' && (
                                                    <div className="flex justify-end gap-1">
                                                        <ActionButton uuid={f.uuid} action="approve" variant="default" />
                                                        <ActionButton uuid={f.uuid} action="decline" />
                                                    </div>
                                                )}
                                                {can.decide && f.state === 'scheduled' && (
                                                    <div className="flex justify-end gap-1">
                                                        <ActionButton uuid={f.uuid} action="complete" variant="default" />
                                                        <ActionButton uuid={f.uuid} action="cancel" />
                                                    </div>
                                                )}
                                                <PendingBox uuid={f.uuid} />
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>

                <section className="flex flex-col gap-2">
                    <SectionHeader
                        title={t('Opportunities')}
                        aside={
                            <div className="flex flex-wrap gap-1">
                                {OPPORTUNITY_FILTERS.map((s) => (
                                    <Button
                                        key={s}
                                        size="sm"
                                        variant={filters.opportunity_state === s ? 'default' : 'ghost'}
                                        onClick={() => router.get('/follow-ups', { state: filters.state, opportunity_state: s }, { preserveState: true, replace: true })}
                                    >
                                        {filterLabel(s)}
                                    </Button>
                                ))}
                            </div>
                        }
                    />
                    {opportunities.length === 0 ? (
                        <EmptyState title={t('No opportunities')} description={t('Nothing here for this filter.')} />
                    ) : (
                        <div className="bg-card overflow-x-auto rounded-xl border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-muted-foreground text-left text-xs">
                                    <tr>
                                        <th className="px-3 py-2 font-medium">{t('Customer')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Subject')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Kind')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Expected')}</th>
                                        <th className="px-3 py-2 font-medium">{t('State')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Proposed by')}</th>
                                        <th className="px-3 py-2" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {opportunities.map((o) => (
                                        <tr key={o.uuid} className="border-t align-top">
                                            <td className="px-3 py-2 text-xs whitespace-nowrap">
                                                <Link href={`/customers/${o.customer.id}`} className="font-medium hover:underline">
                                                    {customerLabel(o.customer)}
                                                </Link>
                                                <div className="text-muted-foreground">{[o.business, o.brand].filter(Boolean).join(' / ')}</div>
                                            </td>
                                            <td className="max-w-md px-3 py-2 text-xs">
                                                <div>{o.subject}</div>
                                                {o.open_follow_ups > 0 && <div className="text-muted-foreground">{t('{count} open follow-ups', { count: o.open_follow_ups })}</div>}
                                                {o.decision_reason && <div className="text-muted-foreground">{t('Decision: {reason}', { reason: o.decision_reason })}</div>}
                                                {o.close_reason && (
                                                    <div className="text-muted-foreground">
                                                        {t('Closed: {reason}', { reason: closeReasonLabel(o.close_reason) })}
                                                        {o.close_note ? ` (${o.close_note})` : ''}
                                                    </div>
                                                )}
                                                {o.conversion_supported !== null && (
                                                    <div className="text-muted-foreground">
                                                        {o.conversion_supported ? t('supported by a credited order') : t("a person's word only")}
                                                    </div>
                                                )}
                                            </td>
                                            <td className="px-3 py-2 text-xs whitespace-nowrap">{opportunityKindLabel(o.kind)}</td>
                                            <td className="px-3 py-2 text-xs whitespace-nowrap">{o.expected_at ?? ''}</td>
                                            <td className="px-3 py-2">
                                                <StatusBadge tone={STATE_TONES[o.state] ?? 'neutral'} dot={false}>
                                                    {stateLabel(o.state)}
                                                </StatusBadge>
                                                {o.decided_by && <div className="text-muted-foreground mt-1 text-xs">{o.decided_by}</div>}
                                                {o.self_decided && <div className="text-muted-foreground text-xs">{t('decided by its proposer')}</div>}
                                            </td>
                                            <td className="text-muted-foreground px-3 py-2 text-xs">{o.proposer_type === 'agent_run' ? t('Agent proposal') : o.proposer}</td>
                                            <td className="px-3 py-2 text-right">
                                                {can.decide && o.state === 'proposed' && (
                                                    <div className="flex justify-end gap-1">
                                                        <ActionButton uuid={o.uuid} action="open-opportunity" variant="default" />
                                                        <ActionButton uuid={o.uuid} action="decline-opportunity" />
                                                    </div>
                                                )}
                                                {can.decide && o.state === 'open' && (
                                                    <div className="flex justify-end gap-1">
                                                        <ActionButton uuid={o.uuid} action="close-opportunity" />
                                                    </div>
                                                )}
                                                <PendingBox uuid={o.uuid} />
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>
            </div>
        </AppLayout>
    );
}
