import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';

type AgentCard = {
    key: string;
    name: string;
    purpose: string | null;
    status: string;
    role_key: string | null;
    model_tier: string | null;
    max_autonomy_level: string | null;
    allowed_tools: string[];
    enabling_user: string | null;
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

function statusTone(status: string, shadow: boolean): string {
    if (status === 'failed') return 'bg-red-100 text-red-900 dark:bg-red-950 dark:text-red-200';
    if (status === 'stopped' || status === 'refused') return 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-200';
    if (shadow) return 'bg-yellow-100 text-yellow-900 dark:bg-yellow-950 dark:text-yellow-200';
    return 'bg-emerald-100 text-emerald-900 dark:bg-emerald-950 dark:text-emerald-200';
}

export default function AgentsIndex({ agents, runs, filter }: { agents: AgentCard[]; runs: RunCard[]; filter: string; can: { review: boolean } }) {
    const unreviewed = agents.reduce((total, a) => total + a.awaiting_review, 0);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Agents" />

            <div className="flex flex-col gap-6 p-4">
                <div className="grid gap-4 md:grid-cols-2">
                    {agents.map((agent) => (
                        <div key={agent.key} className="rounded-xl border p-4">
                            <div className="flex items-start justify-between gap-3">
                                <div>
                                    <h2 className="text-lg font-semibold">{agent.name}</h2>
                                    <p className="text-muted-foreground text-xs">{agent.key}</p>
                                </div>
                                <span
                                    className={`rounded-full px-2 py-0.5 text-xs ${
                                        agent.status === 'active'
                                            ? 'bg-emerald-100 text-emerald-900 dark:bg-emerald-950 dark:text-emerald-200'
                                            : 'bg-neutral-200 text-neutral-800 dark:bg-neutral-800 dark:text-neutral-200'
                                    }`}
                                >
                                    {agent.status}
                                </span>
                            </div>

                            {agent.purpose && <p className="text-muted-foreground mt-2 text-sm">{agent.purpose}</p>}

                            <dl className="mt-4 grid grid-cols-2 gap-2 text-sm">
                                <dt className="text-muted-foreground">Autonomy</dt>
                                <dd className="font-mono">{agent.max_autonomy_level?.toUpperCase() ?? 'A0'}</dd>
                                <dt className="text-muted-foreground">Model tier</dt>
                                <dd>{agent.model_tier ?? 'default'}</dd>
                                <dt className="text-muted-foreground">Acts as</dt>
                                <dd className="truncate">{agent.enabling_user ?? 'nobody, so it is inert'}</dd>
                                <dt className="text-muted-foreground">Runs</dt>
                                <dd>
                                    {agent.runs}
                                    {agent.awaiting_review > 0 && (
                                        <span className="ml-2 rounded bg-yellow-100 px-1.5 py-0.5 text-xs text-yellow-900 dark:bg-yellow-950 dark:text-yellow-200">
                                            {agent.awaiting_review} to review
                                        </span>
                                    )}
                                </dd>
                            </dl>

                            <details className="mt-3">
                                <summary className="text-muted-foreground cursor-pointer text-xs">
                                    {agent.allowed_tools.length} tools, all read-only
                                </summary>
                                <ul className="mt-2 space-y-0.5 font-mono text-xs">
                                    {agent.allowed_tools.map((t) => (
                                        <li key={t}>{t}</li>
                                    ))}
                                </ul>
                            </details>
                        </div>
                    ))}
                </div>

                {unreviewed > 0 && (
                    <div className="rounded-xl border border-yellow-300 bg-yellow-50 p-4 text-sm dark:border-yellow-900 dark:bg-yellow-950/40">
                        <strong>{unreviewed} shadow runs are waiting to be read.</strong> A shadow run is not HQ&rsquo;s answer: it is what an agent
                        would have said. The seven-day shadow period is these runs being reviewed, and autonomy widens on that evidence rather than on
                        a date.
                    </div>
                )}

                <div>
                    <div className="mb-3 flex flex-wrap items-center gap-2">
                        <h2 className="mr-2 text-lg font-semibold">Runs</h2>
                        {['all', 'unreviewed', ...agents.map((a) => a.key)].map((f) => (
                            <Button
                                key={f}
                                size="sm"
                                variant={filter === f ? 'default' : 'outline'}
                                onClick={() => router.get('/agents', f === 'all' ? {} : { filter: f }, { preserveState: true })}
                            >
                                {f}
                            </Button>
                        ))}
                    </div>

                    {runs.length === 0 ? (
                        <p className="text-muted-foreground rounded-xl border p-6 text-sm">
                            No runs yet. Ask one a question with <code className="font-mono">php artisan hq:agent:ask executive &quot;…&quot;</code>.
                        </p>
                    ) : (
                        <div className="overflow-x-auto rounded-xl border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        <th className="p-2 font-medium">When</th>
                                        <th className="p-2 font-medium">Agent</th>
                                        <th className="p-2 font-medium">Question</th>
                                        <th className="p-2 font-medium">Status</th>
                                        <th className="p-2 font-medium">Turns</th>
                                        <th className="p-2 font-medium">Tools</th>
                                        <th className="p-2 font-medium">Cost</th>
                                        <th className="p-2 font-medium">Review</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {runs.map((run) => (
                                        <tr key={run.id} className="border-t">
                                            <td className="text-muted-foreground p-2 whitespace-nowrap">
                                                {run.created_at ? new Date(run.created_at).toLocaleString() : '-'}
                                            </td>
                                            <td className="p-2 font-mono text-xs">{run.agent_key}</td>
                                            <td className="max-w-sm p-2">
                                                <Link href={`/agents/runs/${run.id}`} className="hover:underline">
                                                    {run.question.length > 90 ? `${run.question.slice(0, 90)}…` : run.question}
                                                </Link>
                                            </td>
                                            <td className="p-2">
                                                <span className={`rounded px-1.5 py-0.5 text-xs ${statusTone(run.status, run.shadow)}`}>
                                                    {run.shadow ? 'shadow' : run.status}
                                                    {run.stop_reason ? ` · ${run.stop_reason}` : ''}
                                                </span>
                                            </td>
                                            <td className="p-2">{run.turns}</td>
                                            <td className="p-2">{run.tool_calls_count}</td>
                                            <td className="p-2 font-mono text-xs">${run.cost_usd.toFixed(4)}</td>
                                            <td className="p-2 text-xs">
                                                {run.reviewed_at ? (
                                                    <span className="text-muted-foreground">{run.review_verdict}</span>
                                                ) : (
                                                    <Link href={`/agents/runs/${run.id}`} className="underline">
                                                        read it
                                                    </Link>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
