import { EmptyState } from '@/components/empty-state';
import { Field, FormRow, NativeSelect } from '@/components/form/field';
import { PageHeader, SectionHeader } from '@/components/page-header';
import { Pagination, type ListMeta } from '@/components/pagination';
import { ParcelTimeline, type Parcel } from '@/components/parcel-timeline';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Notice } from '@/components/ui/notice';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';

type Row = Parcel & { business: string | null };
type Props = {
    parcels: Row[];
    pagination: ListMeta;
    businesses: { slug: string; name: string }[];
    providers: string[];
    filters: { status: string | null };
};

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Parcels', href: '/parcels' }];

/**
 * Where parcels are, from the couriers (docs/144-courier-connectors). HQ reads and listens; it books nothing and writes
 * nothing to the ERP. A parcel with no order is shown as unlinked, never guessed onto one.
 */
export default function Parcels({ parcels, pagination, businesses, providers }: Props) {
    const t = useT();
    const form = useForm({ business_slug: businesses[0]?.slug ?? '', courier: providers[0] ?? 'pathao', consignment_ref: '' });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Parcels')} />
            <div className="flex max-w-5xl flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={t('Parcels')}
                    description={t(
                        'Where each consignment is, as the courier last said. HQ reads and listens only: nothing is booked or changed in the ERP.',
                    )}
                />

                <form
                    className="hq-card flex flex-col gap-3 p-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/parcels/track', { preserveScroll: true, onSuccess: () => form.reset('consignment_ref') });
                    }}
                >
                    <SectionHeader title={t('Track a parcel now')} />
                    <FormRow>
                        <Field label={t('Business')} htmlFor="p-business" size="lg" error={form.errors.business_slug}>
                            <NativeSelect
                                id="p-business"
                                value={form.data.business_slug}
                                onChange={(e) => form.setData('business_slug', e.target.value)}
                            >
                                {businesses.map((b) => (
                                    <option key={b.slug} value={b.slug}>
                                        {b.name}
                                    </option>
                                ))}
                            </NativeSelect>
                        </Field>
                        <Field label={t('Courier')} htmlFor="p-courier" size="md" error={form.errors.courier}>
                            <NativeSelect id="p-courier" value={form.data.courier} onChange={(e) => form.setData('courier', e.target.value)}>
                                {providers.map((p) => (
                                    <option key={p} value={p}>
                                        {p}
                                    </option>
                                ))}
                            </NativeSelect>
                        </Field>
                        <Field label={t('Consignment number')} htmlFor="p-ref" size="grow" error={form.errors.consignment_ref}>
                            <Input id="p-ref" value={form.data.consignment_ref} onChange={(e) => form.setData('consignment_ref', e.target.value)} />
                        </Field>
                    </FormRow>
                    <div>
                        <Button type="submit" disabled={form.processing || form.data.consignment_ref.trim() === ''}>
                            {t('Track')}
                        </Button>
                    </div>
                    <Notice tone="neutral">{t('If the courier is not connected yet, this says what is missing. See Connectors.')}</Notice>
                </form>

                {parcels.length === 0 ? (
                    <EmptyState
                        title={t('No parcels yet')}
                        description={t('Parcels appear when a courier sends a status update or when you track one.')}
                    />
                ) : (
                    <ul className="flex flex-col gap-3">
                        {parcels.map((p) => (
                            <li key={p.uuid} className="hq-card p-4">
                                <div className="text-muted-foreground mb-2 flex flex-wrap gap-3 text-xs">
                                    <span>{p.business}</span>
                                    <span>{p.linked ? t('Linked to an order') : t('No order linked')}</span>
                                    {p.merchant_order_ref && <span className="font-mono">{p.merchant_order_ref}</span>}
                                </div>
                                <ParcelTimeline parcel={p} />
                            </li>
                        ))}
                    </ul>
                )}
                <Pagination meta={pagination} />
            </div>
        </AppLayout>
    );
}
