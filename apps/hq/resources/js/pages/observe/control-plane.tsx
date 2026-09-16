import { Bar, DataTable, Note, Panel, Stat, StatGrid, WindowPicker, orDash, type DashboardWindow } from '@/components/observe/panels';
import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, humanKey } from '@/lib/format';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';

type Counted = { value: string | null; count: number };

type Props = {
    window: DashboardWindow;
    calls_total: number;
    calls_by_tier: { risk_tier: string | null; calls: number; succeeded: number; denied: number; failed: number }[];
    policy_outcomes: Counted[];
    calls_by_caller: Counted[];
    approval_decisions: { status: string; count: number; p50_minutes: number; p95_minutes: number }[];
    approvals_pending: number;
    tools_disabled: { key: string; reason: string | null; disabled_at: string | null }[];
    flags: { key: string; environment: string; enabled: boolean; description: string | null }[];
    agents: { key: string; name: string; status: string; autonomy: string | null }[];
    active_grants: number;
    open_shadow_periods: { uuid: string; agent_key: string; opened_at: string; planned_end_at: string }[];
};

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Control plane', href: '/observe/control-plane' }];

function CountTable({ rows, label, empty }: { rows: Counted[]; label: string; empty: string }) {
    const max = Math.max(0, ...rows.map((r) => r.count));
    return (
        <DataTable
            rowKey={(r, i) => `${r.value}-${i}`}
            rows={rows}
            empty={empty}
            columns={[
                { key: 'value', label, render: (r) => orDash(r.value && humanKey(r.value)) },
                { key: 'count', label: 'Calls', align: 'right', render: (r) => r.count },
                { key: 'bar', label: '', render: (r) => <Bar value={r.count} max={max} /> },
            ]}
        />
    );
}

export default function ControlPlane(props: Props) {
    const { window: period } = props;
    const activeAgents = props.agents.filter((a) => a.status === 'active').length;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Control plane" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Control plane"
                    description="What the Tool Gateway was asked to do, what policy decided, how long approvals take, and where every switch stands. Nothing here changes a switch: each has its own screen, or none."
                >
                    <WindowPicker period={period} path="/observe/control-plane" />
                </PageHeader>

                <StatGrid>
                    <Stat label="Tool calls" value={props.calls_total} detail={`since ${formatDateTime(period.since)}`} />
                    <Stat
                        label="Approvals waiting"
                        value={props.approvals_pending}
                        detail={
                            <Link href="/approvals" className="underline">
                                Open approvals
                            </Link>
                        }
                    />
                    <Stat
                        label="Autonomy grants"
                        value={props.active_grants}
                        badge={props.active_grants > 0 ? <StatusBadge tone="warn">active</StatusBadge> : <StatusBadge tone="ok">none</StatusBadge>}
                    />
                    <Stat
                        label="Agents active"
                        value={`${activeAgents} of ${props.agents.length}`}
                        detail={`${props.open_shadow_periods.length} shadow period(s) open`}
                    />
                </StatGrid>

                <Panel title="Tool calls by risk tier">
                    <DataTable
                        rowKey={(r, i) => `${r.risk_tier}-${i}`}
                        rows={props.calls_by_tier}
                        empty="The gateway received no call in this period."
                        columns={[
                            { key: 'tier', label: 'Tier', render: (r) => <span className="font-mono text-xs uppercase">{orDash(r.risk_tier)}</span> },
                            { key: 'calls', label: 'Calls', align: 'right', render: (r) => r.calls },
                            { key: 'ok', label: 'Succeeded', align: 'right', render: (r) => r.succeeded },
                            { key: 'denied', label: 'Refused', align: 'right', render: (r) => r.denied },
                            { key: 'failed', label: 'Failed', align: 'right', render: (r) => r.failed },
                        ]}
                    />
                </Panel>

                <div className="grid gap-6 xl:grid-cols-2">
                    <Panel title="What policy decided">
                        <CountTable rows={props.policy_outcomes} label="Outcome" empty="No call reached policy in this period." />
                    </Panel>
                    <Panel title="Who called">
                        <CountTable rows={props.calls_by_caller} label="Caller" empty="No call in this period." />
                    </Panel>
                </div>

                <Panel title="How long approval decisions took">
                    <DataTable
                        rowKey={(r) => r.status}
                        rows={props.approval_decisions}
                        empty="No approval was decided in this period."
                        columns={[
                            { key: 'status', label: 'Decision', render: (r) => <StatusBadge status={r.status} /> },
                            { key: 'count', label: 'Approvals', align: 'right', render: (r) => r.count },
                            { key: 'p50', label: 'Median', align: 'right', render: (r) => `${r.p50_minutes} min` },
                            { key: 'p95', label: '95th percentile', align: 'right', render: (r) => `${r.p95_minutes} min` },
                        ]}
                    />
                </Panel>

                <div className="grid gap-6 xl:grid-cols-2">
                    <Panel
                        title="Tools switched off"
                        aside={
                            <Link href="/tools?state=disabled" className="underline">
                                Tools screen
                            </Link>
                        }
                    >
                        <DataTable
                            rowKey={(r) => r.key}
                            rows={props.tools_disabled}
                            empty="Every registered tool is switched on."
                            columns={[
                                { key: 'key', label: 'Tool', render: (r) => <span className="font-mono text-xs">{r.key}</span> },
                                { key: 'reason', label: 'Why', render: (r) => orDash(r.reason) },
                                { key: 'at', label: 'Since', render: (r) => formatDateTime(r.disabled_at) },
                            ]}
                        />
                    </Panel>

                    <Panel title="Agents">
                        <DataTable
                            rowKey={(r) => r.key}
                            rows={props.agents}
                            empty="No agents are registered."
                            columns={[
                                { key: 'name', label: 'Agent', render: (r) => r.name },
                                { key: 'status', label: 'Status', render: (r) => <StatusBadge status={r.status} /> },
                                {
                                    key: 'autonomy',
                                    label: 'Ceiling',
                                    render: (r) => <span className="font-mono text-xs uppercase">{orDash(r.autonomy)}</span>,
                                },
                            ]}
                        />
                    </Panel>
                </div>

                <Panel
                    title="Feature flags"
                    aside={
                        <Link href="/flags" className="underline">
                            Flags screen
                        </Link>
                    }
                >
                    <DataTable
                        rowKey={(r) => `${r.key}-${r.environment}`}
                        rows={props.flags}
                        empty="No feature flag rows exist; every flag reads as off."
                        columns={[
                            { key: 'key', label: 'Flag', render: (r) => <span className="font-mono text-xs">{r.key}</span> },
                            { key: 'env', label: 'Environment', render: (r) => r.environment },
                            { key: 'state', label: 'State', render: (r) => <StatusBadge status={r.enabled ? 'on' : 'off'} /> },
                            { key: 'about', label: 'About', render: (r) => orDash(r.description) },
                        ]}
                    />
                </Panel>

                <Panel title="Open shadow periods">
                    <DataTable
                        rowKey={(r) => r.uuid}
                        rows={props.open_shadow_periods}
                        empty="No A0 shadow period is open."
                        columns={[
                            { key: 'agent', label: 'Agent', render: (r) => r.agent_key },
                            { key: 'opened', label: 'Opened', render: (r) => formatDateTime(r.opened_at) },
                            { key: 'ends', label: 'Planned end', render: (r) => formatDateTime(r.planned_end_at) },
                        ]}
                    />
                </Panel>

                <Note>Tool-call and approval rows are read inside your business scope; the switches are group-wide.</Note>
            </div>
        </AppLayout>
    );
}
