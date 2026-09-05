import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';

type Identity = { id: number; channel: string; external_id: string; confidence: string; last_seen_at: string | null };
type Order = { id: number; order_ref: string; status: string; payment_status: string | null; gross: string; placed_at: string | null; district: string | null; store?: { name: string } };
type Customer = {
    id: number; name: string | null; primary_phone_canonical: string | null; email: string | null; orders_count: number; lifetime_value: string;
    first_seen_at: string | null; last_seen_at: string | null; consent: Record<string, unknown> | null; attributes: { aliases?: string[] } | null;
    business?: { name: string }; identities: Identity[];
};

export default function CustomerShow({ customer, orders }: { customer: Customer; orders: Order[] }) {
    const breadcrumbs: BreadcrumbItem[] = [{ title: 'Customers', href: '/customers' }, { title: customer.name ?? `#${customer.id}`, href: `/customers/${customer.id}` }];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={customer.name ?? `Customer #${customer.id}`} />
            <div className="flex flex-col gap-6 p-4">
                <div className="rounded-xl border p-4">
                    <h1 className="text-lg font-semibold">{customer.name ?? `Customer #${customer.id}`}</h1>
                    <dl className="mt-3 grid gap-2 text-sm sm:grid-cols-2 lg:grid-cols-4">
                        <div><dt className="text-muted-foreground">Phone</dt><dd className="font-mono">{customer.primary_phone_canonical ?? '-'}</dd></div>
                        <div><dt className="text-muted-foreground">Email</dt><dd>{customer.email ?? '-'}</dd></div>
                        <div><dt className="text-muted-foreground">Business</dt><dd>{customer.business?.name}</dd></div>
                        <div><dt className="text-muted-foreground">Orders / value</dt><dd>{customer.orders_count} / ৳{Number(customer.lifetime_value).toLocaleString()}</dd></div>
                        <div><dt className="text-muted-foreground">First seen</dt><dd>{customer.first_seen_at ? new Date(customer.first_seen_at).toLocaleString() : '-'}</dd></div>
                        <div><dt className="text-muted-foreground">Last seen</dt><dd>{customer.last_seen_at ? new Date(customer.last_seen_at).toLocaleString() : '-'}</dd></div>
                        <div><dt className="text-muted-foreground">Aliases</dt><dd>{customer.attributes?.aliases?.join(', ') || '-'}</dd></div>
                        <div><dt className="text-muted-foreground">Consent</dt><dd className="font-mono text-xs">{customer.consent ? JSON.stringify(customer.consent) : 'none recorded'}</dd></div>
                    </dl>
                </div>
                <div className="grid gap-4 lg:grid-cols-2">
                    <div className="rounded-xl border">
                        <div className="border-b px-4 py-3 text-sm font-medium">Identities</div>
                        <table className="w-full text-sm">
                            <tbody>
                                {customer.identities.map((i) => (
                                    <tr key={i.id} className="border-b last:border-0">
                                        <td className="px-4 py-2 font-mono text-xs">{i.channel}</td>
                                        <td className="px-4 py-2 font-mono text-xs break-all">{i.external_id}</td>
                                        <td className="text-muted-foreground px-4 py-2 text-xs">{i.confidence}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <div className="rounded-xl border">
                        <div className="border-b px-4 py-3 text-sm font-medium">Orders (read model)</div>
                        <table className="w-full text-sm">
                            <tbody>
                                {orders.map((o) => (
                                    <tr key={o.id} className="border-b last:border-0">
                                        <td className="px-4 py-2 font-mono text-xs">{o.order_ref}</td>
                                        <td className="px-4 py-2">{o.store?.name}</td>
                                        <td className="px-4 py-2">{o.status}{o.payment_status ? ` · ${o.payment_status}` : ''}</td>
                                        <td className="px-4 py-2 text-right tabular-nums">৳{Number(o.gross).toLocaleString()}</td>
                                        <td className="px-4 py-2 whitespace-nowrap">{o.placed_at ? new Date(o.placed_at).toLocaleDateString() : '-'}</td>
                                    </tr>
                                ))}
                                {orders.length === 0 && <tr><td className="text-muted-foreground px-4 py-6 text-center">No orders recorded.</td></tr>}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
