import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Notice } from '@/components/ui/notice';
import { StatusBadge, type Tone } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

/**
 * Batch review of customer messages (docs/143 B). Every message is shown in full with its destination business; ticking
 * a message and pressing the button sends its id and the hash of what was shown. Each message is then decided on its own
 * (its own consent and scope check, its own audit row); one that fails is skipped and the rest go on.
 */

type Message = {
    id: number;
    tool_key: string;
    business: string | null;
    agent_key: string | null;
    payload: Record<string, unknown> | null;
    payload_hash: string;
    created_at: string | null;
    decidable: boolean;
    href: string;
};
type Result = { id: number; result: string; reason: string | null };

const RESULT_TONES: Record<string, Tone> = {
    executed: 'ok',
    not_found: 'neutral',
    payload_changed: 'warn',
    not_pending: 'neutral',
    not_eligible: 'warn',
    refused: 'danger',
};
const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Approvals', href: '/approvals' },
    { title: 'Batch review', href: '/approvals/batch' },
];

export default function ApprovalBatch({ enabled, messages, max }: { enabled: boolean; messages: Message[]; max: number }) {
    const t = useT();
    const { flash, errors } = usePage<{
        flash?: { status?: string; batch?: { executed: number; refused: number; results: Result[] } };
        errors: Record<string, string>;
    }>().props;
    const [chosen, setChosen] = useState<Record<number, boolean>>({});
    const picked = messages.filter((m) => chosen[m.id] && m.decidable);
    const label = (r: string) =>
        ({
            executed: t('Sent'),
            not_found: t('Not found'),
            payload_changed: t('Changed since you read it'),
            not_pending: t('Already decided'),
            not_eligible: t('Not a customer message'),
            refused: t('Refused'),
        })[r] ?? r;

    const submit = () =>
        router.post(
            '/approvals/batch',
            { items: picked.map((m) => ({ id: m.id, payload_hash: m.payload_hash })) },
            { preserveScroll: true, onSuccess: () => setChosen({}) },
        );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Batch review')} />
            <div className="flex max-w-4xl flex-col gap-5 p-4 pb-24 md:p-6">
                <PageHeader
                    title={t('Batch review')}
                    description={t(
                        'Read each message, tick the ones you approve, then decide them together. Each message is still checked and recorded on its own.',
                    )}
                />

                {!enabled && (
                    <EmptyState
                        variant="inert"
                        title={t('Batch review is switched off')}
                        reason={t('Each message is approved on its own page until this is switched on.')}
                        gate={t('a person with group-wide access switches the console.batch_approval flag on')}
                    />
                )}
                {errors.items && (
                    <Notice tone="danger" alert>
                        {errors.items}
                    </Notice>
                )}
                {flash?.batch && (
                    <Notice tone={flash.batch.refused === 0 ? 'ok' : 'warn'}>
                        {t('{sent} sent, {skipped} skipped.', { sent: flash.batch.executed, skipped: flash.batch.refused })}
                        <ul className="mt-2 flex flex-col gap-1">
                            {flash.batch.results.map((r) => (
                                <li key={r.id} className="flex flex-wrap items-center gap-2 text-sm">
                                    <span>#{r.id}</span>
                                    <StatusBadge tone={RESULT_TONES[r.result] ?? 'neutral'}>{label(r.result)}</StatusBadge>
                                    {r.reason && <span className="text-muted-foreground text-xs">{r.reason}</span>}
                                </li>
                            ))}
                        </ul>
                    </Notice>
                )}
                {enabled && messages.length === 0 && (
                    <EmptyState title={t('No customer messages are waiting')} description={t('Messages that need your approval appear here.')} />
                )}

                <ul className="flex flex-col gap-2">
                    {messages.map((m) => (
                        <li key={m.id} className="hq-glass flex flex-col gap-2 rounded-2xl p-4">
                            <label className="flex items-start gap-3">
                                <input
                                    type="checkbox"
                                    className="mt-1 size-4"
                                    disabled={!m.decidable}
                                    checked={Boolean(chosen[m.id])}
                                    onChange={(e) => setChosen((c) => ({ ...c, [m.id]: e.target.checked }))}
                                />
                                <span className="flex min-w-0 flex-1 flex-col gap-1">
                                    <span className="text-muted-foreground text-xs">
                                        {[m.business, m.agent_key, formatDateTime(m.created_at)].filter(Boolean).join(', ')}
                                    </span>
                                    <span className="text-sm whitespace-pre-wrap">
                                        {typeof m.payload?.text === 'string' ? m.payload.text : JSON.stringify(m.payload)}
                                    </span>
                                </span>
                            </label>
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
                </ul>

                {enabled && messages.length > 0 && (
                    <div className="hq-glass sticky bottom-3 flex flex-wrap items-center justify-between gap-3 rounded-2xl p-3">
                        <span className="text-sm">{t('{count} chosen, at most {max}', { count: picked.length, max })}</span>
                        <Button size="sm" onClick={submit} disabled={picked.length === 0 || picked.length > max}>
                            {t('Approve the chosen messages')}
                        </Button>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
