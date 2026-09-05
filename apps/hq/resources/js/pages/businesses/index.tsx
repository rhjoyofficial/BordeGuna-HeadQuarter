import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';

type PlatformAccount = { id: number; platform: string; account_ref: string; name: string | null; approval_status: string };
type Brand = { id: number; code: string; name: string; platform_accounts: PlatformAccount[] };
type Store = { id: number; name: string; adapter: string; base_url: string | null; product_owners: { id: number; name: string }[] };
type Business = { id: number; name: string; slug: string; erp_business_id: number | null; timezone: string; brands: Brand[]; stores: Store[] };

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Businesses', href: '/businesses' }];

export default function BusinessesIndex({ businesses }: { businesses: Business[] }) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Businesses" />
            <div className="flex flex-col gap-4 p-4">
                <div className="text-muted-foreground text-sm">
                    Domain model (ADR-010): Business owns Brands and operates Stores; a Store sells products of one or more owner Businesses. Seeded
                    from config/hq-seed.php; the ERP stays the master for business and brand identity.
                </div>
                {businesses.map((b) => (
                    <div key={b.id} className="rounded-xl border p-4">
                        <div className="flex flex-wrap items-baseline gap-2">
                            <h2 className="text-base font-semibold">{b.name}</h2>
                            <span className="text-muted-foreground text-xs">
                                slug {b.slug} · ERP id {b.erp_business_id ?? 'none'} · {b.timezone}
                            </span>
                        </div>
                        <div className="mt-3 grid gap-4 md:grid-cols-2">
                            <div>
                                <div className="text-muted-foreground text-xs uppercase">Brands</div>
                                {b.brands.length === 0 && <div className="text-muted-foreground text-sm">none</div>}
                                {b.brands.map((br) => (
                                    <div key={br.id} className="mt-1 text-sm">
                                        <span className="font-mono text-xs">{br.code}</span> {br.name}
                                        <ul className="text-muted-foreground ml-4 list-disc text-xs">
                                            {br.platform_accounts.map((pa) => (
                                                <li key={pa.id}>
                                                    {pa.platform} {pa.account_ref}
                                                    {pa.name ? ` (${pa.name})` : ''} · {pa.approval_status}
                                                </li>
                                            ))}
                                        </ul>
                                    </div>
                                ))}
                            </div>
                            <div>
                                <div className="text-muted-foreground text-xs uppercase">Stores</div>
                                {b.stores.length === 0 && <div className="text-muted-foreground text-sm">none</div>}
                                {b.stores.map((s) => (
                                    <div key={s.id} className="mt-1 text-sm">
                                        {s.name} <span className="font-mono text-xs">[{s.adapter}]</span>
                                        <div className="text-muted-foreground text-xs">
                                            sells products of: {s.product_owners.map((o) => o.name).join(', ') || '-'}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </div>
                ))}
            </div>
        </AppLayout>
    );
}
