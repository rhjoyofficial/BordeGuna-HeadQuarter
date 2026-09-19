import { DataTable, Note, Panel, Stat, StatGrid, orDash } from '@/components/observe/panels';
import { JsonBlock, PageHeader, TechnicalDetail } from '@/components/page-header';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/format';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import { type ReactNode } from 'react';

/**
 * The autonomy evidence view (docs/80 C.3). Read-only: it shows what was measured, what a live grant
 * requires and the exact reason the two do not meet. It posts nothing and links to no action.
 */

type RunLineage = {
    run_id: number;
    run_uuid: string;
    suite_key: string;
    role_scope: string | null;
    prompt_version: string | null;
    model: string | null;
    run_status: string;
    run_cases_total: number;
    run_cases_passed: number;
    run_cases_failed: number;
    run_cases_skipped: number;
    run_cases_unmeasured: number;
    run_agreement_rate: number | null;
    triggered_by: string | null;
    finished_at: string | null;
};

type Source = RunLineage & {
    passed: number;
    failed: number;
    unmeasured: number;
    agreement_rate: number | null;
    result_ids: { passed: number[]; failed: number[]; unmeasured: number[] };
    stored: { passed: number; failed: number; rate: number | null } | null;
    stored_matches: boolean;
};

type GrantLineage = {
    grant_id: number;
    grant_uuid: string;
    grant_name: string;
    agent_key: string | null;
    max_level: string;
    min_agreement_rate: number | null;
    min_agreement_dimension: string | null;
    evaluation_suite_key: string | null;
    expires_at: string | null;
};

type Requirement = GrantLineage & {
    required_rate: number;
    measured_passed: number;
    measured_failed: number;
    measured_rate: number | null;
    met: boolean;
    reasons: string[];
};

type Failure = {
    result_id: number;
    case_key: string | null;
    severity: string | null;
    dimension: string;
    run_id: number;
    run_uuid: string | null;
    suite_key: string | null;
    prompt_version: string | null;
    finished_at: string | null;
    in_latest_run: boolean;
    failed_checks: string[];
    reason: string | null;
};

type Row = {
    dimension: string;
    label: string;
    blames: string | null;
    measurement: 'measured' | 'unmeasured_only' | 'never_measured';
    passed: number;
    failed: number;
    unmeasured: number;
    evidence_count: number;
    agreement_rate: number | null;
    required_rate: number | null;
    requirements: Requirement[];
    eligible: boolean;
    state: 'satisfied' | 'not_eligible';
    reasons: string[];
    sources: Source[];
    recent_failures: Failure[];
};

type SuiteRow = {
    suite_id: number;
    suite_key: string;
    name: string;
    role_scope: string | null;
    pass_threshold: number | null;
    finished_runs: number;
    unfinished_newer_run_ids: number[];
    latest_run: RunLineage | null;
};

type AgentEvidence = {
    key: string;
    name: string;
    status: string;
    role: string;
    configured_ceiling: string | null;
    current_stamp: string | null;
    ceiling: {
        level: string;
        bound_by: string;
        constraints: { source: string; level: string; detail: string }[];
        grant_ids: number[];
        why: string[];
    };
    live_grants: GrantLineage[];
    suites: SuiteRow[];
    rows: Row[];
};

type Props = {
    generated_at: string;
    environment: string;
    runtime_flag: { key: string; enabled: boolean; flag_id: number | null; environment: string | null };
    live_grants_total: number;
    agents: AgentEvidence[];
};

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Autonomy evidence', href: '/observe/autonomy-evidence' }];

const rate = (value: number | null | undefined) => (value === null || value === undefined ? '-' : value.toFixed(3));

const capital = (text: string) => text.charAt(0).toUpperCase() + text.slice(1);

function Mono({ children }: { children: ReactNode }) {
    return <span className="font-mono text-xs break-all">{children}</span>;
}

function Figure({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="min-w-0">
            <dt className="text-muted-foreground text-[11px] tracking-wide uppercase">{label}</dt>
            <dd className="text-sm font-medium tabular-nums">{children}</dd>
        </div>
    );
}

function Ids({ label, ids }: { label: string; ids: number[] }) {
    if (ids.length === 0) return null;
    return (
        <div className="text-xs">
            <span className="text-muted-foreground">{label}: </span>
            <span className="font-mono break-words">{ids.join(', ')}</span>
        </div>
    );
}

function MeasurementBadge({ row }: { row: Row }) {
    if (row.measurement === 'never_measured') return <StatusBadge tone="neutral">never measured</StatusBadge>;
    if (row.measurement === 'unmeasured_only') return <StatusBadge tone="warn">unmeasured only</StatusBadge>;
    return row.failed === 0 ? <StatusBadge tone="ok">all passed</StatusBadge> : <StatusBadge tone="danger">{row.failed} failed</StatusBadge>;
}

function SourceBlock({ source }: { source: Source }) {
    return (
        <div className="bg-muted/30 flex min-w-0 flex-col gap-1 rounded-md border p-3">
            <div className="flex flex-wrap items-center gap-2 text-sm font-medium">
                Run {source.run_id} of <Mono>{source.suite_key}</Mono>
                <StatusBadge status={source.run_status} />
            </div>
            <div className="text-muted-foreground text-xs">
                {source.passed} passed, {source.failed} failed, {source.unmeasured} unmeasured in this row. Rate {rate(source.agreement_rate)}.
                Finished {formatDateTime(source.finished_at)}.
            </div>
            <div className="text-muted-foreground text-xs">
                Role scope {source.role_scope ?? 'every role'}; prompt stamp <Mono>{orDash(source.prompt_version)}</Mono>; model{' '}
                <Mono>{orDash(source.model)}</Mono>; run <Mono>{source.run_uuid}</Mono>.
            </div>
            <div className="text-muted-foreground text-xs">
                The whole run: {source.run_cases_passed} of {source.run_cases_total} passed, {source.run_cases_failed} failed,{' '}
                {source.run_cases_unmeasured} unmeasured, {source.run_cases_skipped} skipped for another role.
            </div>
            <div className="text-muted-foreground text-xs">
                Stored on the run:{' '}
                {source.stored
                    ? `${source.stored.passed} passed, ${source.stored.failed} failed, rate ${rate(source.stored.rate)}`
                    : 'nothing for this row'}
                {source.stored_matches ? ' (matches the recount)' : ' (DOES NOT match the recount)'}.
            </div>
            <Ids label="Result ids passed" ids={source.result_ids.passed} />
            <Ids label="Result ids failed" ids={source.result_ids.failed} />
            <Ids label="Result ids unmeasured" ids={source.result_ids.unmeasured} />
        </div>
    );
}

function RowCard({ row }: { row: Row }) {
    return (
        <div className="bg-card flex min-w-0 flex-col gap-3 rounded-xl border p-4">
            <div className="flex flex-wrap items-center gap-2">
                <h3 className="text-sm font-semibold">{capital(row.label)}</h3>
                {row.eligible ? (
                    <StatusBadge tone="ok">evidence satisfies the requirement</StatusBadge>
                ) : (
                    <StatusBadge tone="warn">not eligible</StatusBadge>
                )}
                <MeasurementBadge row={row} />
            </div>
            {row.blames && <p className="text-muted-foreground text-xs">When this fails, look at {row.blames}.</p>}

            <dl className="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6">
                <Figure label="Passed">{row.passed}</Figure>
                <Figure label="Failed">{row.failed}</Figure>
                <Figure label="Unmeasured">{row.unmeasured}</Figure>
                <Figure label="Evidence count">{row.evidence_count}</Figure>
                <Figure label="Agreement rate">{rate(row.agreement_rate)}</Figure>
                <Figure label="Required rate">{row.required_rate === null ? 'none configured' : rate(row.required_rate)}</Figure>
            </dl>

            {row.reasons.length > 0 && (
                <div>
                    <div className="text-xs font-medium">Why it is not eligible</div>
                    <ul className="text-muted-foreground mt-1 list-disc space-y-0.5 pl-5 text-xs">
                        {row.reasons.map((reason) => (
                            <li key={reason}>{capital(reason)}.</li>
                        ))}
                    </ul>
                </div>
            )}

            <TechnicalDetail summary={`Where these numbers come from (${row.sources.length} run(s), ${row.requirements.length} requirement(s))`}>
                <div className="flex flex-col gap-2">
                    {row.sources.length === 0 && <p className="text-muted-foreground text-xs">No finished run holds a result for this row.</p>}
                    {row.sources.map((source) => (
                        <SourceBlock key={source.run_id} source={source} />
                    ))}
                    {row.requirements.map((req) => (
                        <div key={req.grant_id} className="bg-muted/30 rounded-md border p-3 text-xs">
                            <div className="text-sm font-medium">
                                Grant {req.grant_id}: {req.grant_name}{' '}
                                {req.met ? <StatusBadge tone="ok">met</StatusBadge> : <StatusBadge tone="warn">not met</StatusBadge>}
                            </div>
                            <div className="text-muted-foreground">
                                Requires {rate(req.required_rate)} for level {req.max_level.toUpperCase()}
                                {req.evaluation_suite_key ? `, from suite ${req.evaluation_suite_key}` : ''}. Measured {rate(req.measured_rate)} (
                                {req.measured_passed} of {req.measured_passed + req.measured_failed}). Grant <Mono>{req.grant_uuid}</Mono>.
                            </div>
                        </div>
                    ))}
                </div>
            </TechnicalDetail>

            <TechnicalDetail summary={`Recent failures (${row.recent_failures.length})`}>
                <DataTable
                    rowKey={(f) => String(f.result_id)}
                    rows={row.recent_failures}
                    empty="No failed result is recorded for this row."
                    columns={[
                        { key: 'case', label: 'Case', render: (f) => <Mono>{orDash(f.case_key)}</Mono> },
                        { key: 'checks', label: 'Check that failed', render: (f) => (f.failed_checks.length ? f.failed_checks.join(', ') : '-') },
                        {
                            key: 'run',
                            label: 'Run',
                            render: (f) => (
                                <span className="whitespace-nowrap">
                                    {f.run_id} {f.in_latest_run ? '(latest)' : '(earlier)'}
                                </span>
                            ),
                        },
                        { key: 'suite', label: 'Suite', render: (f) => <Mono>{orDash(f.suite_key)}</Mono> },
                        { key: 'date', label: 'Date', render: (f) => <span className="whitespace-nowrap">{formatDateTime(f.finished_at)}</span> },
                        { key: 'result', label: 'Result id', align: 'right', render: (f) => f.result_id },
                        { key: 'why', label: 'Why', render: (f) => <span className="text-xs">{orDash(f.reason)}</span> },
                    ]}
                />
            </TechnicalDetail>
        </div>
    );
}

function AgentPanel({ agent }: { agent: AgentEvidence }) {
    return (
        <Panel
            title={
                <span className="flex flex-wrap items-center gap-2">
                    {agent.name} <StatusBadge status={agent.status} />
                    <StatusBadge tone={agent.ceiling.level === 'a0' ? 'neutral' : 'warn'}>
                        ceiling now {agent.ceiling.level.toUpperCase()}
                    </StatusBadge>
                </span>
            }
        >
            <div className="bg-card flex min-w-0 flex-col gap-2 rounded-xl border p-4">
                <div className="text-sm font-medium">What the agent may do without a person, right now</div>
                <ul className="text-muted-foreground list-disc space-y-0.5 pl-5 text-sm">
                    {agent.ceiling.why.map((line) => (
                        <li key={line}>{line}</li>
                    ))}
                </ul>
                <TechnicalDetail summary="The resolver's answer, the prompt stamp and the suites">
                    <div className="flex flex-col gap-2 text-xs">
                        <div>
                            Role <Mono>{agent.role}</Mono>; the agent's own configured ceiling <Mono>{orDash(agent.configured_ceiling)}</Mono>; prompt
                            stamp today <Mono>{orDash(agent.current_stamp)}</Mono>.
                        </div>
                        <DataTable
                            rowKey={(s) => s.suite_key}
                            rows={agent.suites}
                            empty="No active evaluation suite applies to this agent's role, so nothing can be measured."
                            columns={[
                                { key: 'suite', label: 'Suite', render: (s) => <Mono>{s.suite_key}</Mono> },
                                { key: 'scope', label: 'Role scope', render: (s) => s.role_scope ?? 'every role' },
                                { key: 'pass', label: 'Suite pass mark', align: 'right', render: (s) => rate(s.pass_threshold) },
                                { key: 'runs', label: 'Finished runs', align: 'right', render: (s) => s.finished_runs },
                                {
                                    key: 'latest',
                                    label: 'Latest finished run',
                                    render: (s) =>
                                        s.latest_run ? (
                                            <span className="whitespace-nowrap">
                                                {s.latest_run.run_id} <StatusBadge status={s.latest_run.run_status} />{' '}
                                                {formatDateTime(s.latest_run.finished_at)}
                                            </span>
                                        ) : (
                                            'never run'
                                        ),
                                },
                                {
                                    key: 'unfinished',
                                    label: 'Newer, never finished',
                                    render: (s) => (s.unfinished_newer_run_ids.length ? s.unfinished_newer_run_ids.join(', ') : '-'),
                                },
                            ]}
                        />
                        <JsonBlock value={{ resolver: agent.ceiling, live_grants: agent.live_grants }} />
                    </div>
                </TechnicalDetail>
            </div>

            <div className="grid gap-3 2xl:grid-cols-2">
                {agent.rows.map((row) => (
                    <RowCard key={row.dimension} row={row} />
                ))}
            </div>
        </Panel>
    );
}

export default function AutonomyEvidencePage(props: Props) {
    const rows = props.agents.flatMap((a) => a.rows);
    const satisfied = rows.filter((r) => r.eligible).length;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Autonomy evidence" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Autonomy evidence"
                    description="What each agent has been measured on, what a grant would require, and the exact reason the evidence does not reach it. Nothing here grants, widens or changes anything: it only reads and recounts."
                />

                <StatGrid>
                    <Stat label="Agents" value={props.agents.length} detail={`${props.agents.filter((a) => a.status === 'active').length} active`} />
                    <Stat
                        label="Live autonomy grants"
                        value={props.live_grants_total}
                        badge={
                            props.live_grants_total > 0 ? <StatusBadge tone="warn">active</StatusBadge> : <StatusBadge tone="ok">none</StatusBadge>
                        }
                    />
                    <Stat
                        label="AI runtime"
                        value={props.runtime_flag.enabled ? 'On' : 'Off'}
                        detail={
                            props.runtime_flag.flag_id === null
                                ? `no ${props.runtime_flag.key} flag row, which reads as off`
                                : `${props.runtime_flag.key}, flag row ${props.runtime_flag.flag_id} (${props.environment})`
                        }
                    />
                    <Stat
                        label="Rows where evidence satisfies a requirement"
                        value={`${satisfied} of ${rows.length}`}
                        detail={`read ${formatDateTime(props.generated_at)}`}
                    />
                </StatGrid>

                {props.agents.length === 0 && (
                    <div className="text-muted-foreground rounded-xl border border-dashed px-4 py-6 text-center text-sm">
                        No agents are registered.
                    </div>
                )}

                {props.agents.map((agent) => (
                    <AgentPanel key={agent.key} agent={agent} />
                ))}

                <Note>
                    How to read it. Evidence is the latest finished run of each suite that applies to the agent's role. A result the model provider
                    refused is unmeasured: it is neither a pass nor a failure, and a run holding one is never green. A dimension nothing measured does
                    not borrow the overall rate. A required rate exists only on a live autonomy grant; where none names one, the row reads "no
                    requirement configured" and "not eligible". Every figure folds open to the run ids and result ids it was counted from. Evaluation
                    evidence is group-wide.
                </Note>
            </div>
        </AppLayout>
    );
}
