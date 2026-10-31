import { EmptyState } from '@/components/empty-state';
import { Field, FormRow, NativeSelect } from '@/components/form/field';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { InertNotice, Notice } from '@/components/ui/notice';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { humanKey, humanMillis, timeAgo } from '@/lib/format';
import { postForm } from '@/lib/post-form';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { MessageSquarePlus, Mic, Paperclip, Sparkles } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

type ToolCallRow = { id: number; tool_key: string; status: string; policy_result: string | null; duration_ms: number | null };

type Message = {
    id: number;
    role: 'user' | 'assistant' | 'system';
    status: 'pending' | 'done' | 'failed' | 'refused';
    content: string;
    structured: Record<string, unknown> | null;
    created_at: string | null;
    attachments?: { uuid: string; name: string; kind: string }[];
    run: {
        id: number;
        uuid: string;
        status: string;
        shadow: boolean;
        autonomy_level: string | null;
        stop_reason: string | null;
        tool_calls: ToolCallRow[];
    } | null;
};

type Props = {
    conversation: { uuid: string; title: string; agent_key: string; business_id: number | null } | null;
    messages: Message[];
    conversations: { uuid: string; title: string; agent_key: string; last_activity_at: string }[];
    agents: { key: string; refusal: string | null }[];
    businesses: { id: number; name: string }[];
    runtime_on: boolean;
    uploads_on?: boolean;
    voice_on?: boolean;
    max_question: number;
};

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Ask HQ', href: '/ask' }];

function Answer({ message }: { message: Message }) {
    if (message.status === 'pending') {
        return <p className="text-muted-foreground text-sm italic">The agent is working on it. This page refreshes by itself.</p>;
    }

    const run = message.run;

    return (
        <div className="flex flex-col gap-2">
            {run?.shadow && (
                <Notice tone="pending" className="text-xs">
                    <strong>Shadow.</strong> The agent runs at autonomy {run.autonomy_level?.toUpperCase() ?? 'A0'}, so what follows is not HQ&apos;s
                    answer. It is a proposal for a person to review, and nothing was done because of it.
                </Notice>
            )}
            <p className="text-sm whitespace-pre-line">{message.content || 'The agent gave no text.'}</p>
            {run && (
                <div className="text-muted-foreground flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                    <StatusBadge status={run.status} />
                    {run.stop_reason && <span>stopped: {humanKey(run.stop_reason)}</span>}
                    <span>{run.tool_calls.length} tool call(s)</span>
                    <Link href={`/agents/runs/${run.id}`} className="inline-flex items-center underline underline-offset-4 touch:min-h-11">
                        Open the run
                    </Link>
                </div>
            )}
            {run && run.tool_calls.length > 0 && (
                <div className="hq-table-box">
                    <table className="w-full text-xs">
                        <thead className="text-left">
                            <tr>
                                <th className="px-2 py-1 font-medium">Tool</th>
                                <th className="px-2 py-1 font-medium">Policy</th>
                                <th className="px-2 py-1 font-medium">Result</th>
                                <th className="px-2 py-1 text-right font-medium">Took</th>
                            </tr>
                        </thead>
                        <tbody>
                            {run.tool_calls.map((c) => (
                                <tr key={c.id} className="border-t">
                                    <td className="px-2 py-1 font-mono">{c.tool_key}</td>
                                    <td className="px-2 py-1">{c.policy_result ?? '-'}</td>
                                    <td className="px-2 py-1">
                                        <StatusBadge status={c.status} />
                                    </td>
                                    <td className="px-2 py-1 text-right tabular-nums">{humanMillis(c.duration_ms)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </div>
    );
}

// Keyed on the conversation, so the form starts fresh when the page moves to another thread.
export default function AskIndex(props: Props) {
    return <AskPage key={props.conversation?.uuid ?? 'new'} {...props} />;
}

function AskPage({ conversation, messages, conversations, agents, businesses, runtime_on, uploads_on, voice_on, max_question }: Props) {
    const firstAskable = agents.find((a) => a.refusal === null)?.key ?? agents[0]?.key ?? 'executive';
    const form = useForm({
        question: '',
        agent_key: conversation?.agent_key ?? firstAskable,
        business_id: conversation?.business_id ? String(conversation.business_id) : '',
        conversation: conversation?.uuid ?? '',
        attachment_uuids: [] as string[],
    });
    const [files, setFiles] = useState<{ uuid: string; name: string }[]>([]);
    const [uploadError, setUploadError] = useState<string | null>(null);
    const picker = useRef<HTMLInputElement>(null);

    // A transcript from the voice screen lands in the question box, to be read and sent by the person (speech adds no authority).
    useEffect(() => {
        try {
            const draft = sessionStorage.getItem('hq-voice-draft');
            if (draft) {
                sessionStorage.removeItem('hq-voice-draft');
                form.setData('question', draft);
            }
        } catch {
            /* no session storage: the box starts empty */
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const attach = async (list: FileList | null) => {
        if (!list) return;
        setUploadError(null);
        let uuids = form.data.attachment_uuids;
        for (const file of Array.from(list)) {
            const body = new FormData();
            body.append('file', file);
            if (form.data.business_id) body.append('business_id', form.data.business_id);
            const r = await postForm<{ uuid: string; name: string }>('/ask/media', body);
            if (r.ok) {
                setFiles((f) => [...f, { uuid: r.data.uuid, name: r.data.name }]);
                uuids = [...uuids, r.data.uuid];
                form.setData('attachment_uuids', uuids);
            } else {
                setUploadError(r.error);
            }
        }
        if (picker.current) picker.current.value = '';
    };
    const chosen = agents.find((a) => a.key === form.data.agent_key);
    const waiting = messages.some((m) => m.status === 'pending');

    // While an answer is pending, read the thread again every few seconds; stop as soon as none is.
    useEffect(() => {
        if (!waiting) {
            return;
        }
        const timer = window.setInterval(() => router.reload({ only: ['messages', 'conversations'] }), 3000);

        return () => window.clearInterval(timer);
    }, [waiting]);

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/ask', {
            preserveScroll: true,
            onSuccess: () => {
                form.reset('question', 'attachment_uuids');
                setFiles([]);
            },
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Ask HQ" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Ask HQ"
                    description="Put a question to an agent. It answers through the same runner, tools and policies as everywhere else, with only what you and its enabling user may both do, under its own ceiling; this page holds no power of its own."
                    actions={
                        <StatusBadge tone={runtime_on ? 'warn' : 'neutral'}>
                            {runtime_on ? 'AI runtime on' : 'AI runtime off: nothing is asked'}
                        </StatusBadge>
                    }
                />

                <div className="grid gap-6 lg:grid-cols-[16rem_1fr]">
                    <aside className="flex min-w-0 flex-col gap-2">
                        <Button variant="outline" size="sm" asChild>
                            <Link href="/ask">
                                <MessageSquarePlus className="size-4" aria-hidden /> New conversation
                            </Link>
                        </Button>
                        {conversations.length === 0 ? (
                            <p className="text-muted-foreground text-xs">No conversations yet. Asking a question starts one.</p>
                        ) : (
                            <nav className="flex flex-col gap-1" aria-label="Conversations">
                                {conversations.map((c) => (
                                    <Link
                                        key={c.uuid}
                                        href={`/ask/${c.uuid}`}
                                        className={cn(
                                            'hover:bg-accent flex min-h-10 flex-col justify-center rounded-lg px-3 py-1.5 text-sm transition-colors touch:min-h-11',
                                            conversation?.uuid === c.uuid && 'bg-accent font-medium',
                                        )}
                                    >
                                        <div className="truncate">{c.title}</div>
                                        <div className="text-muted-foreground text-xs">
                                            {c.agent_key}, {timeAgo(c.last_activity_at)}
                                        </div>
                                    </Link>
                                ))}
                            </nav>
                        )}
                    </aside>

                    <section className="flex min-w-0 flex-col gap-4">
                        {messages.length === 0 ? (
                            <EmptyState
                                icon={Sparkles}
                                title={conversation ? conversation.title : 'Ask a question'}
                                description="For example: what needs my attention today, or how did the group do yesterday. Every figure in an answer names its source."
                            />
                        ) : (
                            <ol className="flex flex-col gap-3">
                                {messages.map((m) => (
                                    <li
                                        key={m.id}
                                        className={cn(
                                            'max-w-3xl min-w-0 p-3.5',
                                            m.role === 'user' && 'bg-accent border-glass-border ml-auto rounded-[22px_22px_6px_22px] border',
                                            m.role === 'assistant' && 'hq-card mr-auto',
                                            m.role === 'system' && 'border-glass-border mx-auto rounded-xl border border-dashed',
                                        )}
                                    >
                                        <div className="text-muted-foreground mb-1 flex flex-wrap items-center gap-2 text-xs">
                                            <span className="font-medium">
                                                {m.role === 'user'
                                                    ? 'You'
                                                    : m.role === 'assistant'
                                                      ? humanKey(String(m.structured?.agent_key ?? 'agent'))
                                                      : 'HQ'}
                                            </span>
                                            {m.status !== 'done' && <StatusBadge status={m.status} />}
                                            <span>{timeAgo(m.created_at)}</span>
                                        </div>
                                        {m.role === 'assistant' ? <Answer message={m} /> : <p className="text-sm whitespace-pre-line">{m.content}</p>}
                                        {(m.attachments ?? []).length > 0 && (
                                            <ul className="mt-2 flex flex-wrap gap-2 text-xs">
                                                {(m.attachments ?? []).map((a) => (
                                                    <li key={a.uuid}>
                                                        <a className="underline" href={`/media/${a.uuid}`} target="_blank" rel="noreferrer">
                                                            {a.name}
                                                        </a>
                                                    </li>
                                                ))}
                                            </ul>
                                        )}
                                    </li>
                                ))}
                            </ol>
                        )}

                        <form onSubmit={submit} className="hq-card flex flex-col gap-3 p-4">
                            <Field label="Your question" htmlFor="ask-question">
                                <Textarea
                                    id="ask-question"
                                    value={form.data.question}
                                    onChange={(e) => form.setData('question', e.target.value)}
                                    rows={3}
                                    maxLength={max_question}
                                    placeholder="Your question, in Bangla or English"
                                    aria-label="Your question"
                                />
                            </Field>
                            <InputError message={form.errors.question} />
                            <FormRow>
                                <Field label="Agent" htmlFor="ask-agent" error={form.errors.agent_key}>
                                    <NativeSelect
                                        id="ask-agent"
                                        value={form.data.agent_key}
                                        onChange={(e) => form.setData('agent_key', e.target.value)}
                                    >
                                        {agents.map((a) => (
                                            <option key={a.key} value={a.key} disabled={a.refusal !== null}>
                                                {humanKey(a.key)}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </Field>
                                <Field label="Business" htmlFor="ask-business" error={form.errors.business_id}>
                                    <NativeSelect
                                        id="ask-business"
                                        value={form.data.business_id}
                                        onChange={(e) => form.setData('business_id', e.target.value)}
                                    >
                                        <option value="">Whole group</option>
                                        {businesses.map((b) => (
                                            <option key={b.id} value={String(b.id)}>
                                                {b.name}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </Field>
                            </FormRow>
                            {chosen?.refusal && <Notice tone="pending">{chosen.refusal}</Notice>}
                            {files.length > 0 && (
                                <ul className="flex flex-wrap gap-2 text-xs" aria-label="Attached files">
                                    {files.map((f) => (
                                        <li key={f.uuid} className="border-glass-border rounded-full border px-2.5 py-1">
                                            {f.name}
                                        </li>
                                    ))}
                                </ul>
                            )}
                            {uploadError && <InputError message={uploadError} />}
                            <div className="flex flex-wrap items-center gap-3">
                                <Button type="submit" disabled={form.processing || form.data.question.trim() === '' || chosen?.refusal !== null}>
                                    Ask
                                </Button>
                                {uploads_on && (
                                    <>
                                        <input
                                            ref={picker}
                                            type="file"
                                            multiple
                                            hidden
                                            onChange={(e) => void attach(e.target.files)}
                                            accept="image/jpeg,image/png,image/webp,video/mp4,video/webm,video/quicktime,application/pdf,text/plain,text/csv"
                                        />
                                        <Button type="button" variant="outline" onClick={() => picker.current?.click()}>
                                            <Paperclip className="size-4" aria-hidden /> Attach a photo, video or file
                                        </Button>
                                    </>
                                )}
                                {voice_on && (
                                    <Button type="button" variant="outline" asChild>
                                        <Link href="/voice">
                                            <Mic className="size-4" aria-hidden /> Talk
                                        </Link>
                                    </Button>
                                )}
                            </div>
                            {uploads_on && (
                                <p className="text-muted-foreground text-xs">
                                    Attached files are kept for a limited time, for the people of this business. The agent does not read them: they are for people.
                                </p>
                            )}
                            {!runtime_on && (
                                <InertNotice
                                    title="Answers from an agent"
                                    reason="The AI runtime is off, so the question is recorded and not asked."
                                    gate="A person switches the AI runtime on. Until then no agent answers and nothing is done."
                                />
                            )}
                        </form>
                    </section>
                </div>
            </div>
        </AppLayout>
    );
}
