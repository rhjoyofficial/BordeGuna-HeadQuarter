import { runStatus } from '@/components/agents/run-status';
import { EmptyState } from '@/components/empty-state';
import { PageHeader, SectionHeader, TechnicalDetail } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, humanKey, humanMillis, humanUsd, timeAgo, truncate } from '@/lib/format';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { Bot } from 'lucide-react';

type AgentCard = {
    key: string;
    name: string;
    purpose: string | null;
    status: string;
    role_key: string | null;
    model_tier: string | null;
    max_autonomy_level: string | null;
    allowed_tools: string[];
    enabling_user: { user_id: number; name: string | null } | null;
    runs: number;
    awaiting_review: number;
};

type RunCard = {
    id: number;
    uuid: string;
    agent_key: string;
    question: string;
    answer: string | null;
    status: string;
    stop_reason: string | null;
    shadow: boolean;
    autonomy_level: string | null;
    autonomy_reason: string | null;
    turns: number;
    tool_calls_count: number;
    duration_ms: number | null;
    model: string | null;
    cost_usd: number;
    trigger: string;
    reviewed_at: string | null;
    review_verdict: string | null;
    created_at: string | null;
};

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Agents', href: '/agents' }];

const AUTONOMY: Record<string, string> = {
    a0: 'A0, shadow only',
    a1: 'A1, may read',
    a2: 'A2, may propose',
    a3: 'A3, may act with approval',
    a4: 'A4, may act',
};

export default function AgentsIndex({ agents, runs, filter }: { agents: AgentCard[]; runs: RunCard[]; filter: string; can: { review: boolean } }) {
    const unreviewed = agents.reduce((total, a) => total + a.awaiting_review, 0);
    const agentName = (key: string) => agents.find((a) => a.key === key)?.name ?? humanKey(key);

    const filters: { key: string; label: string }[] = [
        { key: 'all', label: 'All runs' },
        { key: 'unreviewed', label: unreviewed > 0 ? `To read (${unreviewed})` : 'To read' },
        ...agents.map((a) => ({ key: a.key, label: a.name })),
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Agents" />

            <div className="flex flex-col gap-8 p-4 md:p-6">
                <PageHeader
                    title="Agents"
                    description="The AI workers HQ has, what each may do, and every run they have made. A paused agent does nothing; a shadow run is what an agent would have said, kept for a person to read."
                />

                <section className="grid gap-4 md:grid-cols-2">
                    {agents.length === 0 && (
                        <EmptyState
                            icon={Bot}
                            title="No agents registered"
                            description="Agents are registered by a deploy, never from this screen."
                            className="md:col-span-2"
                        />
                    )}
                    {agents.map((agent) => (
                        <article key={agent.key} className="bg-card flex flex-col gap-3 rounded-xl border p-4">
                            <div className="flex items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <h2 className="text-lg font-semibold">{agent.name}</h2>
                                    {agent.purpose && <p className="text-muted-foreground mt-1 text-sm">{agent.purpose}</p>}
                                </div>
                                <StatusBadge status={agent.status} tone={agent.status === 'active' ? 'ok' : 'neutral'} />
                            </div>

                            <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                                <dt className="text-muted-foreground">Autonomy</dt>
                                <dd>{AUTONOMY[agent.max_autonomy_level ?? 'a0'] ?? agent.max_autonomy_level?.toUpperCase()}</dd>
                                <dt className="text-muted-foreground">Acts as</dt>
                                <dd className="truncate">
                                    {agent.enabling_user ? (
                                        agent.enabling_user.name ? (
                                            `${agent.enabling_user.name} (user #${agent.enabling_user.user_id})`
                                        ) : (
                                            `user #${agent.enabling_user.user_id}`
                                        )
                                    ) : (
                                        <span className="text-muted-foreground">nobody, so it is inert</span>
                                    )}
                                </dd>
                                <dt className="text-muted-foreground">Model tier</dt>
                                <dd>{agent.model_tier ?? 'default'}</dd>
                                <dt className="text-muted-foreground">Runs</dt>
                                <dd className="flex flex-wrap items-center gap-2">
                                    {agent.runs}
                                    {agent.awaiting_review > 0 && (
                                        <Link href={`/agents?filter=unreviewed`} className="hover:underline">
                                            <StatusBadge tone="pending">{agent.awaiting_review} to read</StatusBadge>
                                        </Link>
                                    )}
                                </dd>
                            </dl>

                            <TechnicalDetail
                                summary={`${agent.allowed_tools.length} tools, all read-only · key ${agent.key}${agent.role_key ? ` · role ${agent.role_key}` : ''}`}
                            >
                                <ul className="grid gap-0.5 text-xs sm:grid-cols-2">
                                    {agent.allowed_tools.map((t) => (
                                        <li key={t} title={t}>
                                            {humanKey(t)}
                                        </li>
                                    ))}
                                </ul>
                            </TechnicalDetail>
                        </article>
                    ))}
                </section>

                {unreviewed > 0 && (
                    <div className="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
                        <strong>
                            {unreviewed} shadow {unreviewed === 1 ? 'run is' : 'runs are'} waiting to be read.
                        </strong>{' '}
                        A shadow run is not HQ&rsquo;s answer: it is what an agent would have said. The shadow period is these runs being reviewed,
                        and autonomy widens on that evidence rather than on a date.
                    </div>
                )}

                <section className="flex flex-col gap-3">
                    <SectionHeader
                        title="Runs"
                        aside={
                            <div className="flex flex-wrap gap-2">
                                {filters.map((f) => (
                                    <Button
                                        key={f.key}
                                        size="sm"
                                        variant={filter === f.key ? 'default' : 'outline'}
                                        onClick={() => router.get('/agents', f.key === 'all' ? {} : { filter: f.key }, { preserveState: true })}
                                    >
                                        {f.label}
                                    </Button>
                                ))}
                            </div>
                        }
                    />

                    {runs.length === 0 ? (
                        <EmptyState
                            icon={Bot}
                            title={filter === 'unreviewed' ? 'Nothing to read' : 'No runs yet'}
                            description={
                                filter === 'unreviewed' ? (
                                    'Every shadow run has been reviewed.'
                                ) : (
                                    <>
                                        A run is started from the console, never from this screen:{' '}
                                        <code className="font-mono text-xs">php artisan hq:agent:ask executive &quot;...&quot;</code>
                                    </>
                                )
                            }
                        />
                    ) : (
                        <div className="bg-card overflow-x-auto rounded-xl border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-muted-foreground text-left text-xs">
                                    <tr>
                                        <th className="px-3 py-2 font-medium">When</th>
                                        <th className="px-3 py-2 font-medium">Agent</th>
                                        <th className="px-3 py-2 font-medium">Question</th>
                                        <th className="px-3 py-2 font-medium">Outcome</th>
                                        <th className="px-3 py-2 text-right font-medium">Work</th>
                                        <th className="px-3 py-2 text-right font-medium">Cost</th>
                                        <th className="px-3 py-2 font-medium">Review</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {runs.map((run) => {
                                        const st = runStatus(run);
                                        return (
                                            <tr key={run.id} className="border-t align-top">
                                                <td
                                                    className="text-muted-foreground px-3 py-2 whitespace-nowrap"
                                                    title={formatDateTime(run.created_at)}
                                                >
                                                    {run.created_at ? timeAgo(run.created_at) : '-'}
                                                </td>
                                                <td className="px-3 py-2 whitespace-nowrap">{agentName(run.agent_key)}</td>
                                                <td className="max-w-md px-3 py-2">
                                                    <Link href={`/agents/runs/${run.id}`} className="hover:underline">
                                                        {truncate(run.question, 110)}
                                                    </Link>
                                                    {run.answer && (
                                                        <div className="text-muted-foreground mt-0.5 text-xs">{truncate(run.answer, 140)}</div>
                                                    )}
                                                </td>
                                                <td className="px-3 py-2">
                                                    <StatusBadge tone={st.tone}>{st.label}</StatusBadge>
                                                </td>
                                                <td className="text-muted-foreground px-3 py-2 text-right text-xs whitespace-nowrap tabular-nums">
                                                    {run.turns} {run.turns === 1 ? 'turn' : 'turns'}, {run.tool_calls_count}{' '}
                                                    {run.tool_calls_count === 1 ? 'tool' : 'tools'}
                                                    {run.duration_ms !== null && <div>{humanMillis(run.duration_ms)}</div>}
                                                </td>
                                                <td className="px-3 py-2 text-right text-xs whitespace-nowrap tabular-nums">
                                                    {humanUsd(run.cost_usd)}
                                                </td>
                                                <td className="px-3 py-2 text-xs whitespace-nowrap">
                                                    {run.reviewed_at ? (
                                                        <StatusBadge status={run.review_verdict} dot={false} />
                                                    ) : run.shadow ? (
                                                        <Link href={`/agents/runs/${run.id}`}>
                                                            <StatusBadge tone="pending">read it</StatusBadge>
                                                        </Link>
                                                    ) : (
                                                        <span className="text-muted-foreground">not needed</span>
                                                    )}
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>
            </div>
        </AppLayout>
    );
}
