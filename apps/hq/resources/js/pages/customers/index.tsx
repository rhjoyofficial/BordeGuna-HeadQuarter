import { ConfirmDialog } from '@/components/confirm-dialog';
import { EmptyState } from '@/components/empty-state';
import { PageHeader, SectionHeader } from '@/components/page-header';
import { Pagination, type PageLink } from '@/components/pagination';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, timeAgo } from '@/lib/format';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Users } from 'lucide-react';
import { useState } from 'react';

type Customer = {
    id: number;
    name: string | null;
    primary_phone_canonical: string | null;
    email: string | null;
    orders_count: number;
    lifetime_value: string;
    last_seen_at: string | null;
    business?: { name: string };
    // docs/117: the server withheld what an earlier cross-business merge may have brought in.
    quarantine_held?: boolean;
};
type Candidate = { id: number; confidence: string; reason: string; customer: Customer; candidate: Customer };
type Paginated<T> = { data: T[]; links: PageLink[]; total: number };

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Customers', href: '/customers' }];

const taka = (v: string | number) => `৳${Number(v).toLocaleString('en-US')}`;

function Person({ c }: { c: Customer }) {
    return (
        <div className="min-w-0">
            <Link href={`/customers/${c.id}`} className="font-medium hover:underline">
                {c.name ?? `Customer #${c.id}`}
            </Link>
            <div className="text-muted-foreground text-xs">
                {c.primary_phone_canonical ?? c.email ?? 'no contact'}
                {c.business?.name ? ` · ${c.business.name}` : ''} · {c.orders_count} {c.orders_count === 1 ? 'order' : 'orders'},{' '}
                {taka(c.lifetime_value)}
            </div>
        </div>
    );
}

export default function CustomersIndex({
    customers,
    candidates,
    filters,
}: {
    customers: Paginated<Customer>;
    candidates: Candidate[];
    filters: { q: string; business?: number | null };
}) {
    const { auth } = usePage<{ auth: { permissions: string[] } }>().props;
    const canManage = auth.permissions.includes('hq.customers.manage');
    const canReview = auth.permissions.includes('hq.customers.quarantine.review');
    const [q, setQ] = useState(filters.q ?? '');
    const [merging, setMerging] = useState<Candidate | null>(null);
    const [busy, setBusy] = useState(false);

    const post = (url: string, body: Record<string, string | number>) => {
        setBusy(true);
        router.post(url, body, {
            preserveScroll: true,
            onFinish: () => {
                setBusy(false);
                setMerging(null);
            },
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Customers" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Customers"
                    description="One customer per person per business, matched on the phone number first, across every shop. A read model: the shops keep the transactional truth, and this can be rebuilt from them."
                    actions={
                        <span className="flex flex-wrap items-center gap-3">
                            {canReview && (
                                <Button asChild variant="outline" size="sm">
                                    <Link href="/customers/quarantine">Cross-business review</Link>
                                </Button>
                            )}
                            <span className="text-muted-foreground text-sm">
                                {customers.total.toLocaleString('en-US')} {customers.total === 1 ? 'customer' : 'customers'}
                            </span>
                        </span>
                    }
                >
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            router.get('/customers', q ? { q } : {}, { preserveState: true, replace: true });
                        }}
                        className="flex flex-wrap items-center gap-2"
                    >
                        <Input
                            type="search"
                            aria-label="Search customers"
                            placeholder="Search name, email or phone"
                            value={q}
                            onChange={(e) => setQ(e.target.value)}
                            className="w-full max-w-72 sm:w-72"
                        />
                        <Button type="submit" variant="outline">
                            Search
                        </Button>
                        {filters.q && (
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() => router.get('/customers', {}, { preserveState: true, replace: true })}
                            >
                                Clear
                            </Button>
                        )}
                    </form>
                </PageHeader>

                {candidates.length > 0 && (
                    <section className="flex flex-col gap-2">
                        <SectionHeader title="Are these the same person?" aside={`${candidates.length} to decide`} />
                        <div className="hq-table-box">
                            <table className="w-full text-sm">
                                <tbody>
                                    {candidates.map((c) => (
                                        <tr key={c.id} className="border-t first:border-0">
                                            <td className="px-3 py-3">
                                                <Person c={c.customer} />
                                            </td>
                                            <td className="text-muted-foreground px-1 py-3 text-center text-xs">and</td>
                                            <td className="px-3 py-3">
                                                <Person c={c.candidate} />
                                            </td>
                                            <td className="px-3 py-3 text-xs">
                                                <div>{c.reason}</div>
                                                <StatusBadge
                                                    tone={c.confidence === 'high' ? 'ok' : c.confidence === 'medium' ? 'pending' : 'neutral'}
                                                    dot={false}
                                                >
                                                    {c.confidence} confidence
                                                </StatusBadge>
                                            </td>
                                            <td className="px-3 py-3 text-right">
                                                {canManage && (
                                                    <div className="flex flex-wrap justify-end gap-2">
                                                        <Button size="sm" disabled={busy} onClick={() => setMerging(c)}>
                                                            Same person
                                                        </Button>
                                                        <Button
                                                            size="sm"
                                                            variant="ghost"
                                                            disabled={busy}
                                                            onClick={() => post(`/customers/candidates/${c.id}/dismiss`, {})}
                                                        >
                                                            Different people
                                                        </Button>
                                                    </div>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                )}

                {customers.data.length === 0 ? (
                    <EmptyState
                        icon={Users}
                        title={filters.q ? 'No customer matches' : 'No customers yet'}
                        description={filters.q ? 'Try a shorter name or the phone number.' : 'Customers appear as orders arrive from the shops.'}
                    />
                ) : (
                    <div className="hq-table-box">
                        <table className="w-full text-sm">
                            <thead className="text-left text-xs">
                                <tr>
                                    <th className="px-3 py-2 font-medium">Customer</th>
                                    <th className="px-3 py-2 font-medium">Phone</th>
                                    <th className="px-3 py-2 font-medium">Email</th>
                                    <th className="px-3 py-2 font-medium">Business</th>
                                    <th className="px-3 py-2 text-right font-medium">Orders</th>
                                    <th className="px-3 py-2 text-right font-medium">Lifetime value</th>
                                    <th className="px-3 py-2 font-medium">Last seen</th>
                                </tr>
                            </thead>
                            <tbody>
                                {customers.data.map((c) => (
                                    <tr key={c.id} className="border-t">
                                        <td className="px-3 py-2">
                                            <Link href={`/customers/${c.id}`} className="font-medium hover:underline">
                                                {c.name ?? `Customer #${c.id}`}
                                            </Link>
                                            {c.quarantine_held && (
                                                <StatusBadge tone="warn" dot={false} className="ml-2">
                                                    held for review
                                                </StatusBadge>
                                            )}
                                        </td>
                                        <td className="px-3 py-2 text-xs tabular-nums">
                                            {c.primary_phone_canonical ?? <span className="text-muted-foreground">-</span>}
                                        </td>
                                        <td className="px-3 py-2 text-xs">{c.email ?? <span className="text-muted-foreground">-</span>}</td>
                                        <td className="px-3 py-2 text-xs">{c.business?.name}</td>
                                        <td className="px-3 py-2 text-right tabular-nums">{c.orders_count}</td>
                                        <td className="px-3 py-2 text-right tabular-nums">{taka(c.lifetime_value)}</td>
                                        <td
                                            className="text-muted-foreground px-3 py-2 text-xs whitespace-nowrap"
                                            title={formatDateTime(c.last_seen_at)}
                                        >
                                            {c.last_seen_at ? timeAgo(c.last_seen_at) : '-'}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                <Pagination links={customers.links} total={customers.total} noun="customers" />
            </div>

            <ConfirmDialog
                open={merging !== null}
                onOpenChange={(o) => !o && setMerging(null)}
                title="Merge these two customers?"
                description={
                    merging
                        ? `${merging.customer.name ?? `Customer #${merging.customer.id}`} is folded into ${merging.candidate.name ?? `Customer #${merging.candidate.id}`}. Orders, identities and value combine; the merge is recorded and the shops are not touched.`
                        : undefined
                }
                confirmLabel="Merge"
                busy={busy}
                onConfirm={() =>
                    merging && post('/customers/merge', { kept_id: merging.candidate.id, absorbed_id: merging.customer.id, reason: merging.reason })
                }
            />
        </AppLayout>
    );
}
