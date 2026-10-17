import { EmptyState } from '@/components/empty-state';
import { PageHeader, Toolbar } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { InertNotice, Notice } from '@/components/ui/notice';
import { StatusBadge, type Tone } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, timeAgo, truncate } from '@/lib/format';
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

export function customerLabel(t: ThreadCard): string {
    if (t.customer === null) return 'Nobody yet';
    if (t.stranger) return t.customer.name ? `${t.customer.name} (not yet linked)` : 'A stranger';
    return t.customer.name ?? t.customer.phone ?? `Customer #${t.customer.id}`;
}

export function AgentNotice({ agent }: { agent: AgentStatus }) {
    return agent.active ? (
        <Notice tone="info">The support agent is active. Threads it cannot keep come here.</Notice>
    ) : (
        <InertNotice
            title="Answers from the support agent"
            reason={`The support agent is ${agent.status ?? 'not seeded'}. Every thread comes to a person; nothing is answered automatically.`}
            gate="A person activates the support agent after its shadow review. Until then it can only propose."
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
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Inbox" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Inbox"
                    description="Customer conversations from every channel the group listens on, and who is answering each one."
                    actions={
                        <div className="flex flex-wrap items-center gap-2">
                            <StatusBadge tone="warn">{counts.needs_person} need a person</StatusBadge>
                            <StatusBadge tone="neutral">{counts.open} open</StatusBadge>
                        </div>
                    }
                >
                    <Toolbar label="Filter conversations">
                        {FILTERS.map((f) => (
                            <Button
                                key={f.key}
                                size="sm"
                                variant={filter === f.key ? 'default' : 'outline'}
                                onClick={() => router.get('/inbox', { filter: f.key }, { preserveState: true, replace: true })}
                            >
                                {f.label}
                            </Button>
                        ))}
                    </Toolbar>
                </PageHeader>

                <AgentNotice agent={agent} />

                {threads.length === 0 ? (
                    <EmptyState
                        icon={MessageSquare}
                        title={filter === 'needs_person' ? 'Nobody is waiting for a person' : 'No conversations here'}
                        description={
                            filter === 'needs_person'
                                ? 'When a customer writes and the agent cannot or may not answer, the thread appears here.'
                                : 'Try another filter. Conversations arrive through the channels connected to HQ; none is connected yet.'
                        }
                    />
                ) : (
                    <div className="flex flex-col gap-3">
                        {threads.map((t) => (
                            <article key={t.id} className="hq-card p-4">
                                <div className="flex flex-wrap items-start justify-between gap-4">
                                    <div className="min-w-0 flex-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <Link href={`/inbox/${t.uuid}`} className="font-semibold hover:underline">
                                                {customerLabel(t)}
                                            </Link>
                                            <StatusBadge tone={stateTone(t.state)}>{t.state_label}</StatusBadge>
                                            <StatusBadge tone="neutral" dot={false}>
                                                {t.channel}
                                            </StatusBadge>
                                            {languageLabel(t.language) && (
                                                <StatusBadge tone="info" dot={false}>
                                                    {languageLabel(t.language)}
                                                </StatusBadge>
                                            )}
                                        </div>
                                        {t.last_message && (
                                            <p className="mt-1 text-sm">
                                                <span className="text-muted-foreground">{t.last_message.direction === 'inbound' ? 'They wrote: ' : 'We wrote: '}</span>
                                                {t.last_message.body ? truncate(t.last_message.body, 140) : '(no text)'}
                                            </p>
                                        )}
                                        <div className="text-muted-foreground mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs">
                                            {(t.business || t.brand) && (
                                                <span>
                                                    {t.business ?? ''}
                                                    {t.brand ? ` · ${t.brand}` : ''}
                                                </span>
                                            )}
                                            {t.assignee ? <span>assigned to {t.assignee}</span> : <span>unassigned</span>}
                                            {t.last_message_at && <span title={formatDateTime(t.last_message_at)}>last message {timeAgo(t.last_message_at)}</span>}
                                        </div>
                                    </div>
                                    <Button asChild size="sm" variant="outline">
                                        <Link href={`/inbox/${t.uuid}`}>Open</Link>
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
