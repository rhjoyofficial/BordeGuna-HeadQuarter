import { EmptyState } from '@/components/empty-state';
import { JsonBlock, PageHeader, SectionHeader, TechnicalDetail } from '@/components/page-header';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, humanKey, timeAgo } from '@/lib/format';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import { type ReactNode } from 'react';

type Identity = { id: number; channel: string; external_id: string; confidence: string; last_seen_at: string | null };
type Order = {
    id: number;
    order_ref: string;
    status: string;
    payment_status: string | null;
    gross: string;
    placed_at: string | null;
    district: string | null;
    store?: { name: string };
};
type Customer = {
    id: number;
    name: string | null;
    primary_phone_canonical: string | null;
    email: string | null;
    orders_count: number;
    lifetime_value: string;
    first_seen_at: string | null;
    last_seen_at: string | null;
    consent: Record<string, unknown> | null;
    attributes: { aliases?: string[] } | null;
    business?: { name: string };
    identities: Identity[];
};

const taka = (v: string | number) => `৳${Number(v).toLocaleString('en-US')}`;

function Fact({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="min-w-0">
            <dt className="text-muted-foreground text-xs">{label}</dt>
            <dd className="text-sm break-words">{children}</dd>
        </div>
    );
}

export default function CustomerShow({ customer, orders }: { customer: Customer; orders: Order[] }) {
    const name = customer.name ?? `Customer #${customer.id}`;
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Customers', href: '/customers' },
        { title: name, href: `/customers/${customer.id}` },
    ];
    const consentEntries = Object.entries(customer.consent ?? {});

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={name} />
            <div className="flex max-w-5xl flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={name}
                    description={
                        <>
                            {customer.business?.name ? `${customer.business.name} customer` : 'Customer'}
                            {customer.first_seen_at ? ` since ${formatDateTime(customer.first_seen_at).split(',')[0]}` : ''}
                            {customer.last_seen_at ? `, last seen ${timeAgo(customer.last_seen_at)}` : ''}.
                        </>
                    }
                />

                <section className="bg-card rounded-xl border p-4">
                    <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <Fact label="Phone">{customer.primary_phone_canonical ?? <span className="text-muted-foreground">none</span>}</Fact>
                        <Fact label="Email">{customer.email ?? <span className="text-muted-foreground">none</span>}</Fact>
                        <Fact label="Orders">
                            {customer.orders_count} {customer.orders_count === 1 ? 'order' : 'orders'}
                        </Fact>
                        <Fact label="Lifetime value">{taka(customer.lifetime_value)}</Fact>
                        {customer.attributes?.aliases && customer.attributes.aliases.length > 0 && (
                            <Fact label="Also known as">{customer.attributes.aliases.join(', ')}</Fact>
                        )}
                        <Fact label="Consent">
                            {consentEntries.length === 0 ? (
                                <span className="text-muted-foreground">none recorded</span>
                            ) : (
                                <div className="flex flex-wrap gap-1">
                                    {consentEntries.map(([k, v]) => (
                                        <StatusBadge key={k} tone={v === true || v === 'granted' ? 'ok' : 'neutral'} dot={false}>
                                            {humanKey(k)}: {typeof v === 'object' ? JSON.stringify(v) : String(v)}
                                        </StatusBadge>
                                    ))}
                                </div>
                            )}
                        </Fact>
                    </dl>
                </section>

                <div className="grid gap-4 lg:grid-cols-5">
                    <section className="flex flex-col gap-2 lg:col-span-3">
                        <SectionHeader title="Orders" aside={`${orders.length} shown`} />
                        {orders.length === 0 ? (
                            <EmptyState title="No orders recorded" description="Orders arrive from the shops as events." />
                        ) : (
                            <div className="bg-card overflow-x-auto rounded-xl border">
                                <table className="w-full text-sm">
                                    <thead className="bg-muted/50 text-muted-foreground text-left text-xs">
                                        <tr>
                                            <th className="px-3 py-2 font-medium">Order</th>
                                            <th className="px-3 py-2 font-medium">Store</th>
                                            <th className="px-3 py-2 font-medium">Status</th>
                                            <th className="px-3 py-2 text-right font-medium">Amount</th>
                                            <th className="px-3 py-2 font-medium">Placed</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {orders.map((o) => (
                                            <tr key={o.id} className="border-t">
                                                <td className="px-3 py-2 font-mono text-xs">{o.order_ref}</td>
                                                <td className="px-3 py-2 text-xs">
                                                    {o.store?.name}
                                                    {o.district ? <div className="text-muted-foreground">{o.district}</div> : null}
                                                </td>
                                                <td className="px-3 py-2">
                                                    <div className="flex flex-wrap gap-1">
                                                        <StatusBadge status={o.status} />
                                                        {o.payment_status && (
                                                            <StatusBadge status={o.payment_status} dot={false}>
                                                                {humanKey(o.payment_status).toLowerCase()}
                                                            </StatusBadge>
                                                        )}
                                                    </div>
                                                </td>
                                                <td className="px-3 py-2 text-right tabular-nums">{taka(o.gross)}</td>
                                                <td
                                                    className="text-muted-foreground px-3 py-2 text-xs whitespace-nowrap"
                                                    title={formatDateTime(o.placed_at)}
                                                >
                                                    {o.placed_at ? timeAgo(o.placed_at) : '-'}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </section>

                    <section className="flex flex-col gap-2 lg:col-span-2">
                        <SectionHeader
                            title="Known as"
                            aside={`${customer.identities.length} ${customer.identities.length === 1 ? 'identity' : 'identities'}`}
                        />
                        <div className="bg-card overflow-x-auto rounded-xl border">
                            <table className="w-full text-sm">
                                <tbody>
                                    {customer.identities.length === 0 && (
                                        <tr>
                                            <td className="text-muted-foreground p-3 text-sm">No identities linked.</td>
                                        </tr>
                                    )}
                                    {customer.identities.map((i) => (
                                        <tr key={i.id} className="border-t first:border-0">
                                            <td className="px-3 py-2 text-xs whitespace-nowrap">{humanKey(i.channel)}</td>
                                            <td className="px-3 py-2 font-mono text-xs break-all">{i.external_id}</td>
                                            <td className="px-3 py-2">
                                                <StatusBadge
                                                    tone={i.confidence === 'high' ? 'ok' : i.confidence === 'medium' ? 'pending' : 'neutral'}
                                                    dot={false}
                                                >
                                                    {i.confidence}
                                                </StatusBadge>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>

                <TechnicalDetail summary="Raw record">
                    <JsonBlock
                        value={{
                            id: customer.id,
                            consent: customer.consent,
                            attributes: customer.attributes,
                            first_seen_at: customer.first_seen_at,
                            last_seen_at: customer.last_seen_at,
                        }}
                    />
                </TechnicalDetail>
            </div>
        </AppLayout>
    );
}
