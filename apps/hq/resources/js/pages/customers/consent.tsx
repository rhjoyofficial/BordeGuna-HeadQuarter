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
import { Head, Link, useForm } from '@inertiajs/react';
import { useRef } from 'react';

/**
 * A customer's consent (docs/108 §7, S7; docs/114, the follow-ups). The grid is the same ledger answer the
 * Tool Gateway gets before any message; the form records an EVENT, never an edit. Restricting (withdraw, do
 * not contact) and widening (opt-in, operator-recorded consent, lift) are separate permissions.
 *
 * docs/114: every act may name ONE identity of the person (a phone, a PSID) or the whole customer, and a
 * do-not-contact is lifted by two people: the form asks the gateway to park it, a second person approves it
 * in the Approval Centre, and the pending lift is listed here until then.
 */

type Cell = { permitted: boolean; refusal: string | null; event_id: number | null };
type GridRow = { brand_id: number | null; brand: string | null; purpose: string; cells: Record<string, Cell> };
type IdentityGridRow = {
    identity_id: number;
    identity: string;
    channel: string;
    brand_id: number | null;
    brand: string | null;
    cells: Record<string, Cell>;
};
type PendingLift = {
    id: number;
    uuid: string;
    brand: string | null;
    identity: string | null;
    channel: string | null;
    requested_by: string | null;
    requested_by_id: number | null;
    requested_at: string | null;
    reason: string | null;
};
type Position = {
    brand_id: number | null;
    brand: string | null;
    identity_id: number | null;
    identity: string | null;
    channel: string | null;
    purpose: string | null;
    state: string;
    effective_from: string | null;
    expires_at: string | null;
    last_event_id: number;
    liftable: boolean;
    two_person: boolean;
};
type HistoryRow = {
    id: number;
    brand: string | null;
    identity: string | null;
    channel: string | null;
    purpose: string | null;
    state: string;
    basis: string;
    occurred_at: string | null;
    recorded_at: string | null;
    expires_at: string | null;
    evidence_ref: string | null;
    actor_type: string;
    actor_label: string | null;
    reason: string | null;
    two_person: boolean;
};
type Props = {
    customer: { id: number; name: string | null; phone: string | null; business: string | null };
    brands: { id: number; code: string; name: string }[];
    identities: { id: number; channel: string; label: string }[];
    channels: string[];
    purposes: string[];
    grid: GridRow[];
    identity_grid: IdentityGridRow[];
    position: Position[];
    history: HistoryRow[];
    pending_lifts: PendingLift[];
    can: { restrict: boolean; grant: boolean; lift_restriction: boolean };
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

const STATE_TONES: Record<string, Tone> = { granted: 'ok', withdrawn: 'warn', expired: 'neutral', do_not_contact: 'danger', lifted: 'info' };

const ACTS: { key: string; group: 'restrict' | 'grant' }[] = [
    { key: 'withdraw', group: 'restrict' },
    { key: 'do_not_contact', group: 'restrict' },
    { key: 'marketing_opt_in', group: 'grant' },
    { key: 'operator_consent', group: 'grant' },
    { key: 'lift', group: 'grant' },
];

export default function CustomerConsent({ customer, brands, identities, channels, purposes, grid, identity_grid, position, history, pending_lifts, can }: Props) {
    const t = useT();
    const formRef = useRef<HTMLFormElement>(null);
    const name = customer.name ?? t('Customer #{id}', { id: customer.id });
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Customers', href: '/customers' },
        { title: name, href: `/customers/${customer.id}` },
        { title: 'Consent', href: `/customers/${customer.id}/consent` },
    ];

    const stateLabel = (s: string) =>
        ({
            granted: t('Granted'),
            withdrawn: t('Withdrawn'),
            expired: t('Expired'),
            do_not_contact: t('Do not contact'),
            lifted: t('Lifted'),
        })[s] ?? s;
    const purposeLabel = (p: string | null) =>
        p === null
            ? t('All purposes')
            : (({
                  service: t('Service'),
                  marketing: t('Marketing'),
                  research: t('Research'),
                  recording: t('Recording'),
                  synthetic_identity: t('Synthetic identity'),
              }) as Record<string, string>)[p] ?? p;
    const channelLabel = (c: string | null) => (c === null ? t('All channels') : t(CHANNEL_NAMES[c] ?? c));
    const brandLabel = (b: string | null) => b ?? t('All brands');
    const identityLabel = (i: string | null) => i ?? t('Whole customer');
    const refusalLabel = (r: string | null) =>
        (({
            consent_do_not_contact: t('Do not contact'),
            consent_withdrawn: t('Withdrawn'),
            consent_expired: t('Expired'),
            consent_opt_in_missing: t('No opt-in'),
            consent_not_recorded: t('Nothing recorded'),
            consent_subject_unresolved: t('Unknown person'),
        }) as Record<string, string>)[r ?? ''] ?? t('Refused');
    const actLabel = (a: string) =>
        ({
            withdraw: t('Withdraw consent'),
            do_not_contact: t('Do not contact'),
            marketing_opt_in: t('Marketing opt-in'),
            operator_consent: t('Operator-recorded consent'),
            lift: t('Lift a restriction'),
        })[a] ?? a;
    const actHint = (a: string) =>
        ({
            withdraw: t('Stops the chosen purpose. Withdrawing marketing leaves service replies allowed.'),
            do_not_contact: t('Stops every purpose, including service replies. Beats every grant.'),
            marketing_opt_in: t('The person agreed to marketing on this channel from this brand.'),
            operator_consent: t('The person agreed to service messages on this channel.'),
            lift: t('Ends a withdrawal at exactly this scope (one person), or asks for a do-not-contact to be lifted (two people must agree). Grants nothing.'),
        })[a] ?? '';

    const allowedActs = ACTS.filter((a) => (a.key === 'lift' ? can.grant || can.lift_restriction : a.group === 'restrict' ? can.restrict : can.grant));
    const form = useForm({
        act: allowedActs[0]?.key ?? '',
        purpose: '',
        channel: '',
        brand_id: '',
        identity_id: '',
        evidence_ref: '',
        reason: '',
        occurred_at: '',
        expires_at: '',
    });
    const act = form.data.act;
    const purposeChoice = act === 'withdraw' || act === 'lift';
    const channelRequired = act === 'marketing_opt_in' || act === 'operator_consent';
    const expiryAllowed = act === 'marketing_opt_in' || act === 'operator_consent';

    const startLift = (p: Position) => {
        form.setData({
            ...form.data,
            act: 'lift',
            purpose: p.purpose ?? '',
            channel: p.channel ?? '',
            brand_id: p.brand_id === null ? '' : String(p.brand_id),
            identity_id: p.identity_id === null ? '' : String(p.identity_id),
        });
        formRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Consent')} />
            <div className="flex max-w-6xl flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={t('Consent for {name}', { name })}
                    description={t(
                        'Every change here is a new event in the consent ledger. The Tool Gateway checks the same ledger before any message is sent, so a withdrawal or do not contact takes effect at once.',
                    )}
                    actions={
                        <Button asChild variant="outline" size="sm">
                            <Link href={`/customers/${customer.id}`}>{t('Back to customer')}</Link>
                        </Button>
                    }
                />

                <section className="flex flex-col gap-2">
                    <SectionHeader title={t('What a send would get now')} aside={customer.business ?? ''} />
                    <div className="bg-card overflow-x-auto rounded-xl border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-muted-foreground text-left text-xs">
                                <tr>
                                    <th className="px-3 py-2 font-medium">{t('Brand')}</th>
                                    <th className="px-3 py-2 font-medium">{t('Purpose')}</th>
                                    {channels.map((c) => (
                                        <th key={c} className="px-3 py-2 font-medium">
                                            {channelLabel(c)}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {grid.map((row) => (
                                    <tr key={`${row.brand_id ?? 'all'}-${row.purpose}`} className="border-t">
                                        <td className="px-3 py-2 text-xs whitespace-nowrap">{brandLabel(row.brand)}</td>
                                        <td className="px-3 py-2 text-xs whitespace-nowrap">{purposeLabel(row.purpose)}</td>
                                        {channels.map((c) => {
                                            const cell = row.cells[c];
                                            return (
                                                <td key={c} className="px-3 py-2" title={cell?.event_id ? `#${cell.event_id}` : undefined}>
                                                    {cell?.permitted ? (
                                                        <StatusBadge tone="ok" dot={false}>
                                                            {t('Allowed')}
                                                        </StatusBadge>
                                                    ) : (
                                                        <StatusBadge
                                                            tone={cell?.refusal === 'consent_do_not_contact' ? 'danger' : cell?.refusal === 'consent_withdrawn' ? 'warn' : 'neutral'}
                                                            dot={false}
                                                        >
                                                            {refusalLabel(cell?.refusal ?? null)}
                                                        </StatusBadge>
                                                    )}
                                                </td>
                                            );
                                        })}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>

                <section className="flex flex-col gap-2">
                    <SectionHeader title={t('Per identity')} aside={t('On the channel each identity lives on')} />
                    {identity_grid.length === 0 ? (
                        <EmptyState title={t('No identities linked yet')} description={t('A restriction on one identity is never defeated by a grant on another.')} />
                    ) : (
                        <div className="bg-card overflow-x-auto rounded-xl border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-muted-foreground text-left text-xs">
                                    <tr>
                                        <th className="px-3 py-2 font-medium">{t('Identity')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Channel')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Brand')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Service')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Marketing')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {identity_grid.map((row) => (
                                        <tr key={`${row.identity_id}-${row.brand_id ?? 'all'}`} className="border-t">
                                            <td className="px-3 py-2 font-mono text-xs whitespace-nowrap">{row.identity}</td>
                                            <td className="px-3 py-2 text-xs whitespace-nowrap">{channelLabel(row.channel)}</td>
                                            <td className="px-3 py-2 text-xs whitespace-nowrap">{brandLabel(row.brand)}</td>
                                            {['service', 'marketing'].map((purpose) => {
                                                const cell = row.cells[purpose];
                                                return (
                                                    <td key={purpose} className="px-3 py-2" title={cell?.event_id ? `#${cell.event_id}` : undefined}>
                                                        {cell?.permitted ? (
                                                            <StatusBadge tone="ok" dot={false}>
                                                                {t('Allowed')}
                                                            </StatusBadge>
                                                        ) : (
                                                            <StatusBadge
                                                                tone={cell?.refusal === 'consent_do_not_contact' ? 'danger' : cell?.refusal === 'consent_withdrawn' ? 'warn' : 'neutral'}
                                                                dot={false}
                                                            >
                                                                {refusalLabel(cell?.refusal ?? null)}
                                                            </StatusBadge>
                                                        )}
                                                    </td>
                                                );
                                            })}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>

                {pending_lifts.length > 0 && (
                    <section className="flex flex-col gap-2">
                        <SectionHeader title={t('Lifts waiting for a second person')} aside={t('{count} pending', { count: pending_lifts.length })} />
                        <div className="bg-card overflow-x-auto rounded-xl border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-muted-foreground text-left text-xs">
                                    <tr>
                                        <th className="px-3 py-2 font-medium">#</th>
                                        <th className="px-3 py-2 font-medium">{t('Scope')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Requested by')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Requested')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Reason')}</th>
                                        <th className="px-3 py-2" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {pending_lifts.map((l) => (
                                        <tr key={l.uuid} className="border-t align-top">
                                            <td className="px-3 py-2 font-mono text-xs">{l.id}</td>
                                            <td className="px-3 py-2 text-xs">
                                                {brandLabel(l.brand)}, {identityLabel(l.identity)}, {channelLabel(l.channel)}
                                            </td>
                                            <td className="px-3 py-2 text-xs break-all">{l.requested_by ?? '-'}</td>
                                            <td className="text-muted-foreground px-3 py-2 text-xs whitespace-nowrap">{formatDateTime(l.requested_at)}</td>
                                            <td className="text-muted-foreground px-3 py-2 text-xs">{l.reason ?? '-'}</td>
                                            <td className="px-3 py-2 text-right">
                                                <Button asChild variant="outline" size="sm">
                                                    <Link href="/approvals">{t('Open in the Approval Centre')}</Link>
                                                </Button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <p className="text-muted-foreground text-xs">
                            {t('The requester never approves their own lift; a second person holding the lift permission decides it in the Approval Centre. The do-not-contact stays in force until then.')}
                        </p>
                    </section>
                )}

                <section className="flex flex-col gap-2">
                    <SectionHeader title={t('Current position')} aside={t('{count} scopes', { count: position.length })} />
                    {position.length === 0 ? (
                        <EmptyState title={t('No consent recorded')} description={t('Nothing is allowed until something is recorded.')} />
                    ) : (
                        <div className="bg-card overflow-x-auto rounded-xl border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-muted-foreground text-left text-xs">
                                    <tr>
                                        <th className="px-3 py-2 font-medium">{t('Brand')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Identity')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Channel')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Purpose')}</th>
                                        <th className="px-3 py-2 font-medium">{t('State')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Since')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Expires')}</th>
                                        <th className="px-3 py-2" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {position.map((p) => (
                                        <tr key={`${p.brand_id}-${p.identity_id}-${p.channel}-${p.purpose}`} className="border-t">
                                            <td className="px-3 py-2 text-xs">{brandLabel(p.brand)}</td>
                                            <td className="px-3 py-2 font-mono text-xs">{identityLabel(p.identity)}</td>
                                            <td className="px-3 py-2 text-xs">{channelLabel(p.channel)}</td>
                                            <td className="px-3 py-2 text-xs">{purposeLabel(p.purpose)}</td>
                                            <td className="px-3 py-2">
                                                <StatusBadge tone={STATE_TONES[p.state] ?? 'neutral'} dot={false}>
                                                    {stateLabel(p.state)}
                                                </StatusBadge>
                                            </td>
                                            <td className="text-muted-foreground px-3 py-2 text-xs whitespace-nowrap">{formatDateTime(p.effective_from)}</td>
                                            <td className="text-muted-foreground px-3 py-2 text-xs whitespace-nowrap">{formatDateTime(p.expires_at)}</td>
                                            <td className="px-3 py-2 text-right whitespace-nowrap">
                                                {p.liftable && (p.two_person ? can.lift_restriction : can.grant) && (
                                                    <Button variant="outline" size="sm" onClick={() => startLift(p)}>
                                                        {t('Lift')}
                                                    </Button>
                                                )}
                                                {p.two_person && (
                                                    <span className="ml-2">
                                                        <StatusBadge tone="info" dot={false}>
                                                            {t('Two-person')}
                                                        </StatusBadge>
                                                    </span>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>

                {allowedActs.length > 0 && (
                    <section className="bg-card flex flex-col gap-4 rounded-xl border p-4">
                        <SectionHeader title={t('Record a consent event')} />
                        <form
                            ref={formRef}
                            className="flex flex-col gap-4"
                            onSubmit={(e) => {
                                e.preventDefault();
                                form.transform((d) => ({
                                    ...d,
                                    purpose: purposeChoice && d.purpose !== '' ? d.purpose : null,
                                    channel: d.channel === '' ? null : d.channel,
                                    brand_id: d.brand_id === '' ? null : Number(d.brand_id),
                                    identity_id: d.identity_id === '' ? null : Number(d.identity_id),
                                    // The browser's local time, sent as an absolute instant so the server never guesses a zone.
                                    occurred_at: d.occurred_at === '' ? null : new Date(d.occurred_at).toISOString(),
                                    expires_at: expiryAllowed && d.expires_at !== '' ? new Date(d.expires_at).toISOString() : null,
                                }));
                                form.post(`/customers/${customer.id}/consent`, {
                                    preserveScroll: true,
                                    onSuccess: () => form.reset('evidence_ref', 'reason', 'occurred_at', 'expires_at'),
                                });
                            }}
                        >
                            <FormRow>
                                <Field label={t('Act')} htmlFor="act" size="auto" hint={actHint(act)} error={form.errors.act}>
                                    <NativeSelect id="act" value={act} onChange={(e) => form.setData('act', e.target.value)} required>
                                        {allowedActs.map((a) => (
                                            <option key={a.key} value={a.key}>
                                                {actLabel(a.key)}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </Field>
                                {purposeChoice && (
                                    <Field label={t('Purpose')} htmlFor="purpose" size="auto" error={form.errors.purpose}>
                                        <NativeSelect id="purpose" value={form.data.purpose} onChange={(e) => form.setData('purpose', e.target.value)}>
                                            <option value="">{t('All purposes')}</option>
                                            {purposes.map((p) => (
                                                <option key={p} value={p}>
                                                    {purposeLabel(p)}
                                                </option>
                                            ))}
                                        </NativeSelect>
                                    </Field>
                                )}
                                <Field label={t('Channel')} htmlFor="channel" size="auto" error={form.errors.channel}>
                                    <NativeSelect
                                        id="channel"
                                        value={form.data.channel}
                                        onChange={(e) => form.setData('channel', e.target.value)}
                                        required={channelRequired}
                                    >
                                        {!channelRequired && <option value="">{t('All channels')}</option>}
                                        {channelRequired && <option value="">{t('Choose a channel')}</option>}
                                        {Object.keys(CHANNEL_NAMES).map((c) => (
                                            <option key={c} value={c}>
                                                {channelLabel(c)}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </Field>
                                {brands.length > 0 && (
                                    <Field label={t('Brand')} htmlFor="brand" size="auto" error={form.errors.brand_id}>
                                        <NativeSelect
                                            id="brand"
                                            value={form.data.brand_id}
                                            onChange={(e) => form.setData('brand_id', e.target.value)}
                                            required={act === 'marketing_opt_in'}
                                        >
                                            <option value="">{act === 'marketing_opt_in' ? t('Choose a brand') : t('All brands')}</option>
                                            {brands.map((b) => (
                                                <option key={b.id} value={b.id}>
                                                    {b.name}
                                                </option>
                                            ))}
                                        </NativeSelect>
                                    </Field>
                                )}
                                {identities.length > 0 && (
                                    <Field
                                        label={t('Identity')}
                                        htmlFor="identity"
                                        size="auto"
                                        hint={t('One phone or account of this person, or the whole customer.')}
                                        error={form.errors.identity_id}
                                    >
                                        <NativeSelect id="identity" value={form.data.identity_id} onChange={(e) => form.setData('identity_id', e.target.value)}>
                                            <option value="">{t('Whole customer')}</option>
                                            {identities.map((i) => (
                                                <option key={i.id} value={i.id}>
                                                    {i.label}
                                                </option>
                                            ))}
                                        </NativeSelect>
                                    </Field>
                                )}
                            </FormRow>
                            <FormRow>
                                <Field
                                    label={t('Evidence')}
                                    htmlFor="evidence"
                                    size="lg"
                                    hint={t('Where it is written down: a message, a call, a form.')}
                                    error={form.errors.evidence_ref}
                                >
                                    <Input
                                        id="evidence"
                                        value={form.data.evidence_ref}
                                        onChange={(e) => form.setData('evidence_ref', e.target.value)}
                                        maxLength={191}
                                        required
                                    />
                                </Field>
                                <Field
                                    label={t('When it happened')}
                                    htmlFor="occurred"
                                    size="auto"
                                    hint={t('Leave empty for now.')}
                                    error={form.errors.occurred_at}
                                >
                                    <Input
                                        id="occurred"
                                        type="datetime-local"
                                        value={form.data.occurred_at}
                                        onChange={(e) => form.setData('occurred_at', e.target.value)}
                                    />
                                </Field>
                                {expiryAllowed && (
                                    <Field label={t('Expires')} htmlFor="expires" size="auto" hint={t('Optional.')} error={form.errors.expires_at}>
                                        <Input
                                            id="expires"
                                            type="datetime-local"
                                            value={form.data.expires_at}
                                            onChange={(e) => form.setData('expires_at', e.target.value)}
                                        />
                                    </Field>
                                )}
                            </FormRow>
                            <Field label={t('Reason')} htmlFor="reason" error={form.errors.reason}>
                                <textarea
                                    id="reason"
                                    value={form.data.reason}
                                    onChange={(e) => form.setData('reason', e.target.value)}
                                    rows={2}
                                    maxLength={1000}
                                    required
                                    className="border-input bg-background focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                                />
                            </Field>
                            <div>
                                <Button type="submit" disabled={form.processing || act === ''}>
                                    {t('Record event')}
                                </Button>
                            </div>
                        </form>
                    </section>
                )}

                <section className="flex flex-col gap-2">
                    <SectionHeader title={t('History')} aside={t('{count} events', { count: history.length })} />
                    {history.length === 0 ? (
                        <EmptyState title={t('No consent recorded')} description={t('Nothing is allowed until something is recorded.')} />
                    ) : (
                        <div className="bg-card overflow-x-auto rounded-xl border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-muted-foreground text-left text-xs">
                                    <tr>
                                        <th className="px-3 py-2 font-medium">#</th>
                                        <th className="px-3 py-2 font-medium">{t('State')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Scope')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Basis')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Who')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Evidence')}</th>
                                        <th className="px-3 py-2 font-medium">{t('When it happened')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Recorded')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {history.map((h) => (
                                        <tr key={h.id} className="border-t align-top">
                                            <td className="px-3 py-2 font-mono text-xs">{h.id}</td>
                                            <td className="px-3 py-2">
                                                <StatusBadge tone={STATE_TONES[h.state] ?? 'neutral'} dot={false}>
                                                    {stateLabel(h.state)}
                                                </StatusBadge>
                                            </td>
                                            <td className="px-3 py-2 text-xs">
                                                {brandLabel(h.brand)}, {identityLabel(h.identity)}, {channelLabel(h.channel)}, {purposeLabel(h.purpose)}
                                                {h.two_person ? (
                                                    <div>
                                                        <StatusBadge tone="info" dot={false}>
                                                            {t('Two-person')}
                                                        </StatusBadge>
                                                    </div>
                                                ) : null}
                                                {h.expires_at ? (
                                                    <div className="text-muted-foreground">{t('Expires {at}', { at: formatDateTime(h.expires_at) })}</div>
                                                ) : null}
                                            </td>
                                            <td className="px-3 py-2 text-xs">{h.basis}</td>
                                            <td className="px-3 py-2 text-xs break-all">{h.actor_label ?? h.actor_type}</td>
                                            <td className="px-3 py-2 text-xs">
                                                <div className="break-all">{h.evidence_ref ?? '-'}</div>
                                                {h.reason ? <div className="text-muted-foreground">{h.reason}</div> : null}
                                            </td>
                                            <td className="text-muted-foreground px-3 py-2 text-xs whitespace-nowrap">{formatDateTime(h.occurred_at)}</td>
                                            <td className="text-muted-foreground px-3 py-2 text-xs whitespace-nowrap">{formatDateTime(h.recorded_at)}</td>
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
