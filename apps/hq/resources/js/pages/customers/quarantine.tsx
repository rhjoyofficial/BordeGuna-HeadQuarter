import { EmptyState } from '@/components/empty-state';
import { Field, FormRow, NativeSelect } from '@/components/form/field';
import { PageHeader, SectionHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { StatusBadge, type Tone } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';

/**
 * docs/117 (ruling docs/110 §5 (m)): quarantine flags on relationships that cross a business boundary, and
 * the audited human review of each. A review records a decision only: it lifts no suppression, undoes no
 * merge and re-points nothing. A flag is listed only to a person who may see both of its businesses.
 */

type Review = { decision: string; note: string; by: string | null; at: string | null };
type Flag = {
    id: number;
    kind: string;
    subject: string;
    customer_id: number;
    customer_business: string | null;
    row_business: string | null;
    merge_id: number | null;
    reason: string;
    detected_at: string | null;
    reviews: number;
    review: Review | null;
};
type Props = { flags: Flag[]; decisions: string[] };

const DECISION_TONES: Record<string, Tone> = { confirmed_wrong: 'danger', needs_repair: 'warn', disputed: 'info' };

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Customers', href: '/customers' },
    { title: 'Cross-business review', href: '/customers/quarantine' },
];

export default function CustomerQuarantine({ flags, decisions }: Props) {
    const t = useT();
    const [open, setOpen] = useState<number | null>(null);
    const form = useForm({ decision: decisions[0] ?? '', note: '' });

    const decisionLabel = (d: string) =>
        (({ confirmed_wrong: t('Confirmed wrong'), needs_repair: t('Needs repair'), disputed: t('Disputed (needs a ruling)') }) as Record<string, string>)[d] ?? d;
    const decisionHint = (d: string) =>
        (({
            confirmed_wrong: t('This relationship should not exist.'),
            needs_repair: t('It should be repaired when the lineage-preserving repair is ruled and built.'),
            disputed: t('You believe it is legitimate. Explain why; it stays held, because merges across businesses are not allowed.'),
        }) as Record<string, string>)[d] ?? '';
    const kindLabel = (k: string) =>
        (({
            merge: t('Merge'),
            merge_pointer: t('Merge link'),
            identity: t('Identity'),
            order: t('Order copy'),
            conversation: t('Conversation'),
            memory: t('Memory'),
            consent_event: t('Consent event'),
            consent_state: t('Consent position'),
        }) as Record<string, string>)[k] ?? k;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Cross-business review')} />
            <div className="flex max-w-6xl flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={t('Cross-business review')}
                    description={t(
                        'Relationships that cross a business boundary, left by merges made before they were refused. They are already hidden from every customer page. Your review is recorded and audited; it does not undo, move or delete anything.',
                    )}
                />

                <section className="flex flex-col gap-2">
                    <SectionHeader title={t('Flags')} aside={t('{count} flags', { count: flags.length })} />
                    {flags.length === 0 ? (
                        <EmptyState
                            title={t('Nothing is flagged')}
                            description={t('No relationship crossing a business boundary has been flagged for review.')}
                        />
                    ) : (
                        <div className="bg-card overflow-x-auto rounded-xl border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-muted-foreground text-left text-xs">
                                    <tr>
                                        <th className="px-3 py-2 font-medium">#</th>
                                        <th className="px-3 py-2 font-medium">{t('Kind')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Record')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Customer')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Record business')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Reason')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Latest review')}</th>
                                        <th className="px-3 py-2" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {flags.map((f) => (
                                        <tr key={f.id} className="border-t align-top">
                                            <td className="px-3 py-2 font-mono text-xs">{f.id}</td>
                                            <td className="px-3 py-2 text-xs">{kindLabel(f.kind)}</td>
                                            <td className="px-3 py-2 font-mono text-xs break-all">
                                                {f.subject}
                                                {f.merge_id !== null ? (
                                                    <div className="text-muted-foreground">{t('Merge #{id}', { id: f.merge_id })}</div>
                                                ) : (
                                                    <div className="text-muted-foreground">{t('No merge lineage')}</div>
                                                )}
                                            </td>
                                            <td className="px-3 py-2 text-xs">
                                                <Link href={`/customers/${f.customer_id}`} className="hover:underline">
                                                    {t('Customer #{id}', { id: f.customer_id })}
                                                </Link>
                                                <div className="text-muted-foreground">{f.customer_business ?? '-'}</div>
                                            </td>
                                            <td className="px-3 py-2 text-xs">{f.row_business ?? t('No business on the record')}</td>
                                            <td className="px-3 py-2 font-mono text-xs break-all">
                                                {f.reason}
                                                <div className="text-muted-foreground font-sans">{formatDateTime(f.detected_at)}</div>
                                            </td>
                                            <td className="px-3 py-2 text-xs">
                                                {f.review === null ? (
                                                    <span className="text-muted-foreground">{t('No review yet')}</span>
                                                ) : (
                                                    <div className="flex flex-col gap-1">
                                                        <StatusBadge tone={DECISION_TONES[f.review.decision] ?? 'neutral'} dot={false}>
                                                            {decisionLabel(f.review.decision)}
                                                        </StatusBadge>
                                                        <div className="break-words">{f.review.note}</div>
                                                        <div className="text-muted-foreground break-all">
                                                            {f.review.by ?? '-'}, {formatDateTime(f.review.at)}
                                                        </div>
                                                        {f.reviews > 1 && (
                                                            <div className="text-muted-foreground">{t('{count} reviews in all', { count: f.reviews })}</div>
                                                        )}
                                                    </div>
                                                )}
                                            </td>
                                            <td className="px-3 py-2 text-right">
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() => {
                                                        form.reset();
                                                        setOpen(open === f.id ? null : f.id);
                                                    }}
                                                >
                                                    {t('Review')}
                                                </Button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>

                {open !== null && (
                    <section className="bg-card flex flex-col gap-4 rounded-xl border p-4">
                        <SectionHeader title={t('Review flag #{id}', { id: open })} />
                        <form
                            className="flex flex-col gap-4"
                            onSubmit={(e) => {
                                e.preventDefault();
                                form.post(`/customers/quarantine/${open}/review`, {
                                    preserveScroll: true,
                                    onSuccess: () => {
                                        form.reset();
                                        setOpen(null);
                                    },
                                });
                            }}
                        >
                            <FormRow>
                                <Field
                                    label={t('Decision')}
                                    htmlFor="decision"
                                    size="auto"
                                    hint={decisionHint(form.data.decision)}
                                    error={form.errors.decision}
                                >
                                    <NativeSelect id="decision" value={form.data.decision} onChange={(e) => form.setData('decision', e.target.value)} required>
                                        {decisions.map((d) => (
                                            <option key={d} value={d}>
                                                {decisionLabel(d)}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </Field>
                            </FormRow>
                            <Field label={t('Note')} htmlFor="note" error={form.errors.note}>
                                <textarea
                                    id="note"
                                    value={form.data.note}
                                    onChange={(e) => form.setData('note', e.target.value)}
                                    rows={3}
                                    minLength={3}
                                    maxLength={1000}
                                    required
                                    className="border-input bg-background focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                                />
                            </Field>
                            <div>
                                <Button type="submit" disabled={form.processing}>
                                    {t('Record review')}
                                </Button>
                            </div>
                        </form>
                    </section>
                )}
            </div>
        </AppLayout>
    );
}
