import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/page-header';
import { type ListMeta, Pagination } from '@/components/pagination';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';

/**
 * The batch overview of waiting customer messages (docs/143 B as ruled on 31 Oct 2026, ruling 2: sequence only).
 * Read-only: every message is shown in full with its destination business and opens on its own approval page, where it
 * is approved on its own; that page then offers the next message. Nothing here approves, and nothing is chosen together.
 */

type Message = {
    id: number;
    tool_key: string;
    business: string | null;
    agent_key: string | null;
    payload: Record<string, unknown> | null;
    created_at: string | null;
    decidable: boolean;
    href: string;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Approvals', href: '/approvals' },
    { title: 'Batch review', href: '/approvals/batch' },
];

export default function ApprovalBatch({ enabled, messages, pagination }: { enabled: boolean; messages: Message[]; pagination: ListMeta | null }) {
    const t = useT();
    const first = messages.find((m) => m.decidable) ?? messages[0];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Batch review')} />
            <div className="flex max-w-4xl flex-col gap-5 p-4 md:p-6">
                <PageHeader
                    title={t('Batch review')}
                    description={t(
                        'Read the waiting customer messages here. Each one is opened and approved on its own page, then you move to the next. Nothing is approved together.',
                    )}
                    actions={
                        enabled && first ? (
                            <Link
                                href={first.href}
                                className="bg-primary text-primary-foreground inline-flex h-9 items-center gap-1.5 rounded-full px-4 text-sm font-medium hover:opacity-90"
                            >
                                {t('Start with the first message')}
                                <ArrowRight className="size-4" aria-hidden />
                            </Link>
                        ) : undefined
                    }
                />

                {!enabled && (
                    <EmptyState
                        variant="inert"
                        title={t('Batch review is switched off')}
                        reason={t('Each message is approved on its own page until this is switched on.')}
                        gate={t('a person with group-wide access switches the console.batch_approval flag on')}
                    />
                )}
                {enabled && messages.length === 0 && (
                    <EmptyState title={t('No customer messages are waiting')} description={t('Messages that need your approval appear here.')} />
                )}

                <ol className="flex flex-col gap-2">
                    {messages.map((m, i) => (
                        <li key={m.id} className="hq-glass flex flex-col gap-2 rounded-2xl p-4">
                            <span className="text-muted-foreground text-xs">
                                {[`${(pagination?.from ?? 1) + i}.`, m.business, m.agent_key, formatDateTime(m.created_at)].filter(Boolean).join(' ')}
                            </span>
                            <span className="text-sm whitespace-pre-wrap">
                                {typeof m.payload?.text === 'string' ? m.payload.text : JSON.stringify(m.payload)}
                            </span>
                            <div className="flex flex-wrap items-center gap-3 text-xs">
                                <Link href={m.href} className="text-hq-violet hover:underline">
                                    {t('Open the approval')}
                                </Link>
                                {!m.decidable && (
                                    <span className="text-muted-foreground">{t('You can read this one; someone else decides it.')}</span>
                                )}
                            </div>
                        </li>
                    ))}
                </ol>
                {pagination && <Pagination meta={pagination} />}
            </div>
        </AppLayout>
    );
}
