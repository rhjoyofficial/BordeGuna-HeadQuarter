import { EmptyState } from '@/components/empty-state';
import { PageHeader, Toolbar } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { InertNotice, Notice } from '@/components/ui/notice';
import { StatusBadge, type Tone } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, timeAgo, truncate } from '@/lib/format';
import { translate, type Translator, useT } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { MessageSquare } from 'lucide-react';

export type ThreadCard = {
    id: number;
    uuid: string;
    channel: string;
    state: string;
    state_label: string;
    status: string;
    language: string | null;
    business: string | null;
    business_slug: string | null;
    brand: string | null;
    customer: { id: number; name: string | null; phone: string | null } | null;
    stranger: boolean;
    assignee: string | null;
    last_message: { body: string | null; direction: string; at: string | null } | null;
    last_message_at: string | null;
    opened_at: string | null;
};

export type AgentStatus = { active: boolean; status: string | null };

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Inbox', href: '/inbox' }];

const FILTERS: { key: string; label: string }[] = [
    { key: 'needs_person', label: 'Needs a person' },
    { key: 'mine', label: 'Mine' },
    { key: 'open', label: 'Open' },
    { key: 'all', label: 'All' },
];

export function stateTone(state: string): Tone {
    switch (state) {
        case 'human_required':
            return 'warn';
        case 'human_active':
            return 'info';
        case 'human_resolved':
            return 'ok';
        case 'ai_active':
        case 'ai_resumed':
            return 'pending';
        case 'ai_waiting':
            return 'neutral';
        case 'closed':
        default:
            return 'neutral';
    }
}

export function languageLabel(language: string | null): string | null {
    switch (language) {
        case 'bn':
            return 'Bangla';
        case 'en':
            return 'English';
        case 'banglish':
            return 'Bangla and English';
        default:
            return null;
    }
}

const english: Translator = (text, params) => translate('en', text, params);

/** Who the thread is with. Pass the page's translator so the fallback words follow the chosen language. */
export function customerLabel(thread: ThreadCard, t: Translator = english): string {
    if (thread.customer === null) return t('Nobody yet');
    if (thread.stranger) return thread.customer.name ? t('{name} (not yet linked)', { name: thread.customer.name }) : t('A stranger');
    return thread.customer.name ?? thread.customer.phone ?? t('Customer #{id}', { id: thread.customer.id });
}

export function AgentNotice({ agent }: { agent: AgentStatus }) {
    const t = useT();
    return agent.active ? (
        <Notice tone="info">{t('The support agent is active. Threads it cannot keep come here.')}</Notice>
    ) : (
        <InertNotice
            title={t('Answers from the support agent')}
            reason={t('The support agent is {status}. Every thread comes to a person; nothing is answered automatically.', {
                status: agent.status ? t(agent.status) : t('not seeded'),
            })}
            gate={t('A person activates the support agent after its shadow review. Until then it can only propose.')}
        />
    );
}

export default function InboxIndex({
    threads,
    filter,
    counts,
    agent,
}: {
    threads: ThreadCard[];
    filter: string;
    counts: { needs_person: number; open: number };
    agent: AgentStatus;
}) {
    const t = useT();
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Inbox')} />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={t('Inbox')}
                    description={t('Customer conversations from every channel the group listens on, and who is answering each one.')}
                    actions={
                        <div className="flex flex-wrap items-center gap-2">
                            <StatusBadge tone="warn">{t('{count} need a person', { count: counts.needs_person })}</StatusBadge>
                            <StatusBadge tone="neutral">{t('{count} open', { count: counts.open })}</StatusBadge>
                        </div>
                    }
                >
                    <Toolbar label={t('Filter conversations')}>
                        {FILTERS.map((f) => (
                            <Button
                                key={f.key}
                                size="sm"
                                variant={filter === f.key ? 'default' : 'outline'}
                                onClick={() => router.get('/inbox', { filter: f.key }, { preserveState: true, replace: true })}
                            >
                                {t(f.label)}
                            </Button>
                        ))}
                    </Toolbar>
                </PageHeader>

                <AgentNotice agent={agent} />

                {threads.length === 0 ? (
                    <EmptyState
                        icon={MessageSquare}
                        title={filter === 'needs_person' ? t('Nobody is waiting for a person') : t('No conversations here')}
                        description={
                            filter === 'needs_person'
                                ? t('When a customer writes and the agent cannot or may not answer, the thread appears here.')
                                : t('Try another filter. Conversations arrive through the channels connected to HQ; none is connected yet.')
                        }
                    />
                ) : (
                    <div className="flex flex-col gap-3">
                        {threads.map((thread) => (
                            <article key={thread.id} className="hq-card p-4">
                                <div className="flex flex-wrap items-start justify-between gap-4">
                                    <div className="min-w-0 flex-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <Link href={`/inbox/${thread.uuid}`} className="font-semibold hover:underline">
                                                {customerLabel(thread, t)}
                                            </Link>
                                            <StatusBadge tone={stateTone(thread.state)}>{thread.state_label}</StatusBadge>
                                            <StatusBadge tone="neutral" dot={false}>
                                                {thread.channel}
                                            </StatusBadge>
                                            {languageLabel(thread.language) && (
                                                <StatusBadge tone="info" dot={false}>
                                                    {languageLabel(thread.language)}
                                                </StatusBadge>
                                            )}
                                        </div>
                                        {thread.last_message && (
                                            <p className="mt-1 text-sm">
                                                <span className="text-muted-foreground">
                                                    {thread.last_message.direction === 'inbound' ? t('They wrote:') : t('We wrote:')}{' '}
                                                </span>
                                                {thread.last_message.body ? truncate(thread.last_message.body, 140) : t('(no text)')}
                                            </p>
                                        )}
                                        <div className="text-muted-foreground mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs">
                                            {(thread.business || thread.brand) && (
                                                <span>
                                                    {thread.business ?? ''}
                                                    {thread.brand ? ` · ${thread.brand}` : ''}
                                                </span>
                                            )}
                                            {thread.assignee ? (
                                                <span>{t('assigned to {name}', { name: thread.assignee })}</span>
                                            ) : (
                                                <span>{t('unassigned')}</span>
                                            )}
                                            {thread.last_message_at && (
                                                <span title={formatDateTime(thread.last_message_at)}>
                                                    {t('last message {when}', { when: timeAgo(thread.last_message_at) })}
                                                </span>
                                            )}
                                        </div>
                                    </div>
                                    <Button asChild size="sm" variant="outline">
                                        <Link href={`/inbox/${thread.uuid}`}>{t('Open')}</Link>
                                    </Button>
                                </div>
                            </article>
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
