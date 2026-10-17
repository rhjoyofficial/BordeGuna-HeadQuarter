import { EmptyState } from '@/components/empty-state';
import { Field, FormRow, NativeSelect } from '@/components/form/field';
import { PageHeader, SectionHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Notice } from '@/components/ui/notice';
import { StatusBadge, type Tone } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';

/**
 * docs/140 (ruling docs/131 item 3, docs/138 P3-W1): the open identity conflicts of the businesses this person may read,
 * and, for a person holding hq.customers.identity_conflicts.resolve, the resolution of ONE conflict in a popup (Joy's
 * rule). The choice goes to the Tool Gateway as the permanently human-only tool customers.identity_conflicts.resolve;
 * no agent can hold or call it. Three choices only: keep the owner, move to the claimant of the same business, or mark
 * not resolvable. Evidence and a reason are required. A held identity comes back live only when the rows support it;
 * when they do not, the screen says why and keeping the owner is not offered.
 */

type Conflict = {
    id: number;
    business: string | null;
    kind: string;
    reason: string;
    source: string;
    identity_id: number;
    channel: string | null;
    key: string | null;
    store_id: number | null;
    held: boolean;
    held_reason: string | null;
    owner_customer_id: number | null;
    holder_customer_id: number | null;
    claimant_customer_id: number | null;
    occurrences: number;
    first_seen_at: string | null;
    last_seen_at: string | null;
    release_blockers: string[];
    choices: string[];
};
type Resolved = {
    id: number;
    conflict_id: number;
    business: string | null;
    resolution: string;
    owner_before: number;
    owner_after: number;
    released: boolean;
    reason: string;
    by: string | null;
    at: string | null;
};
type Props = { conflicts: Conflict[]; resolved: Resolved[]; can_resolve: boolean };

const RESOLUTION_TONES: Record<string, Tone> = { kept_owner: 'ok', moved_to_claimant: 'info', not_resolvable: 'warn' };

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Customers', href: '/customers' },
    { title: 'Identity conflicts', href: '/customers/identity-conflicts' },
];

export default function IdentityConflicts({ conflicts, resolved, can_resolve }: Props) {
    const t = useT();
    const [open, setOpen] = useState<Conflict | null>(null);
    const form = useForm({ resolution: '', evidence: '', reason: '' });
    const errors = form.errors as Record<string, string | undefined>;

    const resolutionLabel = (r: string) =>
        (({
            kept_owner: t('Keep the current owner'),
            moved_to_claimant: t('Move to the claimant (same business)'),
            not_resolvable: t('Mark not resolvable'),
        }) as Record<string, string>)[r] ?? r;
    const resolutionHint = (r: string) =>
        (({
            kept_owner: t('The identity stays with the customer who holds it. A held identity comes back live only because the rows support it.'),
            moved_to_claimant: t('The identity moves to the claimant, a customer of the same business. Permissions do not follow it; stop-contact records do.'),
            not_resolvable: t('The conflict closes and the identity is left as it is. A held identity stays held as evidence.'),
        }) as Record<string, string>)[r] ?? '';
    const blockerLabel = (b: string) =>
        (({
            live_copy: t('Another live identity already carries this key in this business.'),
            store_business: t('Its store belongs to another business.'),
            no_own_use: t('No order, thread or consent record of this business uses it.'),
        }) as Record<string, string>)[b] ?? b;
    const kindLabel = (k: string) =>
        (({ link_refused: t('Re-point refused'), quarantined: t('Held by the migration') }) as Record<string, string>)[k] ?? k;

    const start = (c: Conflict) => {
        form.reset();
        form.clearErrors();
        form.setData({ resolution: c.choices[0] ?? '', evidence: '', reason: '' });
        setOpen(c);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Identity conflicts')} />
            <div className="flex max-w-6xl flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={t('Identity conflicts')}
                    description={t(
                        'Phone numbers, page ids and shop ids that HQ refused to move from one customer to another, or held because the rows contradicted each other. Each belongs to one business. Only a person resolves one, with evidence; no agent can.',
                    )}
                />

                {!can_resolve && (
                    <Notice tone="neutral">{t('You can see these conflicts. Resolving one needs the identity conflict permission, held by the owner and the operator.')}</Notice>
                )}

                <section className="flex flex-col gap-2">
                    <SectionHeader title={t('Open conflicts')} aside={t('{count} open', { count: conflicts.length })} />
                    {conflicts.length === 0 ? (
                        <EmptyState title={t('No open conflict')} description={t('No identity of a business you may read is waiting for a person.')} />
                    ) : (
                        <div className="hq-table-box">
                            <table className="w-full text-sm">
                                <thead className="text-left text-xs">
                                    <tr>
                                        <th className="px-3 py-2 font-medium">#</th>
                                        <th className="px-3 py-2 font-medium">{t('Business')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Kind')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Identity')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Owner')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Claimant')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Seen')}</th>
                                        <th className="px-3 py-2" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {conflicts.map((c) => (
                                        <tr key={c.id} className="border-t align-top">
                                            <td className="px-3 py-2 font-mono text-xs">{c.id}</td>
                                            <td className="px-3 py-2 text-xs">{c.business ?? '-'}</td>
                                            <td className="px-3 py-2 text-xs">
                                                {kindLabel(c.kind)}
                                                <div className="text-muted-foreground font-mono break-all">{c.reason}</div>
                                            </td>
                                            <td className="px-3 py-2 text-xs">
                                                <span className="font-mono">{t('Identity #{id}', { id: c.identity_id })}</span>
                                                <div className="text-muted-foreground font-mono break-all">
                                                    {c.channel ?? '-'} {c.key ?? ''}
                                                    {c.store_id !== null ? ` (${t('Store #{id}', { id: c.store_id })})` : ''}
                                                </div>
                                                {c.held && (
                                                    <StatusBadge tone="warn" dot={false}>
                                                        {t('Held')}
                                                    </StatusBadge>
                                                )}
                                            </td>
                                            <td className="px-3 py-2 text-xs">
                                                {c.owner_customer_id !== null ? (
                                                    <Link href={`/customers/${c.owner_customer_id}`} className="hover:underline">
                                                        {t('Customer #{id}', { id: c.owner_customer_id })}
                                                    </Link>
                                                ) : (
                                                    '-'
                                                )}
                                            </td>
                                            <td className="px-3 py-2 text-xs">
                                                {c.claimant_customer_id !== null ? (
                                                    <Link href={`/customers/${c.claimant_customer_id}`} className="hover:underline">
                                                        {t('Customer #{id}', { id: c.claimant_customer_id })}
                                                    </Link>
                                                ) : (
                                                    <span className="text-muted-foreground">{t('None')}</span>
                                                )}
                                            </td>
                                            <td className="px-3 py-2 text-xs">
                                                {t('{count} times', { count: c.occurrences })}
                                                <div className="text-muted-foreground">{formatDateTime(c.last_seen_at)}</div>
                                            </td>
                                            <td className="px-3 py-2 text-right">
                                                {can_resolve && (
                                                    <Button variant="outline" size="sm" onClick={() => start(c)}>
                                                        {t('Resolve')}
                                                    </Button>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>

                {resolved.length > 0 && (
                    <section className="flex flex-col gap-2">
                        <SectionHeader title={t('Resolved by a person')} aside={t('{count} latest', { count: resolved.length })} />
                        <ul className="hq-card flex flex-col divide-y text-xs">
                            {resolved.map((r) => (
                                <li key={r.id} className="flex flex-col gap-1 px-3 py-2">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="font-mono">{t('Conflict #{id}', { id: r.conflict_id })}</span>
                                        <span>{r.business ?? '-'}</span>
                                        <StatusBadge tone={RESOLUTION_TONES[r.resolution] ?? 'neutral'} dot={false}>
                                            {resolutionLabel(r.resolution)}
                                        </StatusBadge>
                                        {r.released && <span className="text-muted-foreground">{t('Released from hold')}</span>}
                                    </div>
                                    <span className="text-muted-foreground">
                                        {t('Owner: customer #{before} to customer #{after}', { before: r.owner_before, after: r.owner_after })}
                                    </span>
                                    <span className="break-words">{r.reason}</span>
                                    <span className="text-muted-foreground break-all">
                                        {r.by ?? '-'}, {formatDateTime(r.at)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                <Dialog open={open !== null} onOpenChange={(o) => !o && setOpen(null)}>
                    <DialogContent className="sm:max-w-xl">
                        <DialogHeader>
                            <DialogTitle>{t('Resolve conflict #{id}', { id: open?.id ?? 0 })}</DialogTitle>
                            <DialogDescription>
                                {t('Recorded with your name, the evidence and the reason, and never changed afterwards. Nothing moves to another business.')}
                            </DialogDescription>
                        </DialogHeader>
                        {open !== null && (
                            <form
                                className="flex flex-col gap-4"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    form.post(`/customers/identity-conflicts/${open.id}/resolve`, {
                                        preserveScroll: true,
                                        onSuccess: () => {
                                            form.reset();
                                            setOpen(null);
                                        },
                                    });
                                }}
                            >
                                {open.release_blockers.length > 0 && (
                                    <Notice tone="warn" title={t('The rows do not support releasing this held identity')}>
                                        <ul className="list-disc pl-4">
                                            {open.release_blockers.map((b) => (
                                                <li key={b}>{blockerLabel(b)}</li>
                                            ))}
                                        </ul>
                                    </Notice>
                                )}
                                <FormRow>
                                    <Field
                                        label={t('Resolution')}
                                        htmlFor="resolution"
                                        size="auto"
                                        hint={resolutionHint(form.data.resolution)}
                                        error={errors.resolution}
                                    >
                                        <NativeSelect
                                            id="resolution"
                                            value={form.data.resolution}
                                            onChange={(e) => form.setData('resolution', e.target.value)}
                                            required
                                        >
                                            {open.choices.map((c) => (
                                                <option key={c} value={c}>
                                                    {resolutionLabel(c)}
                                                </option>
                                            ))}
                                        </NativeSelect>
                                    </Field>
                                </FormRow>
                                <Field label={t('Evidence you relied on')} htmlFor="evidence" error={errors.evidence}>
                                    <Textarea
                                        id="evidence"
                                        value={form.data.evidence}
                                        onChange={(e) => form.setData('evidence', e.target.value)}
                                        rows={3}
                                        minLength={10}
                                        maxLength={2000}
                                        required
                                    />
                                </Field>
                                <Field label={t('Reason')} htmlFor="reason" error={errors.reason}>
                                    <Textarea
                                        id="reason"
                                        value={form.data.reason}
                                        onChange={(e) => form.setData('reason', e.target.value)}
                                        rows={2}
                                        minLength={10}
                                        maxLength={2000}
                                        required
                                    />
                                </Field>
                                <div className="flex flex-wrap justify-end gap-2">
                                    <Button type="button" variant="outline" onClick={() => setOpen(null)}>
                                        {t('Cancel')}
                                    </Button>
                                    <Button type="submit" disabled={form.processing}>
                                        {t('Record resolution')}
                                    </Button>
                                </div>
                            </form>
                        )}
                    </DialogContent>
                </Dialog>
            </div>
        </AppLayout>
    );
}
