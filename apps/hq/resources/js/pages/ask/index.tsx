import { EmptyState } from '@/components/empty-state';
import { Field, FormRow, NativeSelect } from '@/components/form/field';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { humanKey, humanMillis, timeAgo } from '@/lib/format';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { MessageSquarePlus, Sparkles } from 'lucide-react';
import { useEffect } from 'react';

type ToolCallRow = { id: number; tool_key: string; status: string; policy_result: string | null; duration_ms: number | null };

type Message = {
    id: number;
    role: 'user' | 'assistant' | 'system';
    status: 'pending' | 'done' | 'failed' | 'refused';
    content: string;
    structured: Record<string, unknown> | null;
    created_at: string | null;
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
                <div className="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
                    <strong>Shadow.</strong> The agent runs at autonomy {run.autonomy_level?.toUpperCase() ?? 'A0'}, so what follows is not HQ&apos;s
                    answer. It is a proposal for a person to review, and nothing was done because of it.
                </div>
            )}
            <p className="text-sm whitespace-pre-line">{message.content || 'The agent gave no text.'}</p>
            {run && (
                <div className="text-muted-foreground flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                    <StatusBadge status={run.status} />
                    {run.stop_reason && <span>stopped: {humanKey(run.stop_reason)}</span>}
                    <span>{run.tool_calls.length} tool call(s)</span>
                    <Link href={`/agents/runs/${run.id}`} className="underline">
                        Open the run
                    </Link>
                </div>
            )}
            {run && run.tool_calls.length > 0 && (
                <div className="overflow-x-auto rounded-md border">
                    <table className="w-full text-xs">
                        <thead className="bg-muted/50 text-muted-foreground text-left">
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

function AskPage({ conversation, messages, conversations, agents, businesses, runtime_on, max_question }: Props) {
    const firstAskable = agents.find((a) => a.refusal === null)?.key ?? agents[0]?.key ?? 'executive';
    const form = useForm({
        question: '',
        agent_key: conversation?.agent_key ?? firstAskable,
        business_id: conversation?.business_id ? String(conversation.business_id) : '',
        conversation: conversation?.uuid ?? '',
    });
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
        form.post('/ask', { preserveScroll: true, onSuccess: () => form.reset('question') });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Ask HQ" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Ask HQ"
                    description="Put a question to an agent. It answers through the same runner, tools and policies as everywhere else, under its enabling user and its own ceiling; this page holds no power of its own."
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
                            <p className="text-muted-foreground text-xs">No conversations yet.</p>
                        ) : (
                            <nav className="flex flex-col gap-1">
                                {conversations.map((c) => (
                                    <Link
                                        key={c.uuid}
                                        href={`/ask/${c.uuid}`}
                                        className={cn(
                                            'hover:bg-muted rounded-md px-2 py-1.5 text-sm',
                                            conversation?.uuid === c.uuid && 'bg-muted font-medium',
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
                                            'rounded-xl border p-3',
                                            m.role === 'user' && 'bg-muted/40 ml-auto max-w-3xl',
                                            m.role === 'assistant' && 'bg-card mr-auto max-w-3xl',
                                            m.role === 'system' && 'mx-auto max-w-3xl border-dashed',
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
                                    </li>
                                ))}
                            </ol>
                        )}

                        <form onSubmit={submit} className="bg-card flex flex-col gap-3 rounded-xl border p-4">
                            <textarea
                                value={form.data.question}
                                onChange={(e) => form.setData('question', e.target.value)}
                                rows={3}
                                maxLength={max_question}
                                placeholder="Your question, in Bangla or English"
                                className="border-input bg-background w-full rounded-md border p-2 text-sm"
                                aria-label="Your question"
                            />
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
                            {chosen?.refusal && <p className="text-sm text-amber-700 dark:text-amber-300">{chosen.refusal}</p>}
                            <div className="flex flex-wrap items-center gap-3">
                                <Button type="submit" disabled={form.processing || form.data.question.trim() === '' || chosen?.refusal !== null}>
                                    Ask
                                </Button>
                                {!runtime_on && (
                                    <span className="text-muted-foreground text-xs">
                                        The AI runtime is off, so the question is recorded and not asked.
                                    </span>
                                )}
                            </div>
                        </form>
                    </section>
                </div>
            </div>
        </AppLayout>
    );
}
