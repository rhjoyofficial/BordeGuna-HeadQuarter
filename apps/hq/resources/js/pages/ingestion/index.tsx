import { EmptyState } from '@/components/empty-state';
import { Field, FormRow, NativeSelect } from '@/components/form/field';
import { PageHeader, SectionHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Notice } from '@/components/ui/notice';
import { StatusBadge, type Tone } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, humanKey } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';

type Doc = {
    uuid: string;
    status: string;
    business: string | null;
    from: string | null;
    subject: string | null;
    received_at: string | null;
    supplier: string | null;
    invoice_number: string | null;
    total: string | null;
    warnings: number;
};
type Props = {
    documents: Doc[];
    businesses: { slug: string; name: string }[];
    filters: { status: string | null };
    can: { review: boolean; poll: boolean };
};

const TONE: Record<string, Tone> = { draft: 'pending', reviewed: 'info', rejected: 'neutral', sent_to_erp: 'ok', erp_failed: 'danger' };
const breadcrumbs: BreadcrumbItem[] = [{ title: 'Supplier documents', href: '/ingestion' }];

/**
 * Bill drafts HQ read from the supplier mailbox (docs/144-email-ingestion). Every row is a DRAFT: nothing here has
 * reached the ERP, and nothing was sent, deleted or marked in the mailbox.
 */
export default function IngestionIndex({ documents, businesses, can }: Props) {
    const t = useT();
    const poll = useForm({ business_slug: businesses[0]?.slug ?? '' });
    const statusLabel = (s: string) =>
        (
            ({
                draft: t('Waiting for review'),
                reviewed: t('Reviewed'),
                rejected: t('Rejected'),
                sent_to_erp: t('Draft bill in the ERP'),
                erp_failed: t('The ERP refused'),
            }) as Record<string, string>
        )[s] ?? humanKey(s);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Supplier documents')} />
            <div className="flex max-w-5xl flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={t('Supplier documents')}
                    description={t(
                        'Bills read from the supplier mailbox, each field beside the words it came from. These are drafts for a person to check; nothing has reached the ERP.',
                    )}
                />

                {can.poll && (
                    <form
                        className="hq-card flex flex-col gap-3 p-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            poll.post('/ingestion/poll', { preserveScroll: true });
                        }}
                    >
                        <SectionHeader title={t('Read the mailbox now')} />
                        <FormRow>
                            <Field
                                label={t('Business')}
                                htmlFor="i-business"
                                size="lg"
                                error={(poll.errors as Record<string, string | undefined>).poll ?? poll.errors.business_slug}
                            >
                                <NativeSelect
                                    id="i-business"
                                    value={poll.data.business_slug}
                                    onChange={(e) => poll.setData('business_slug', e.target.value)}
                                >
                                    {businesses.map((b) => (
                                        <option key={b.slug} value={b.slug}>
                                            {b.name}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </Field>
                        </FormRow>
                        <div>
                            <Button type="submit" disabled={poll.processing}>
                                {t('Read new mail')}
                            </Button>
                        </div>
                        <Notice tone="neutral">{t('If the mailbox is not connected yet, this says what is missing. See Connectors.')}</Notice>
                    </form>
                )}

                {documents.length === 0 ? (
                    <EmptyState
                        title={t('No documents yet')}
                        description={t('Drafts appear after the mailbox is read and a supplier bill is found.')}
                    />
                ) : (
                    <ul className="flex flex-col gap-2">
                        {documents.map((d) => (
                            <li key={d.uuid}>
                                <Link
                                    href={`/ingestion/${d.uuid}`}
                                    className="hq-card hover:bg-accent/40 flex min-h-11 flex-wrap items-center gap-x-4 gap-y-1 p-3 text-sm transition-colors"
                                >
                                    <StatusBadge tone={TONE[d.status] ?? 'neutral'}>{statusLabel(d.status)}</StatusBadge>
                                    <span className="font-medium">{d.supplier ?? t('Supplier not read')}</span>
                                    <span className="font-mono text-xs">{d.invoice_number ?? '-'}</span>
                                    <span>{d.total ? `BDT ${d.total}` : '-'}</span>
                                    {d.warnings > 0 && (
                                        <span className="text-tone-warn text-xs">{t('{count} things to check', { count: d.warnings })}</span>
                                    )}
                                    <span className="text-muted-foreground ml-auto text-xs">
                                        {d.business} · {formatDateTime(d.received_at)}
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </AppLayout>
    );
}
