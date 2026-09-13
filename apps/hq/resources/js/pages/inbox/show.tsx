import { ConfirmDialog } from '@/components/confirm-dialog';
import { NativeSelect } from '@/components/form/field';
import { PageHeader, SectionHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, timeAgo } from '@/lib/format';
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

export default function InboxShow({
    thread,
    messages,
    proposals,
    assignees,
    agent,
    can,
}: {
    thread: Thread;
    messages: Message[];
    proposals: Proposal[];
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
                    <div className="rounded-xl border border-red-300 bg-red-50 p-4 text-sm text-red-900 dark:border-red-900 dark:bg-red-950/40 dark:text-red-200">
                        <strong>That move is not allowed.</strong> {errors.state}
                    </div>
                )}
                {errors?.decision && (
                    <div className="rounded-xl border border-red-300 bg-red-50 p-4 text-sm text-red-900 dark:border-red-900 dark:bg-red-950/40 dark:text-red-200">
                        {errors.decision}
                    </div>
                )}
                {notSent && (
                    <div className="rounded-xl border border-orange-300 bg-orange-50 p-4 text-sm text-orange-900 dark:border-orange-900 dark:bg-orange-950/40 dark:text-orange-200">
                        <strong>Not sent.</strong> {outcome?.error ?? SENDING_NOTICE}
                    </div>
                )}
                {flash?.status === 'reply-sent' && (
                    <div className="rounded-xl border border-emerald-300 bg-emerald-50 p-4 text-sm text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-200">
                        <strong>Sent.</strong> The provider accepted it; delivery is verified separately.
                    </div>
                )}
                {flash?.status === 'proposal-discarded' && (
                    <div className="rounded-xl border p-4 text-sm">The proposal was discarded and the decision recorded.</div>
                )}

                <div className="grid gap-6 lg:grid-cols-[1fr_320px]">
                    <div className="flex flex-col gap-6">
                        <section>
                            <SectionHeader title="Conversation" aside={<span className="text-muted-foreground text-xs">{messages.length} messages</span>} />
                            <div className="mt-3 flex flex-col gap-3">
                                {messages.length === 0 && <p className="text-muted-foreground text-sm">Nothing has been said yet.</p>}
                                {messages.map((m) => (
                                    <div
                                        key={m.uuid}
                                        className={
                                            m.direction === 'internal'
                                                ? 'bg-muted/60 rounded-xl border border-dashed p-3'
                                                : m.direction === 'inbound'
                                                  ? 'bg-card mr-8 rounded-xl border p-3'
                                                  : 'bg-primary/5 ml-8 rounded-xl border p-3'
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
                                        <article key={p.run_id} className="rounded-xl border border-amber-300 bg-amber-50 p-4 dark:border-amber-900 dark:bg-amber-950/30">
                                            <div className="text-muted-foreground flex flex-wrap gap-x-3 text-xs">
                                                <span>proposed by the support agent</span>
                                                {p.at && <span>{timeAgo(p.at)}</span>}
                                                <Link href={`/agents/runs/${p.run_id}`} className="hover:underline">
                                                    run #{p.run_id}
                                                </Link>
                                            </div>
                                            {p.why && <p className="mt-1 text-xs italic">{p.why}</p>}
                                            {can.manage ? (
                                                <textarea
                                                    className="bg-background mt-2 w-full rounded-md border p-2 text-sm"
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

                        {can.manage && (
                            <section>
                                <SectionHeader title="Reply" />
                                <p className="text-muted-foreground mt-1 text-xs">{SENDING_NOTICE}</p>
                                <textarea
                                    className="bg-background mt-2 w-full rounded-md border p-2 text-sm"
                                    rows={3}
                                    placeholder="Write in the customer's language"
                                    value={text}
                                    onChange={(e) => setText(e.target.value)}
                                />
                                <div className="mt-2 flex flex-wrap items-center gap-3">
                                    <Button size="sm" disabled={busy || text.trim() === ''} onClick={() => post('reply', { text, keep_as_note: keepAsNote }, () => setText(''))}>
                                        Send through the control plane
                                    </Button>
                                    <label className="flex items-center gap-2 text-xs">
                                        <input type="checkbox" checked={keepAsNote} onChange={(e) => setKeepAsNote(e.target.checked)} />
                                        keep as an internal note if it cannot be sent
                                    </label>
                                </div>
                            </section>
                        )}

                        {can.manage && (
                            <section>
                                <SectionHeader title="Internal note" />
                                <textarea
                                    className="bg-background mt-2 w-full rounded-md border p-2 text-sm"
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
                        <section className="bg-card rounded-xl border p-4">
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
                                        <Link href={`/customers/${thread.customer.id}`} className="hover:underline">
                                            open the customer
                                        </Link>
                                    </dd>
                                </dl>
                            ) : (
                                <p className="text-muted-foreground mt-2 text-sm">No person is linked to this thread.</p>
                            )}
                        </section>

                        <section className="bg-card rounded-xl border p-4">
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
                                        <div className="mt-2 flex items-center gap-2">
                                            <NativeSelect value={assignTo} onChange={(e) => setAssignTo(e.target.value)}>
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
                                    {errors?.user_id && <p className="text-xs text-red-700 dark:text-red-300">{errors.user_id}</p>}
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
