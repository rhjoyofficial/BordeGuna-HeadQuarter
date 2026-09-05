import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

type Customer = { id: number; name: string | null; primary_phone_canonical: string | null; email: string | null; orders_count: number; lifetime_value: string; last_seen_at: string | null; business?: { name: string } };
type Candidate = { id: number; confidence: string; reason: string; customer: Customer; candidate: Customer };
type Paginated<T> = { data: T[]; links: { url: string | null; label: string; active: boolean }[]; total: number };

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Customers', href: '/customers' }];

export default function CustomersIndex({ customers, candidates, filters }: { customers: Paginated<Customer>; candidates: Candidate[]; filters: { q: string } }) {
    const { auth } = usePage<{ auth: { permissions: string[] } }>().props;
    const canManage = auth.permissions.includes('hq.customers.manage');
    const [q, setQ] = useState(filters.q ?? '');

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Customers" />
            <div className="flex flex-col gap-6 p-4">
                <div className="text-muted-foreground text-sm">
                    Unified identity (ADR-007, docs/14): one customer per person per business, matched on the canonical phone first. Read model, rebuildable with{' '}
                    <span className="font-mono">hq:customers:rebuild</span>. Transactional truth stays in the shops.
                </div>

                {candidates.length > 0 && (
                    <div className="rounded-xl border p-4">
                        <div className="text-sm font-medium">Merge candidates awaiting a human ({candidates.length})</div>
                        <table className="mt-2 w-full text-sm">
                            <tbody>
                                {candidates.map((c) => (
                                    <tr key={c.id} className="border-t">
                                        <td className="px-2 py-2">
                                            #{c.customer.id} {c.customer.name} <span className="font-mono text-xs">{c.customer.primary_phone_canonical ?? c.customer.email}</span>
                                        </td>
                                        <td className="px-2 py-2">
                                            #{c.candidate.id} {c.candidate.name} <span className="font-mono text-xs">{c.candidate.primary_phone_canonical ?? c.candidate.email}</span>
                                        </td>
                                        <td className="text-muted-foreground px-2 py-2 text-xs">
                                            {c.reason} ({c.confidence})
                                        </td>
                                        <td className="px-2 py-2 text-right">
                                            {canManage && (
                                                <div className="flex justify-end gap-2">
                                                    <Button size="sm" variant="outline" onClick={() => router.post('/customers/merge', { kept_id: c.candidate.id, absorbed_id: c.customer.id, reason: c.reason }, { preserveScroll: true })}>
                                                        Merge into #{c.candidate.id}
                                                    </Button>
                                                    <Button size="sm" variant="ghost" onClick={() => router.post(`/customers/candidates/${c.id}/dismiss`, {}, { preserveScroll: true })}>
                                                        Not the same
                                                    </Button>
                                                </div>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        router.get('/customers', { q }, { preserveState: true });
                    }}
                    className="flex gap-2"
                >
                    <Input placeholder="Search name, email or phone" value={q} onChange={(e) => setQ(e.target.value)} className="max-w-sm" />
                    <Button type="submit" variant="outline">
                        Search
                    </Button>
                    <span className="text-muted-foreground ml-auto self-center text-sm">{customers.total} customers</span>
                </form>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead className="text-muted-foreground text-left text-xs uppercase">
                            <tr>
                                <th className="px-4 py-2">Customer</th>
                                <th className="px-4 py-2">Phone</th>
                                <th className="px-4 py-2">Email</th>
                                <th className="px-4 py-2">Business</th>
                                <th className="px-4 py-2 text-right">Orders</th>
                                <th className="px-4 py-2 text-right">Lifetime value</th>
                                <th className="px-4 py-2">Last seen</th>
                            </tr>
                        </thead>
                        <tbody>
                            {customers.data.map((c) => (
                                <tr key={c.id} className="border-t">
                                    <td className="px-4 py-2">
                                        <Link href={`/customers/${c.id}`} className="underline">
                                            {c.name ?? `#${c.id}`}
                                        </Link>
                                    </td>
                                    <td className="px-4 py-2 font-mono text-xs">{c.primary_phone_canonical ?? '-'}</td>
                                    <td className="px-4 py-2 text-xs">{c.email ?? '-'}</td>
                                    <td className="px-4 py-2">{c.business?.name}</td>
                                    <td className="px-4 py-2 text-right tabular-nums">{c.orders_count}</td>
                                    <td className="px-4 py-2 text-right tabular-nums">৳{Number(c.lifetime_value).toLocaleString()}</td>
                                    <td className="px-4 py-2 whitespace-nowrap">{c.last_seen_at ? new Date(c.last_seen_at).toLocaleDateString() : '-'}</td>
                                </tr>
                            ))}
                            {customers.data.length === 0 && (
                                <tr>
                                    <td colSpan={7} className="text-muted-foreground px-4 py-6 text-center">
                                        No customers yet. They appear as shop webhooks arrive (Phase 1).
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
                <div className="flex flex-wrap gap-1 text-sm">
                    {customers.links.map((l, i) =>
                        l.url ? (
                            <Link key={i} href={l.url} className={`rounded border px-2 py-1 ${l.active ? 'bg-accent' : ''}`} dangerouslySetInnerHTML={{ __html: l.label }} />
                        ) : (
                            <span key={i} className="text-muted-foreground px-2 py-1" dangerouslySetInnerHTML={{ __html: l.label }} />
                        ),
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
