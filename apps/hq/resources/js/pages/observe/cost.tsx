import { Bar, DataTable, Note, Panel, Stat, StatGrid, WindowPicker, orDash, type DashboardWindow } from '@/components/observe/panels';
import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, humanUsd } from '@/lib/format';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';

type Spend = { runs: number; usd: number };

type Props = {
    window: DashboardWindow;
    totals: { runs: number; usd: number; tokens_in: number; tokens_out: number; per_run_usd: number | null };
    by_day: (Spend & { day: string })[];
    by_model: (Spend & { provider: string | null; model: string | null; tokens_in: number; tokens_out: number })[];
    by_agent: (Spend & { agent_key: string | null })[];
    by_business: (Spend & { business: string | null })[];
    by_cost_source: (Spend & { cost_source: string | null })[];
    unit_costs: {
        agent_runs: number;
        per_agent_run_usd: number | null;
        conversations: number;
        per_conversation_usd: number | null;
        briefings: number;
        per_briefing_usd: number | null;
    };
    budget: {
        scope: string;
        spent_usd: number;
        cap_usd: number | null;
        share_percent: number;
        notify: boolean;
        downgrade: boolean;
        refuse: boolean;
        projected_month_usd: number;
    };
    degradations: { from: string; to: string | null; count: number }[];
};

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Cost', href: '/observe/cost' }];

function spendColumns<T extends Spend>(max: number) {
    return [
        { key: 'runs', label: 'Calls', align: 'right' as const, render: (r: T) => r.runs },
        { key: 'usd', label: 'Spend', align: 'right' as const, render: (r: T) => humanUsd(r.usd) },
        { key: 'bar', label: '', render: (r: T) => <Bar value={r.usd} max={max} /> },
    ];
}

export default function Cost(props: Props) {
    const { window: period, totals, budget, unit_costs: unit } = props;
    const maxOf = (rows: Spend[]) => Math.max(0, ...rows.map((r) => r.usd));
    const budgetTone = budget.refuse ? 'danger' : budget.downgrade ? 'warn' : budget.notify ? 'pending' : 'ok';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Cost" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Cost"
                    description="What model calls cost, where it went, and how fast the monthly budget is burning. A call counts its reported cost when the provider reported one and its estimate otherwise, exactly as the budget does."
                >
                    <WindowPicker period={period} path="/observe/cost" />
                </PageHeader>

                <StatGrid>
                    <Stat
                        label="Spend in this period"
                        value={humanUsd(totals.usd)}
                        detail={`${totals.runs} model calls since ${formatDateTime(period.since)}`}
                    />
                    <Stat label="Per model call" value={orDash(totals.per_run_usd === null ? null : humanUsd(totals.per_run_usd))} />
                    <Stat
                        label="Per agent run"
                        value={orDash(unit.per_agent_run_usd === null ? null : humanUsd(unit.per_agent_run_usd))}
                        detail={`${unit.agent_runs} agent runs`}
                    />
                    <Stat
                        label="Per conversation"
                        value={orDash(unit.per_conversation_usd === null ? null : humanUsd(unit.per_conversation_usd))}
                        detail={`${unit.conversations} conversations with model work`}
                    />
                </StatGrid>

                <Panel title="Monthly budget" aside={<StatusBadge tone={budgetTone}>{budget.share_percent}% of the cap</StatusBadge>}>
                    <StatGrid>
                        <Stat label={`Spent this month (${budget.scope})`} value={humanUsd(budget.spent_usd)} />
                        <Stat
                            label="Cap"
                            value={budget.cap_usd === null ? 'none' : humanUsd(budget.cap_usd)}
                            detail="A soft cap: at 100% HQ uses a cheaper tier."
                        />
                        <Stat label="Month at this pace" value={humanUsd(budget.projected_month_usd)} />
                        <Stat
                            label="Per daily briefing"
                            value={orDash(unit.per_briefing_usd === null ? null : humanUsd(unit.per_briefing_usd))}
                            detail={
                                unit.briefings === 0
                                    ? 'No briefing has been composed yet; it needs the model and comes after the gates.'
                                    : `${unit.briefings} briefing(s) composed`
                            }
                        />
                    </StatGrid>
                </Panel>

                <Panel title="Spend by day (Dhaka)">
                    <DataTable
                        rowKey={(r) => r.day}
                        rows={props.by_day}
                        empty="No model call in this period."
                        columns={[{ key: 'day', label: 'Day', render: (r) => r.day }, ...spendColumns(maxOf(props.by_day))]}
                    />
                </Panel>

                <div className="grid gap-6 xl:grid-cols-2">
                    <Panel title="By provider and model">
                        <DataTable
                            rowKey={(r, i) => `${r.provider}-${r.model}-${i}`}
                            rows={props.by_model}
                            empty="No model call in this period."
                            columns={[
                                {
                                    key: 'model',
                                    label: 'Model',
                                    render: (r) => (
                                        <span className="font-mono text-xs">
                                            {orDash(r.provider)} / {orDash(r.model)}
                                        </span>
                                    ),
                                },
                                { key: 'tokens', label: 'Tokens in / out', align: 'right', render: (r) => `${r.tokens_in} / ${r.tokens_out}` },
                                ...spendColumns(maxOf(props.by_model)),
                            ]}
                        />
                    </Panel>

                    <Panel title="By agent">
                        <DataTable
                            rowKey={(r, i) => `${r.agent_key}-${i}`}
                            rows={props.by_agent}
                            empty="No model call in this period."
                            columns={[
                                {
                                    key: 'agent',
                                    label: 'Agent',
                                    render: (r) => r.agent_key ?? <span className="text-muted-foreground">no agent (probes, checks)</span>,
                                },
                                ...spendColumns(maxOf(props.by_agent)),
                            ]}
                        />
                    </Panel>
                </div>

                <div className="grid gap-6 xl:grid-cols-2">
                    <Panel title="By business">
                        <DataTable
                            rowKey={(r, i) => `${r.business}-${i}`}
                            rows={props.by_business}
                            empty="No model call in this period."
                            columns={[
                                {
                                    key: 'business',
                                    label: 'Business',
                                    render: (r) => r.business ?? <span className="text-muted-foreground">group-wide</span>,
                                },
                                ...spendColumns(maxOf(props.by_business)),
                            ]}
                        />
                    </Panel>

                    <Panel title="Reported or estimated">
                        <DataTable
                            rowKey={(r, i) => `${r.cost_source}-${i}`}
                            rows={props.by_cost_source}
                            empty="No model call in this period."
                            columns={[
                                { key: 'source', label: 'Cost from', render: (r) => r.cost_source ?? 'estimate from the price table' },
                                ...spendColumns(maxOf(props.by_cost_source)),
                            ]}
                        />
                    </Panel>
                </div>

                <Panel title="Calls that ran on a cheaper tier than asked">
                    <DataTable
                        rowKey={(r, i) => `${r.from}-${r.to}-${i}`}
                        rows={props.degradations}
                        empty="Nothing was degraded in this period."
                        columns={[
                            { key: 'from', label: 'Asked for', render: (r) => r.from },
                            { key: 'to', label: 'Ran on', render: (r) => orDash(r.to) },
                            { key: 'count', label: 'Calls', align: 'right', render: (r) => r.count },
                        ]}
                    />
                </Panel>

                <Note>Rows are read inside your business scope; a model call that belongs to no business shows only to a group-wide viewer.</Note>
            </div>
        </AppLayout>
    );
}
