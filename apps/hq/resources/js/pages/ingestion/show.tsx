import { PageHeader, SectionHeader, TechnicalDetail } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Notice } from '@/components/ui/notice';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';

type Citation = { text: string; start: number; end: number };
type Field = { value: string; currency?: string; citation: Citation } | null;
type Line = { description: Field; quantity: Field; unit_price: Field; line_total: Field };
type Doc = {
    uuid: string;
    status: string;
    business: string | null;
    business_slug: string | null;
    from: string | null;
    subject: string | null;
    received_at: string | null;
    extractor: string;
    extracted: { supplier: Field; invoice_number: Field; invoice_date: Field; total: Field; lines: Line[]; warnings: string[] };
    source_text: string;
    review_note: string | null;
    erp_draft_ref: string | null;
    erp_draft_error: string | null;
};

/** A field and the exact words it was read from, so a person never has to trust a number they cannot point at. */
function Cited({ label, field }: { label: string; field: Field }) {
    const t = useT();
    return (
        <div className="flex flex-col gap-0.5">
            <div className="text-muted-foreground text-xs">{label}</div>
            {field === null ? (
                <div className="text-tone-warn text-sm">{t('Not read from the document')}</div>
            ) : (
                <>
                    <div className="font-medium break-words">
                        {field.value}
                        {field.currency ? ` ${field.currency}` : ''}
                    </div>
                    <blockquote className="border-glass-border text-muted-foreground border-l-2 pl-2 font-mono text-xs break-words whitespace-pre-wrap">{field.citation.text}</blockquote>
                </>
            )}
        </div>
    );
}

export default function IngestionShow({ document: d, can }: { document: Doc; can: { review: boolean; send: boolean } }) {
    const t = useT();
    const form = useForm({ note: '' });
    const decide = (decision: 'reviewed' | 'rejected') => router.post(`/ingestion/${d.uuid}/decide`, { decision, note: form.data.note }, { preserveScroll: true });
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Supplier documents', href: '/ingestion' },
        { title: d.extracted.invoice_number?.value ?? d.uuid.slice(0, 8), href: `/ingestion/${d.uuid}` },
    ];
    const open = d.status === 'draft' || d.status === 'erp_failed';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Supplier document')} />
            <div className="flex max-w-5xl flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={d.extracted.supplier?.value ?? t('Supplier document')}
                    description={`${d.business ?? ''} · ${d.from ?? ''} · ${formatDateTime(d.received_at)}`}
                    actions={<StatusBadge status={d.status} />}
                />
                <Notice tone="warn">{t('This is a draft read by a program. Check every field against the words beside it before you trust it.')}</Notice>

                {d.extracted.warnings.length > 0 && (
                    <Notice tone="pending" title={t('Things to check')}>
                        <ul className="list-disc pl-5">
                            {d.extracted.warnings.map((w) => (
                                <li key={w}>{w}</li>
                            ))}
                        </ul>
                    </Notice>
                )}

                <section className="hq-card grid gap-4 p-4 md:grid-cols-2">
                    <Cited label={t('Supplier')} field={d.extracted.supplier} />
                    <Cited label={t('Invoice number')} field={d.extracted.invoice_number} />
                    <Cited label={t('Invoice date')} field={d.extracted.invoice_date} />
                    <Cited label={t('Total')} field={d.extracted.total} />
                </section>

                <section className="flex flex-col gap-2">
                    <SectionHeader title={t('Lines')} aside={String(d.extracted.lines.length)} />
                    {d.extracted.lines.length === 0 ? (
                        <p className="text-muted-foreground text-sm">{t('No lines could be read.')}</p>
                    ) : (
                        <div className="hq-table-box">
                            <table className="w-full text-sm">
                                <thead className="text-left text-xs">
                                    <tr>
                                        <th className="px-3 py-2 font-medium">{t('Item')}</th>
                                        <th className="px-3 py-2 text-right font-medium">{t('Quantity')}</th>
                                        <th className="px-3 py-2 text-right font-medium">{t('Unit price')}</th>
                                        <th className="px-3 py-2 text-right font-medium">{t('Line total')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Read from')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {d.extracted.lines.map((l, i) => (
                                        <tr key={i} className="border-t align-top">
                                            <td className="px-3 py-2">{l.description?.value}</td>
                                            <td className="px-3 py-2 text-right tabular-nums">{l.quantity?.value}</td>
                                            <td className="px-3 py-2 text-right tabular-nums">{l.unit_price?.value}</td>
                                            <td className="px-3 py-2 text-right tabular-nums">{l.line_total?.value}</td>
                                            <td className="text-muted-foreground px-3 py-2 font-mono text-xs break-words">{l.description?.citation.text}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>

                <TechnicalDetail summary={t('The full text it was read from')}>
                    <pre className="text-xs whitespace-pre-wrap">{d.source_text}</pre>
                </TechnicalDetail>

                {d.erp_draft_ref && <Notice tone="ok">{t('A draft bill exists in the ERP: {ref}. The ERP keeps its own approval.', { ref: d.erp_draft_ref })}</Notice>}
                {d.erp_draft_error && <Notice tone="danger">{d.erp_draft_error}</Notice>}
                {d.review_note && <Notice tone="neutral">{d.review_note}</Notice>}

                {can.review && open && (
                    <div className="hq-card flex flex-col gap-3 p-4">
                        <SectionHeader title={t('Your decision')} />
                        <Textarea value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} rows={2} placeholder={t('A note (optional)')} aria-label={t('A note (optional)')} />
                        <div className="flex flex-wrap gap-2">
                            <Button type="button" onClick={() => decide('reviewed')}>
                                {t('I checked it: mark reviewed')}
                            </Button>
                            <Button type="button" variant="outline" onClick={() => decide('rejected')}>
                                {t('Reject')}
                            </Button>
                        </div>
                    </div>
                )}

                {can.send && d.status === 'reviewed' && (
                    <div className="hq-card flex flex-col gap-2 p-4">
                        <SectionHeader title={t('Send to the ERP as a draft bill')} />
                        <p className="text-muted-foreground text-sm">
                            {t('This asks the ERP for a draft bill. A second person must approve the request, and the ERP keeps its own approval after that.')}
                        </p>
                        <div>
                            <Button onClick={() => router.post(`/ingestion/${d.uuid}/send-to-erp`, {}, { preserveScroll: true })}>{t('Ask for a draft bill')}</Button>
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
