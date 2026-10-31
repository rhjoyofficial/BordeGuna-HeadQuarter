import { Bar, DataTable, Note, Panel, Stat, StatGrid, WindowPicker, orDash, type DashboardWindow } from '@/components/observe/panels';
import { PageHeader } from '@/components/page-header';
import { InertNotice } from '@/components/ui/notice';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, humanKey, humanMillis } from '@/lib/format';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';

type AgentRow = { key: string; name: string; status: string; autonomy: string | null; has_enabling_user: boolean };
type RunsByAgent = { agent_key: string; total: number; by_status: Record<string, number>; ended_normally_percent: number | null };

type Props = {
    window: DashboardWindow;
    ai_runtime: boolean;
    agents: AgentRow[];
    runs_by_agent: RunsByAgent[];
    model_failures: { status: string; stop_reason: string | null; count: number }[];
    tool_failures: { status: string; outcome_class: string | null; count: number }[];
    latency_by_tier: { tier: string | null; calls: number; p50_ms: number; p95_ms: number }[];
    denials_by_policy: { policy: string | null; tool_key: string; count: number }[];
    stops: Record<string, number>;
    loop_bound_hits: number;
    approvals: { pending: number; past_deadline: number };
    shadow_runs_to_review: number;
    repeated_denial_alerts: { subject: string; first_seen_at: string; occurrences: number }[];
};

const breadcrumbs: BreadcrumbItem[] = [{ title: 'AI health', href: '/observe/ai-health' }];

const STATUSES = ['answered', 'refused', 'clarified', 'stopped', 'failed', 'running'];

export default function AiHealth(props: Props) {
    const { window: period } = props;
    const maxDenials = Math.max(0, ...props.denials_by_policy.map((d) => d.count));
    const totalRuns = props.runs_by_agent.reduce((sum, r) => sum + r.total, 0);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="AI health" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="AI health"
                    description="How the agents' runs ended, why model and tool calls failed, how long model calls took, what policy refused, and what waits for a person. Read-only."
                    actions={
                        <StatusBadge tone={props.ai_runtime ? 'warn' : 'neutral'}>
                            {props.ai_runtime ? 'AI runtime on' : 'AI runtime off'}
                        </StatusBadge>
                    }
                >
                    <WindowPicker period={period} path="/observe/ai-health" />
                </PageHeader>

                {!props.ai_runtime && (
                    <InertNotice
                        title="AI runtime off"
                        reason="No agent runs and no model is called, so every figure below is a past record."
                        gate="A person switches the AI runtime on. Agents still start paused at A0, where HQ only proposes and a person acts."
                    />
                )}

                <StatGrid>
                    <Stat label="Agent runs" value={totalRuns} detail={`since ${formatDateTime(period.since)}`} />
                    <Stat
                        label="Shadow runs to read"
                        value={props.shadow_runs_to_review}
                        detail={
                            <Link href="/agents?filter=unreviewed" className="inline-flex min-h-8 items-center underline touch:min-h-11">
                                Open the agents screen
                            </Link>
                        }
                    />
                    <Stat
                        label="Approvals waiting"
                        value={props.approvals.pending}
                        detail={`${props.approvals.past_deadline} past their deadline`}
                        badge={props.approvals.past_deadline > 0 ? <StatusBadge tone="warn">overdue</StatusBadge> : undefined}
                    />
                    <Stat
                        label="Runs stopped at the loop bound"
                        value={props.loop_bound_hits}
                        detail="The runtime ends a run that keeps calling tools."
                    />
                </StatGrid>

                <Panel title="Agents">
                    <DataTable
                        rowKey={(a) => a.key}
                        rows={props.agents}
                        empty="No agents are registered."
                        columns={[
                            { key: 'name', label: 'Agent', render: (a) => <span className="font-medium">{a.name}</span> },
                            { key: 'status', label: 'Status', render: (a) => <StatusBadge status={a.status} /> },
                            {
                                key: 'autonomy',
                                label: 'Autonomy ceiling',
                                render: (a) => <span className="font-mono text-xs uppercase">{orDash(a.autonomy)}</span>,
                            },
                            { key: 'enabling', label: 'Enabling user', render: (a) => (a.has_enabling_user ? 'named' : 'none') },
                        ]}
                    />
                </Panel>

                <Panel title="How runs ended, by agent" aside="Ended normally: answered, refused or asked for clarification.">
                    <DataTable
                        rowKey={(r) => r.agent_key}
                        rows={props.runs_by_agent}
                        empty="No agent ran in this period."
                        columns={[
                            { key: 'agent', label: 'Agent', render: (r) => <span className="font-medium">{r.agent_key}</span> },
                            { key: 'total', label: 'Runs', align: 'right', render: (r) => r.total },
                            ...STATUSES.map((s) => ({
                                key: s,
                                label: humanKey(s),
                                align: 'right' as const,
                                render: (r: RunsByAgent) => r.by_status[s] ?? 0,
                            })),
                            {
                                key: 'rate',
                                label: 'Ended normally',
                                align: 'right',
                                render: (r) => (r.ended_normally_percent === null ? '-' : `${r.ended_normally_percent}%`),
                            },
                        ]}
                    />
                </Panel>

                <div className="grid gap-6 xl:grid-cols-2">
                    <Panel title="Model calls that did not succeed">
                        <DataTable
                            rowKey={(r, i) => `${r.status}-${r.stop_reason}-${i}`}
                            rows={props.model_failures}
                            empty="Every model call in this period succeeded, or none was made."
                            columns={[
                                { key: 'status', label: 'Status', render: (r) => <StatusBadge status={r.status} /> },
                                { key: 'reason', label: 'Stop reason', render: (r) => orDash(r.stop_reason && humanKey(r.stop_reason)) },
                                { key: 'count', label: 'Calls', align: 'right', render: (r) => r.count },
                            ]}
                        />
                    </Panel>

                    <Panel title="Tool calls that did not succeed">
                        <DataTable
                            rowKey={(r, i) => `${r.status}-${r.outcome_class}-${i}`}
                            rows={props.tool_failures}
                            empty="No tool call failed or was refused in this period."
                            columns={[
                                { key: 'status', label: 'Status', render: (r) => <StatusBadge status={r.status} /> },
                                { key: 'class', label: 'Whose fault', render: (r) => orDash(r.outcome_class && humanKey(r.outcome_class)) },
                                { key: 'count', label: 'Calls', align: 'right', render: (r) => r.count },
                            ]}
                        />
                    </Panel>
                </div>

                <div className="grid gap-6 xl:grid-cols-2">
                    <Panel title="Model latency by tier">
                        <DataTable
                            rowKey={(r) => r.tier ?? 'unknown'}
                            rows={props.latency_by_tier}
                            empty="No timed model call in this period."
                            columns={[
                                { key: 'tier', label: 'Tier', render: (r) => orDash(r.tier) },
                                { key: 'calls', label: 'Calls', align: 'right', render: (r) => r.calls },
                                { key: 'p50', label: 'Median', align: 'right', render: (r) => humanMillis(r.p50_ms) },
                                { key: 'p95', label: '95th percentile', align: 'right', render: (r) => humanMillis(r.p95_ms) },
                            ]}
                        />
                    </Panel>

                    <Panel title="Refused by policy">
                        <DataTable
                            rowKey={(r, i) => `${r.policy}-${r.tool_key}-${i}`}
                            rows={props.denials_by_policy}
                            empty="Policy refused nothing in this period."
                            columns={[
                                {
                                    key: 'policy',
                                    label: 'Policy',
                                    render: (r) => r.policy ?? <span className="text-muted-foreground">tier baseline or a check before policy</span>,
                                },
                                { key: 'tool', label: 'Tool', render: (r) => <span className="font-mono text-xs">{r.tool_key}</span> },
                                { key: 'count', label: 'Refusals', align: 'right', render: (r) => r.count },
                                { key: 'bar', label: '', render: (r) => <Bar value={r.count} max={maxDenials} /> },
                            ]}
                        />
                    </Panel>
                </div>

                <div className="grid gap-6 xl:grid-cols-2">
                    <Panel title="Why the runtime stopped runs">
                        <DataTable
                            rowKey={(r) => r[0]}
                            rows={Object.entries(props.stops)}
                            empty="The runtime stopped no run in this period."
                            columns={[
                                { key: 'reason', label: 'Reason', render: (r) => humanKey(r[0]) },
                                { key: 'count', label: 'Runs', align: 'right', render: (r) => r[1] },
                            ]}
                        />
                    </Panel>

                    <Panel title="Repeated-denial alerts open now">
                        <DataTable
                            rowKey={(r, i) => `${r.subject}-${i}`}
                            rows={props.repeated_denial_alerts}
                            empty="No agent is being refused repeatedly."
                            columns={[
                                { key: 'subject', label: 'Alert', render: (r) => r.subject },
                                { key: 'since', label: 'Since', render: (r) => formatDateTime(r.first_seen_at) },
                                { key: 'seen', label: 'Checks', align: 'right', render: (r) => r.occurrences },
                            ]}
                        />
                    </Panel>
                </div>

                <Note>
                    Rows are read inside your business scope. Every figure comes from the run and call records themselves; nothing on this page
                    changes anything.
                </Note>
            </div>
        </AppLayout>
    );
}
