import { EmptyState } from '@/components/empty-state';
import { PageHeader, SectionHeader } from '@/components/page-header';
import { ParcelTimeline, type Parcel } from '@/components/parcel-timeline';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';

type Order = {
    id: number;
    ref: string;
    status: string;
    payment_status: string | null;
    gross: string;
    placed_at: string | null;
    delivered_at: string | null;
    store: string | null;
    district: string | null;
    thana: string | null;
    customer_id: number | null;
};

/** One order copy and where its parcels are. The shop or the ERP holds the truth; this is HQ's copy and the couriers' last words. */
export default function OrderShow({ order, parcels }: { order: Order; parcels: Parcel[] }) {
    const t = useT();
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Customers', href: '/customers' },
        { title: order.ref, href: `/orders/${order.id}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={order.ref} />
            <div className="flex max-w-4xl flex-col gap-6 p-4 md:p-6">
                <PageHeader title={order.ref} description={t('HQ holds a copy of this order. The shop or the ERP is the truth; check there before acting on a status.')} />

                <section className="hq-card flex flex-wrap items-center gap-x-6 gap-y-2 p-4 text-sm">
                    <StatusBadge status={order.status} />
                    {order.payment_status && <StatusBadge status={order.payment_status} dot={false} />}
                    <span>BDT {order.gross}</span>
                    <span className="text-muted-foreground">{formatDateTime(order.placed_at)}</span>
                    {order.store && <span className="text-muted-foreground">{order.store}</span>}
                    {(order.district || order.thana) && <span className="text-muted-foreground">{[order.thana, order.district].filter(Boolean).join(', ')}</span>}
                    {order.customer_id && (
                        <Link href={`/customers/${order.customer_id}`} className="underline">
                            {t('Customer')}
                        </Link>
                    )}
                </section>

                <section className="flex flex-col gap-3">
                    <SectionHeader title={t('Parcels')} />
                    {parcels.length === 0 ? (
                        <EmptyState title={t('No parcel is linked to this order')} description={t('A parcel is linked when the courier echoes this order number, or it can be tracked on the Parcels screen.')} />
                    ) : (
                        parcels.map((p) => (
                            <div key={p.uuid} className="hq-card p-4">
                                <ParcelTimeline parcel={p} />
                            </div>
                        ))
                    )}
                </section>
            </div>
        </AppLayout>
    );
}
