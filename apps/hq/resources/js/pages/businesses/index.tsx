import { EmptyState } from '@/components/empty-state';
import { PageHeader, TechnicalDetail } from '@/components/page-header';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { humanKey } from '@/lib/format';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import { Building2 } from 'lucide-react';

type PlatformAccount = { id: number; platform: string; account_ref: string; name: string | null; approval_status: string };
type Brand = { id: number; code: string; name: string; platform_accounts: PlatformAccount[] };
type Store = { id: number; name: string; adapter: string; base_url: string | null; product_owners: { id: number; name: string }[] };
type Business = { id: number; name: string; slug: string; erp_business_id: number | null; timezone: string; brands: Brand[]; stores: Store[] };

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Businesses', href: '/businesses' }];

export default function BusinessesIndex({ businesses }: { businesses: Business[] }) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Businesses" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Businesses"
                    description="The group as HQ sees it. A business owns brands and operates stores; a store sells the products of one or more businesses. The ERP is the master for business and brand identity, so nothing here is edited by hand."
                />
                {businesses.length === 0 ? (
                    <EmptyState icon={Building2} title="No businesses" description="Businesses are seeded from config and mastered by the ERP." />
                ) : (
                    <div className="grid gap-4 lg:grid-cols-2">
                        {businesses.map((b) => (
                            <article key={b.id} className="hq-card flex flex-col gap-4 p-4">
                                <div className="flex flex-wrap items-baseline justify-between gap-2">
                                    <h2 className="text-lg font-semibold">{b.name}</h2>
                                    <span className="text-muted-foreground text-xs">
                                        {b.timezone}
                                        {b.erp_business_id ? ` · in the ERP as business ${b.erp_business_id}` : ' · not in the ERP'}
                                    </span>
                                </div>

                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <div className="text-muted-foreground mb-1 text-xs font-medium uppercase">Brands</div>
                                        {b.brands.length === 0 && <div className="text-muted-foreground text-sm">none</div>}
                                        <ul className="flex flex-col gap-2">
                                            {b.brands.map((br) => (
                                                <li key={br.id} className="text-sm">
                                                    <div className="font-medium">{br.name}</div>
                                                    {br.platform_accounts.length > 0 && (
                                                        <ul className="mt-1 flex flex-col gap-1">
                                                            {br.platform_accounts.map((pa) => (
                                                                <li key={pa.id} className="flex flex-wrap items-center gap-1.5 text-xs">
                                                                    <span>{humanKey(pa.platform)}</span>
                                                                    <span className="text-muted-foreground">{pa.name ?? pa.account_ref}</span>
                                                                    <StatusBadge status={pa.approval_status} dot={false} />
                                                                </li>
                                                            ))}
                                                        </ul>
                                                    )}
                                                </li>
                                            ))}
                                        </ul>
                                    </div>
                                    <div>
                                        <div className="text-muted-foreground mb-1 text-xs font-medium uppercase">Stores</div>
                                        {b.stores.length === 0 && <div className="text-muted-foreground text-sm">none</div>}
                                        <ul className="flex flex-col gap-2">
                                            {b.stores.map((s) => (
                                                <li key={s.id} className="text-sm">
                                                    <div className="font-medium">
                                                        {s.base_url ? (
                                                            <a
                                                                href={s.base_url}
                                                                target="_blank"
                                                                rel="noopener noreferrer"
                                                                className="hover:underline"
                                                            >
                                                                {s.name}
                                                            </a>
                                                        ) : (
                                                            s.name
                                                        )}
                                                    </div>
                                                    <div className="text-muted-foreground text-xs">
                                                        sells for {s.product_owners.map((o) => o.name).join(', ') || 'nobody yet'} ·{' '}
                                                        {humanKey(s.adapter).toLowerCase()} connector
                                                    </div>
                                                </li>
                                            ))}
                                        </ul>
                                    </div>
                                </div>

                                <TechnicalDetail summary={`slug ${b.slug} · brand codes ${b.brands.map((br) => br.code).join(', ') || 'none'}`}>
                                    <dl className="text-muted-foreground grid grid-cols-[auto_1fr] gap-x-4 gap-y-0.5 font-mono text-xs">
                                        {b.brands.map((br) => (
                                            <div key={br.id} className="contents">
                                                <dt>{br.code}</dt>
                                                <dd>{br.platform_accounts.map((pa) => `${pa.platform}:${pa.account_ref}`).join(', ') || '-'}</dd>
                                            </div>
                                        ))}
                                        {b.stores.map((s) => (
                                            <div key={s.id} className="contents">
                                                <dt>{s.adapter}</dt>
                                                <dd>{s.base_url ?? '-'}</dd>
                                            </div>
                                        ))}
                                    </dl>
                                </TechnicalDetail>
                            </article>
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
