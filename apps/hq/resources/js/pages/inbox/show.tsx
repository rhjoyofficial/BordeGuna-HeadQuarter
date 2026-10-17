import { ConfirmDialog } from '@/components/confirm-dialog';
import { NativeSelect } from '@/components/form/field';
import { PageHeader, SectionHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { InertNotice, Notice } from '@/components/ui/notice';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, timeAgo } from '@/lib/format';
import { formatNumber, useT } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { AgentNotice, type AgentStatus, customerLabel, languageLabel, stateTone, type ThreadCard } from './index';

type Thread = ThreadCard & {
    ai_disabled: boolean;
    platform_account: string | null;
    customer_id: number | null;
    allowed_next: string[];
};

type Message = {
    uuid: string;
    direction: 'inbound' | 'outbound' | 'internal';
    author_type: string;
    author: string;
    provenance: string;
    trusted: boolean;
    body: string | null;
    status: string;
    language: string | null;
    attachments: { type: string; ref: string }[];
    at: string | null;
};

type Proposal = { run_id: number; text: string; why: string | null; at: string | null };

/** docs/95 stream W2a: why this thread needs a person, from the hand-over the state machine audited. Null unless it is waiting on one. */
type Handover = {
    reason: string | null;
    label: string | null;
    detail: string | null;
    from: string | null;
    at: string | null;
    by: string | null;
};

/** docs/95 stream W2a: one of the known customer's last orders, as HQ's fact table holds it. */
type RecentOrder = {
    ref: string;
    store: string | null;
    status: string;
    payment_status: string | null;
    placed_at: string | null;
    gross: string;
};

/** A fact a person remembered about the customer (docs/78 B.1): shown with its kind, source and date, never as truth. */
type Remembered = {
    id: number;
    kind: 'structured' | 'episodic';
    key: string | null;
    text: string;
    provenance: string;
    source: string | null;
    at: string | null;
    closable: boolean;
};

/** P3-G3: a proposal the agent made beside what the n8n bot (or a person) actually sent, for a person to judge. */
type Comparison = {
    id: number;
    run_id: number | null;
    proposed: string | null;
    sent: string | null;
    sent_at: string | null;
    identical: boolean;
    verdict: string | null;
    compared_at: string | null;
};

const MEMORY_KEY_LABELS: Record<string, string> = {
    delivery_notes: 'Delivery notes',
    preferred_language: 'Preferred language',
    preferred_contact_time: 'Preferred contact time',
    about: 'About this person',
};

type Outcome = { status: string; error: string | null } | null;

type Dialog = { kind: 'resolve' | 'close' | 'discard'; run?: number } | null;

const SENDING_NOTICE = 'Sending is not enabled yet (gate P3-G4): no messaging provider is registered, so every send is refused by the control plane.';

function provenanceTone(m: Message) {
    if (m.direction === 'internal') return 'neutral' as const;
    return m.trusted ? ('ok' as const) : ('warn' as const);
}

function provenanceLabel(m: Message): string {
    if (m.direction === 'internal') return 'internal note';
    switch (m.provenance) {
        case 'customer':
            return 'customer, untrusted';
        case 'generated':
            return 'agent, generated';
        case 'business':
            return 'colleague';
        case 'system':
            return 'system';
        default:
            return m.provenance;
    }
}

/** The hand-over reason card: the code as a badge, the sentence under it, who and when. No customer text is in any of it. */
function HandoverCard({ handover }: { handover: Handover }) {
    const t = useT();
    return (
        <section className="bg-tone-pending-bg border-tone-pending-line rounded-xl border p-4" data-testid="handover-reason">
            <SectionHeader title={t('Why a person is needed')} />
            <div className="mt-2 flex flex-wrap items-center gap-2">
                <StatusBadge tone="warn">{handover.reason ?? t('reason not recorded')}</StatusBadge>
                {handover.label && <span className="text-sm">{t(handover.label)}</span>}
            </div>
            {handover.detail && <p className="text-muted-foreground mt-2 text-xs">{handover.detail}</p>}
            <p className="text-muted-foreground mt-1 text-xs">
                {handover.from ? `${t('from')} ${handover.from}` : t('opened needing a person')}
                {handover.by ? ` · ${t('by')} ${handover.by}` : ''}
                {handover.at ? ` · ${timeAgo(handover.at)}` : ''}
            </p>
        </section>
    );
}

/** The recent orders card: the last five facts HQ holds for the known customer; a stranger has none. */
function RecentOrdersCard({ orders, known }: { orders: RecentOrder[]; known: boolean }) {
    const t = useT();
    return (
        <section className="hq-card p-4" data-testid="recent-orders">
            <SectionHeader title={t('Recent orders')} aside={<span className="text-muted-foreground text-xs">{t('HQ order facts, newest first')}</span>} />
            {!known || orders.length === 0 ? (
                <p className="text-muted-foreground mt-2 text-sm">{t('No known orders')}</p>
            ) : (
                <ul className="mt-2 flex flex-col gap-2">
                    {orders.map((o) => (
                        <li key={o.ref} className="bg-muted rounded-lg p-2.5 text-sm">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <span className="font-medium">{o.ref}</span>
                                <StatusBadge tone="neutral" dot={false}>
                                    {o.status}
                                </StatusBadge>
                            </div>
                            <div className="text-muted-foreground mt-1 text-xs">
                                {o.store ?? t('unknown store')}
                                {` · BDT ${formatNumber(o.gross)}`}
                                {o.payment_status ? ` · ${o.payment_status}` : ''}
                                {o.placed_at ? ` · ${formatDateTime(o.placed_at)}` : ''}
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

export default function InboxShow({
    thread,
    messages,
    proposals,
    handover,
    orders,
    memory,
    memory_keys,
    can_remember,
    comparisons,
    verdicts,
    assignees,
    agent,
    can,
}: {
    thread: Thread;
    messages: Message[];
    proposals: Proposal[];
    handover: Handover | null;
    orders: RecentOrder[];
    memory: Remembered[];
    memory_keys: string[];
    can_remember: boolean;
    comparisons: Comparison[];
    verdicts: string[];
    assignees: { id: number; email: string }[];
    agent: AgentStatus;
    can: { manage: boolean };
}) {
    const { flash, errors } = usePage<{ flash: { status?: string | null; outcome?: Outcome }; errors: Record<string, string> }>().props;
    const [busy, setBusy] = useState(false);
    const [dialog, setDialog] = useState<Dialog>(null);
    const [text, setText] = useState('');
    const [keepAsNote, setKeepAsNote] = useState(true);
    const [note, setNote] = useState('');
    const [memKind, setMemKind] = useState<'structured' | 'episodic'>('structured');
    const [memKey, setMemKey] = useState<string>(memory_keys[0] ?? 'about');
    const [memText, setMemText] = useState('');
    const [assignTo, setAssignTo] = useState<string>(assignees[0]?.id.toString() ?? '');
    const [editing, setEditing] = useState<Record<number, string>>({});

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Inbox', href: '/inbox' },
        { title: customerLabel(thread), href: `/inbox/${thread.uuid}` },
    ];

    const post = (action: string, body: Record<string, string | number | boolean | null> = {}, after?: () => void) => {
        setBusy(true);
        router.post(`/inbox/${thread.uuid}/${action}`, body, {
            preserveScroll: true,
            onFinish: () => {
                setBusy(false);
                setDialog(null);
                after?.();
            },
        });
    };

    const may = (state: string) => thread.allowed_next.includes(state);
    const outcome = flash?.outcome ?? null;
    const notSent = flash?.status === 'reply-not-sent';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Inbox · ${customerLabel(thread)}`} />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={customerLabel(thread)}
                    description={
                        <>
                            {thread.business ?? ''}
                            {thread.brand ? ` · ${thread.brand}` : ''}
                            {thread.platform_account ? ` · via ${thread.platform_account}` : ''}
                            {` · ${thread.channel}`}
                            {thread.opened_at ? ` · opened ${timeAgo(thread.opened_at)}` : ''}
                        </>
                    }
                    actions={
                        <div className="flex flex-wrap items-center gap-2">
                            <StatusBadge tone={stateTone(thread.state)}>{thread.state_label}</StatusBadge>
                            {languageLabel(thread.language) && (
                                <StatusBadge tone="info" dot={false}>
                                    {languageLabel(thread.language)}
                                </StatusBadge>
                            )}
                            {thread.ai_disabled && <StatusBadge tone="neutral">agent off for this thread</StatusBadge>}
                        </div>
                    }
                />

                {errors?.state && (
                    <Notice tone="danger" alert>
                        <strong>That move is not allowed.</strong> {errors.state}
                    </Notice>
                )}
                {errors?.decision && (
                    <Notice tone="danger" alert>
                        {errors.decision}
                    </Notice>
                )}
                {notSent && (
                    <Notice tone="warn" alert>
                        <strong>Not sent.</strong> {outcome?.error ?? SENDING_NOTICE}
                    </Notice>
                )}
                {flash?.status === 'reply-sent' && (
                    <Notice tone="ok">
                        <strong>Sent.</strong> The provider accepted it; delivery is verified separately.
                    </Notice>
                )}
                {flash?.status === 'proposal-discarded' && (
                    <Notice tone="neutral">The proposal was discarded and the decision recorded.</Notice>
                )}

                <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_320px]">
                    <div className="flex min-w-0 flex-col gap-6">
                        <section>
                            <SectionHeader title="Conversation" aside={<span className="text-muted-foreground text-xs">{messages.length} messages</span>} />
                            <div className="mt-3 flex flex-col gap-3">
                                {messages.length === 0 && <p className="text-muted-foreground text-sm">Nothing has been said yet.</p>}
                                {messages.map((m) => (
                                    <div
                                        key={m.uuid}
                                        className={
                                            m.direction === 'internal'
                                                ? 'bg-muted border-glass-border rounded-xl border border-dashed p-3'
                                                : m.direction === 'inbound'
                                                  ? 'hq-card mr-4 rounded-[22px_22px_22px_6px] p-3.5 sm:mr-8'
                                                  : 'bg-accent border-glass-border ml-4 rounded-[22px_22px_6px_22px] border p-3.5 sm:ml-8'
                                        }
                                    >
                                        <div className="flex flex-wrap items-center gap-2 text-xs">
                                            <span className="font-medium">{m.direction === 'inbound' ? 'Customer' : m.author}</span>
                                            <StatusBadge tone={provenanceTone(m)} dot={false}>
                                                {provenanceLabel(m)}
                                            </StatusBadge>
                                            {m.at && (
                                                <span className="text-muted-foreground" title={formatDateTime(m.at)}>
                                                    {timeAgo(m.at)}
                                                </span>
                                            )}
                                            {m.direction === 'outbound' && <span className="text-muted-foreground">{m.status}</span>}
                                        </div>
                                        <p className="mt-1 text-sm whitespace-pre-wrap">{m.body || <span className="text-muted-foreground">(no text)</span>}</p>
                                        {m.attachments.length > 0 && (
                                            <ul className="text-muted-foreground mt-1 text-xs">
                                                {m.attachments.map((a, i) => (
                                                    <li key={i}>
                                                        attachment: {a.type} {a.ref ? `(${a.ref})` : ''}
                                                    </li>
                                                ))}
                                            </ul>
                                        )}
                                    </div>
                                ))}
                            </div>
                        </section>

                        {proposals.length > 0 && (
                            <section>
                                <SectionHeader title="The agent suggested" />
                                <div className="mt-3 flex flex-col gap-3">
                                    {proposals.map((p) => (
                                        <article key={p.run_id} className="hq-card border-tone-pending-line p-4">
                                            <div className="text-muted-foreground flex flex-wrap gap-x-3 text-xs">
                                                <span>proposed by the support agent</span>
                                                {p.at && <span>{timeAgo(p.at)}</span>}
                                                <Link href={`/agents/runs/${p.run_id}`} className="underline-offset-4 hover:underline touch:inline-flex touch:min-h-11 touch:items-center">
                                                    run #{p.run_id}
                                                </Link>
                                            </div>
                                            {p.why && <p className="mt-1 text-xs italic">{p.why}</p>}
                                            {can.manage ? (
                                                <Textarea
                                                    className="mt-2"
                                                    aria-label="Proposed reply"
                                                    rows={3}
                                                    value={editing[p.run_id] ?? p.text}
                                                    onChange={(e) => setEditing({ ...editing, [p.run_id]: e.target.value })}
                                                />
                                            ) : (
                                                <p className="mt-2 text-sm whitespace-pre-wrap">{p.text}</p>
                                            )}
                                            {can.manage && (
                                                <div className="mt-3 flex flex-wrap gap-2">
                                                    <Button
                                                        size="sm"
                                                        disabled={busy}
                                                        onClick={() =>
                                                            post(`proposals/${p.run_id}/decide`, {
                                                                decision: 'send',
                                                                text: editing[p.run_id] ?? p.text,
                                                            })
                                                        }
                                                    >
                                                        {(editing[p.run_id] ?? p.text) === p.text ? 'Send as it is' : 'Send edited'}
                                                    </Button>
                                                    <Button size="sm" variant="outline" disabled={busy} onClick={() => setDialog({ kind: 'discard', run: p.run_id })}>
                                                        Discard
                                                    </Button>
                                                </div>
                                            )}
                                        </article>
                                    ))}
                                </div>
                            </section>
                        )}

                        {comparisons.length > 0 && (
                            <section>
                                <SectionHeader title="Shadow comparison" aside={<span className="text-muted-foreground text-xs">what the agent proposed, beside what was sent</span>} />
                                <div className="mt-3 flex flex-col gap-3">
                                    {comparisons.map((c) => (
                                        <article key={c.id} className="hq-card p-4">
                                            <div className="text-muted-foreground flex flex-wrap gap-x-3 text-xs">
                                                {c.run_id !== null && (
                                                    <Link href={`/agents/runs/${c.run_id}`} className="underline-offset-4 hover:underline touch:inline-flex touch:min-h-11 touch:items-center">
                                                        proposal, run #{c.run_id}
                                                    </Link>
                                                )}
                                                {c.sent_at && <span>sent {timeAgo(c.sent_at)}</span>}
                                                {c.identical && <span>identical text</span>}
                                                {c.verdict && <StatusBadge tone={c.verdict === 'unmeasured' ? 'neutral' : 'ok'}>{c.verdict}</StatusBadge>}
                                            </div>
                                            <div className="mt-2 grid gap-3 sm:grid-cols-2">
                                                <div>
                                                    <div className="hq-eyebrow">the agent proposed</div>
                                                    <p className="mt-1 text-sm whitespace-pre-wrap">{c.proposed ?? '(the run no longer holds a proposal)'}</p>
                                                </div>
                                                <div>
                                                    <div className="hq-eyebrow">what was actually sent</div>
                                                    <p className="mt-1 text-sm whitespace-pre-wrap">{c.sent ?? (c.verdict === 'unmeasured' ? '(nothing was sent within a day)' : '(no text)')}</p>
                                                </div>
                                            </div>
                                            {can.manage && c.verdict !== 'unmeasured' && (
                                                <div className="mt-3 flex flex-wrap gap-2">
                                                    {verdicts.map((v) => (
                                                        <Button
                                                            key={v}
                                                            size="sm"
                                                            variant={c.verdict === v ? 'default' : 'outline'}
                                                            disabled={busy}
                                                            onClick={() => post(`comparisons/${c.id}/verdict`, { verdict: v })}
                                                        >
                                                            {v}
                                                        </Button>
                                                    ))}
                                                </div>
                                            )}
                                        </article>
                                    ))}
                                </div>
                            </section>
                        )}

                        {can.manage && (
                            <section>
                                <SectionHeader title="Reply" />
                                <InertNotice
                                    className="mt-2"
                                    title="Sending to the customer"
                                    reason={SENDING_NOTICE}
                                    gate="A messaging provider is registered and the channel is connected and verified (gate P3-G4)."
                                />
                                <Textarea
                                    className="mt-2"
                                    aria-label="Reply"
                                    rows={3}
                                    placeholder="Write in the customer's language"
                                    value={text}
                                    onChange={(e) => setText(e.target.value)}
                                />
                                <div className="mt-2 flex flex-wrap items-center gap-3">
                                    <Button size="sm" disabled={busy || text.trim() === ''} onClick={() => post('reply', { text, keep_as_note: keepAsNote }, () => setText(''))}>
                                        Send through the control plane
                                    </Button>
                                    <label className="flex min-h-9 cursor-pointer items-center gap-2 text-xs touch:min-h-11">
                                        <input type="checkbox" className="accent-hq-violet size-4" checked={keepAsNote} onChange={(e) => setKeepAsNote(e.target.checked)} />
                                        keep as an internal note if it cannot be sent
                                    </label>
                                </div>
                            </section>
                        )}

                        {can.manage && (
                            <section>
                                <SectionHeader title="Internal note" />
                                <Textarea
                                    className="mt-2"
                                    aria-label="Internal note"
                                    rows={2}
                                    placeholder="Seen by colleagues only"
                                    value={note}
                                    onChange={(e) => setNote(e.target.value)}
                                />
                                <div className="mt-2">
                                    <Button size="sm" variant="outline" disabled={busy || note.trim() === ''} onClick={() => post('note', { body: note }, () => setNote(''))}>
                                        Add note
                                    </Button>
                                </div>
                            </section>
                        )}
                    </div>

                    <aside className="flex flex-col gap-6">
                        {handover !== null && <HandoverCard handover={handover} />}
                        {thread.customer_id !== null && (
                            <section className="hq-card p-4">
                                <SectionHeader title="Remembered about this customer" />
                                <p className="text-muted-foreground mt-1 text-xs">
                                    What a colleague chose to remember. Memory is not truth: the order facts win when they disagree, and the agent
                                    is told the same.
                                </p>
                                {memory.length === 0 ? (
                                    <p className="text-muted-foreground mt-3 text-sm">Nothing remembered yet.</p>
                                ) : (
                                    <ul className="mt-3 flex flex-col gap-2">
                                        {memory.map((m) => (
                                            <li key={m.id} className="bg-muted rounded-lg p-2.5 text-sm">
                                                <div className="flex items-start justify-between gap-2">
                                                    <div>
                                                        {m.kind === 'structured' && (
                                                            <span className="font-medium">{MEMORY_KEY_LABELS[m.key ?? ''] ?? m.key}: </span>
                                                        )}
                                                        <span>{m.text}</span>
                                                    </div>
                                                    {can.manage && m.closable && (
                                                        <Button size="sm" variant="ghost" disabled={busy} onClick={() => post(`memory/${m.id}/forget`)}>
                                                            Forget
                                                        </Button>
                                                    )}
                                                </div>
                                                <div className="text-muted-foreground mt-1 text-xs">
                                                    {m.kind === 'structured' ? 'fact' : 'episode'} · {m.provenance}
                                                    {m.source ? ` · ${m.source}` : ''}
                                                    {m.at ? ` · ${timeAgo(m.at)}` : ''}
                                                </div>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                                {can.manage && can_remember && (
                                    <div className="mt-3 flex flex-col gap-2">
                                        <div className="flex flex-wrap gap-2">
                                            <NativeSelect aria-label="Kind of memory" value={memKind} onChange={(e) => setMemKind(e.target.value as 'structured' | 'episodic')}>
                                                <option value="structured">A fact (replaces the previous one for its key)</option>
                                                <option value="episodic">Something that happened (kept as it was)</option>
                                            </NativeSelect>
                                            {memKind === 'structured' && (
                                                <NativeSelect aria-label="Which fact" value={memKey} onChange={(e) => setMemKey(e.target.value)}>
                                                    {memory_keys.map((k) => (
                                                        <option key={k} value={k}>
                                                            {MEMORY_KEY_LABELS[k] ?? k}
                                                        </option>
                                                    ))}
                                                </NativeSelect>
                                            )}
                                        </div>
                                        <Textarea
                                            aria-label="What to remember"
                                            rows={2}
                                            placeholder={memKind === 'structured' ? 'The fact, in a few words' : 'What happened, in a sentence'}
                                            value={memText}
                                            onChange={(e) => setMemText(e.target.value)}
                                        />
                                        {errors.kind && <p className="text-tone-danger text-xs">{errors.kind}</p>}
                                        {errors.value && <p className="text-tone-danger text-xs">{errors.value}</p>}
                                        {errors.content && <p className="text-tone-danger text-xs">{errors.content}</p>}
                                        <div>
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                disabled={busy || memText.trim() === ''}
                                                onClick={() =>
                                                    post(
                                                        'remember',
                                                        memKind === 'structured'
                                                            ? { kind: 'structured', key: memKey, value: memText }
                                                            : { kind: 'episodic', content: memText },
                                                        () => setMemText(''),
                                                    )
                                                }
                                            >
                                                Remember
                                            </Button>
                                        </div>
                                    </div>
                                )}
                                {can.manage && !can_remember && (
                                    <p className="text-muted-foreground mt-3 text-xs">A fact is remembered once the thread is resolved or closed.</p>
                                )}
                            </section>
                        )}
                        <section className="hq-card p-4">
                            <SectionHeader title="Customer" />
                            {thread.customer ? (
                                <dl className="mt-2 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm">
                                    <dt className="text-muted-foreground">Name</dt>
                                    <dd>{thread.customer.name ?? 'unknown'}</dd>
                                    <dt className="text-muted-foreground">Phone</dt>
                                    <dd>{thread.customer.phone ?? 'not linked'}</dd>
                                    <dt className="text-muted-foreground">Identity</dt>
                                    <dd>{thread.stranger ? 'a stranger until an order or a phone links them' : 'known'}</dd>
                                    <dt className="text-muted-foreground">360</dt>
                                    <dd>
                                        <Link href={`/customers/${thread.customer.id}`} className="underline underline-offset-4 touch:inline-flex touch:min-h-11 touch:items-center">
                                            open the customer
                                        </Link>
                                    </dd>
                                </dl>
                            ) : (
                                <p className="text-muted-foreground mt-2 text-sm">No person is linked to this thread.</p>
                            )}
                        </section>

                        <RecentOrdersCard orders={orders} known={thread.customer !== null && !thread.stranger} />

                        <section className="hq-card p-4">
                            <SectionHeader title="Handling" />
                            <div className="mt-2">
                                <AgentNotice agent={agent} />
                            </div>
                            <dl className="mt-2 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm">
                                <dt className="text-muted-foreground">Assigned</dt>
                                <dd>{thread.assignee ?? 'nobody'}</dd>
                            </dl>
                            {can.manage && (
                                <div className="mt-3 flex flex-col gap-2">
                                    {may('human_active') && (
                                        <Button size="sm" disabled={busy} onClick={() => post('take-over')}>
                                            Take over
                                        </Button>
                                    )}
                                    {may('human_resolved') && (
                                        <Button size="sm" variant="outline" disabled={busy} onClick={() => setDialog({ kind: 'resolve' })}>
                                            Resolve
                                        </Button>
                                    )}
                                    {may('ai_resumed') && (
                                        <Button size="sm" variant="outline" disabled={busy || !agent.active || thread.ai_disabled} onClick={() => post('return-to-agent')}>
                                            Return to the agent
                                        </Button>
                                    )}
                                    {may('closed') && (
                                        <Button size="sm" variant="outline" disabled={busy} onClick={() => setDialog({ kind: 'close' })}>
                                            Close
                                        </Button>
                                    )}
                                    {thread.status !== 'closed' && (
                                        <Button size="sm" variant="outline" disabled={busy} onClick={() => post('toggle-ai')}>
                                            {thread.ai_disabled ? 'Allow the agent on this thread' : 'Keep the agent off this thread'}
                                        </Button>
                                    )}
                                    {assignees.length > 0 && thread.status !== 'closed' && (
                                        <div className="mt-2 flex flex-wrap items-center gap-2">
                                            <NativeSelect aria-label="Assign to" className="min-w-0 flex-1" value={assignTo} onChange={(e) => setAssignTo(e.target.value)}>
                                                {assignees.map((u) => (
                                                    <option key={u.id} value={u.id}>
                                                        {u.email}
                                                    </option>
                                                ))}
                                            </NativeSelect>
                                            <Button size="sm" variant="outline" disabled={busy || assignTo === ''} onClick={() => post('assign', { user_id: Number(assignTo) })}>
                                                Assign
                                            </Button>
                                        </div>
                                    )}
                                    {errors?.user_id && <p className="text-xs text-tone-danger">{errors.user_id}</p>}
                                </div>
                            )}
                        </section>
                    </aside>
                </div>
            </div>

            <ConfirmDialog
                open={dialog?.kind === 'resolve'}
                onOpenChange={(open) => !open && setDialog(null)}
                title="Resolve this conversation"
                description="The customer's matter is settled by a person. If they write again, the thread comes back."
                confirmLabel="Resolve"
                busy={busy}
                reason={{ label: 'What was done (optional)', placeholder: 'e.g. confirmed the parcel leaves tomorrow' }}
                onConfirm={(reason) => post('resolve', { note: reason || null })}
            />
            <ConfirmDialog
                open={dialog?.kind === 'close'}
                onOpenChange={(open) => !open && setDialog(null)}
                title="Close this conversation"
                description="A closed thread stays closed. The customer's next message opens a new one."
                confirmLabel="Close"
                busy={busy}
                reason={{ label: 'Reason (optional)' }}
                onConfirm={(reason) => post('close', { reason: reason || null })}
            />
            <ConfirmDialog
                open={dialog?.kind === 'discard'}
                onOpenChange={(open) => !open && setDialog(null)}
                title="Discard the agent's proposal"
                description="The suggestion is not sent. The decision is recorded beside the proposal, which is what the shadow gate reads."
                confirmLabel="Discard"
                destructive
                busy={busy}
                reason={{ label: 'Why (optional)' }}
                onConfirm={(reason) => dialog?.run !== undefined && post(`proposals/${dialog.run}/decide`, { decision: 'discard', reason: reason || null })}
            />
        </AppLayout>
    );
}
