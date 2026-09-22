import { DataTable, Note, Panel, Stat, StatGrid, orDash } from '@/components/observe/panels';
import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, humanKey } from '@/lib/format';
import { formatNumber, useT, type Translator } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';

type AgentRow = {
    key: string;
    name: string;
    role: string;
    status: string;
    autonomy: string | null;
    enabling_user: string | null;
    forbidden: string[];
    tools: string[];
    shadow: { id: number; opened_at: string | null; planned_end_at: string | null; opened_by: string | null } | null;
};

type EvidenceRow = {
    agent_key: string;
    suite: string;
    run_id: number | null;
    status: string | null;
    cases_total: number | null;
    cases_passed: number | null;
    cases_failed: number | null;
    unmeasured: number | null;
    model: string | null;
    triggered_by: string | null;
    finished_at: string | null;
};

type ProviderRow = {
    key: string;
    credential: { id: number; status: string } | null;
    last_check: { ok: boolean; class: string | null; source: string; at: string } | null;
    models: { tier: string; model: string; on: boolean; priority: number }[];
};

type ToolRow = {
    key: string;
    title: string;
    risk: string;
    writes: boolean;
    customer_facing: boolean;
    agent_executable: boolean;
    human_only_forever: boolean;
    enabled: boolean;
    held_by: string[];
};

type Props = {
    switches: {
        ai_runtime: boolean;
        meta_inbound: boolean;
        messaging_providers: string[];
        take_order_stores: string[];
        active_grants: number;
        consent_events: number;
        active_evidence_policy: { version: string; name: string; activated_at: string | null } | null;
        open_shadow_periods: number;
    };
    agents: AgentRow[];
    role_policy: { version: string; kinds: string[]; roles: { role: string; forbidden: string[] }[] };
    shadow: { agent_key: string; may_open: boolean; refusals: string[] }[];
    evidence: EvidenceRow[];
    model: { serving_state: string; serving: string | null; describe: string; reasoning_effort: string | null; providers: ProviderRow[] };
    readiness: {
        total: number;
        verified: number;
        would_attempt: number;
        unsupported: number;
        incidents: { target: string; provider: string; state: string; reason: string }[];
    } | null;
    capability: { tools: ToolRow[] };
    reconciliation: {
        uncertain_calls: number;
        latest_uncertain: { id: number; tool_key: string; at: string | null; outcome_class: string | null }[];
        dead_events: number;
        open_alerts: number;
        failed_queue_jobs: number | null;
    };
    audit: { id: number; at: string | null; action: string; actor: string | null; target: string | null }[] | null;
};

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Activation posture', href: '/observe/posture' }];

/** What a role-boundary kind means, in words a person reads. An unknown kind shows its own name. */
const KIND_LABELS: Record<string, string> = {
    outbound_message: 'Send a message to someone outside HQ',
    publication: 'Publish content',
    customer_reply: 'Reply to a customer',
    role_expansion: 'Take on another role or more authority',
};

function kindLabel(t: Translator, kind: string): string {
    return KIND_LABELS[kind] ? t(KIND_LABELS[kind]) : humanKey(kind);
}

function OnOff({ on }: { on: boolean }) {
    const t = useT();
    return <StatusBadge tone={on ? 'warn' : 'neutral'}>{on ? t('On') : t('Off')}</StatusBadge>;
}

function YesNo({ value, warnWhenYes = false }: { value: boolean; warnWhenYes?: boolean }) {
    const t = useT();
    return <StatusBadge tone={value ? (warnWhenYes ? 'warn' : 'ok') : 'neutral'} dot={false}>{value ? t('yes') : t('no')}</StatusBadge>;
}

export default function Posture(props: Props) {
    const t = useT();
    const { switches } = props;

    const switchedOn: string[] = [
        switches.ai_runtime ? t('the AI runtime') : null,
        switches.meta_inbound ? t('Meta inbound') : null,
        switches.messaging_providers.length > 0 ? t('a messaging provider') : null,
        switches.take_order_stores.length > 0 ? t('a store that accepts AI orders') : null,
        switches.active_grants > 0 ? t('an autonomy grant') : null,
        switches.open_shadow_periods > 0 ? t('a shadow period') : null,
        ...props.agents.filter((a) => a.status !== 'paused').map((a) => t('the {agent} agent', { agent: a.name })),
    ].filter((x): x is string => x !== null);
    const inert = switchedOn.length === 0;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Activation posture')} />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Activation posture"
                    description="Whether anything is switched on, what each role may never do, and what still stands between the agents and their next step. Read-only: nothing on this page changes anything."
                    actions={<StatusBadge tone={inert ? 'ok' : 'warn'}>{inert ? t('Inert') : t('Something is on')}</StatusBadge>}
                />

                <div
                    className={
                        inert
                            ? 'rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-200'
                            : 'rounded-xl border border-orange-200 bg-orange-50 px-4 py-3 text-sm text-orange-900 dark:border-orange-900 dark:bg-orange-950 dark:text-orange-200'
                    }
                >
                    {inert
                        ? t('Production is inert: the AI runtime is off, every agent is paused, and nothing can reach a customer, spend money or take an order.')
                        : t('Switched on now: {items}.', { items: switchedOn.join(', ') })}
                </div>

                <StatGrid>
                    <Stat label="AI runtime" value={<OnOff on={switches.ai_runtime} />} detail="Off unless a gate or a shadow period is running." />
                    <Stat label="Meta inbound" value={<OnOff on={switches.meta_inbound} />} detail="Customer messages from Facebook and Instagram." />
                    <Stat
                        label="Messaging providers registered"
                        value={formatNumber(switches.messaging_providers.length)}
                        detail={switches.messaging_providers.length > 0 ? switches.messaging_providers.join(', ') : 'None: no message can leave HQ.'}
                    />
                    <Stat
                        label="Stores that accept AI orders"
                        value={formatNumber(switches.take_order_stores.length)}
                        detail={switches.take_order_stores.length > 0 ? switches.take_order_stores.join(', ') : 'None: every AI order is refused.'}
                    />
                    <Stat label="Autonomy grants" value={formatNumber(switches.active_grants)} detail="Active grants. Zero means every action still asks." />
                    <Stat label="Consent events" value={formatNumber(switches.consent_events)} detail="Recorded customer consent decisions." />
                    <Stat label="Open shadow periods" value={formatNumber(switches.open_shadow_periods)} detail="Opened only by a named person." />
                    <Stat
                        label="Evidence policy"
                        value={switches.active_evidence_policy ? switches.active_evidence_policy.version : t('none')}
                        detail={switches.active_evidence_policy ? switches.active_evidence_policy.name : 'No autonomy numbers are active.'}
                    />
                </StatGrid>

                <Panel title="Agents and what they may never do">
                    <DataTable
                        rowKey={(a) => a.key}
                        rows={props.agents}
                        empty="No agents are registered."
                        columns={[
                            { key: 'agent', label: 'Agent', render: (a) => <span className="font-medium">{a.name}</span> },
                            { key: 'status', label: 'Status', render: (a) => <StatusBadge status={a.status} /> },
                            { key: 'autonomy', label: 'Autonomy ceiling', render: (a) => <span className="font-mono text-xs uppercase">{orDash(a.autonomy)}</span> },
                            { key: 'enabling', label: 'Enabling user', render: (a) => a.enabling_user ?? t('none') },
                            {
                                key: 'forbidden',
                                label: 'May never',
                                render: (a) =>
                                    a.forbidden.length === 0 ? (
                                        t('nothing extra')
                                    ) : (
                                        <div className="flex flex-wrap gap-1">
                                            {a.forbidden.map((k) => (
                                                <StatusBadge key={k} tone="danger" dot={false}>
                                                    {kindLabel(t, k)}
                                                </StatusBadge>
                                            ))}
                                        </div>
                                    ),
                            },
                            { key: 'tools', label: 'Tools held', align: 'right', render: (a) => formatNumber(a.tools.length) },
                            {
                                key: 'shadow',
                                label: 'Shadow period',
                                render: (a) =>
                                    a.shadow ? t('open since {at}', { at: formatDateTime(a.shadow.opened_at) }) : <span className="text-muted-foreground">{t('none')}</span>,
                            },
                        ]}
                    />
                </Panel>

                <Panel title="Role restrictions" aside={t('Role policy {version}', { version: props.role_policy.version })}>
                    <DataTable
                        rowKey={(r) => r.role}
                        rows={props.role_policy.roles}
                        empty="No role is named by the policy."
                        columns={[
                            { key: 'role', label: 'Role', render: (r) => <span className="font-medium">{humanKey(r.role)}</span> },
                            ...props.role_policy.kinds.map((kind) => ({
                                key: kind,
                                label: kindLabel(t, kind),
                                render: (r: { role: string; forbidden: string[] }) =>
                                    r.forbidden.includes(kind) ? (
                                        <StatusBadge tone="danger">{t('refused before the model')}</StatusBadge>
                                    ) : (
                                        <span className="text-muted-foreground text-xs">{t('not refused by the role')}</span>
                                    ),
                            })),
                        ]}
                    />
                    <Note>
                        {t(
                            'Checked by the runtime on the words the operator wrote, before any model is asked. Not refused by the role is not a permission: the tool, its policy, consent and approval still decide.',
                        )}
                    </Note>
                </Panel>

                <Panel title="What stands between each agent and its shadow week">
                    <div className="flex flex-col gap-3">
                        {props.shadow.map((s) => (
                            <div key={s.agent_key} className="bg-card rounded-xl border p-4 text-sm">
                                <div className="mb-2 flex flex-wrap items-center gap-2">
                                    <span className="font-medium">{humanKey(s.agent_key)}</span>
                                    <StatusBadge tone={s.may_open ? 'ok' : 'pending'}>
                                        {s.may_open ? t('every precondition is met') : t('{n} precondition(s) not met', { n: s.refusals.length })}
                                    </StatusBadge>
                                </div>
                                {s.may_open ? (
                                    <p className="text-muted-foreground">{t('Only Joy can open the period, by name, from the console.')}</p>
                                ) : (
                                    <ul className="text-muted-foreground list-disc space-y-1 pl-5" lang="en">
                                        {s.refusals.map((r, i) => (
                                            <li key={i}>{r}</li>
                                        ))}
                                    </ul>
                                )}
                            </div>
                        ))}
                    </div>
                    <Note>The reasons are the console's own words, the same check the shadow command refuses on.</Note>
                </Panel>

                <Panel title="Gate evidence" aside={<Link href="/observe/autonomy-evidence" className="underline">{t('Autonomy evidence')}</Link>}>
                    <DataTable
                        rowKey={(e) => `${e.agent_key}:${e.suite}`}
                        rows={props.evidence}
                        empty="No evaluation suite applies to any agent."
                        columns={[
                            { key: 'agent', label: 'Agent', render: (e) => humanKey(e.agent_key) },
                            { key: 'suite', label: 'Suite', render: (e) => <span className="font-mono text-xs">{e.suite}</span> },
                            { key: 'run', label: 'Run', align: 'right', render: (e) => (e.run_id === null ? '-' : `#${e.run_id}`) },
                            { key: 'result', label: 'Result', render: (e) => (e.status ? <StatusBadge status={e.status} /> : t('not measured yet')) },
                            {
                                key: 'passed',
                                label: 'Passed',
                                align: 'right',
                                render: (e) => (e.cases_total === null ? '-' : `${formatNumber(e.cases_passed)} / ${formatNumber(e.cases_total)}`),
                            },
                            { key: 'failed', label: 'Failed', align: 'right', render: (e) => (e.cases_failed === null ? '-' : formatNumber(e.cases_failed)) },
                            { key: 'unmeasured', label: 'Unmeasured', align: 'right', render: (e) => (e.unmeasured === null ? '-' : formatNumber(e.unmeasured)) },
                            { key: 'model', label: 'Model', render: (e) => <span className="font-mono text-xs">{orDash(e.model)}</span> },
                            { key: 'by', label: 'Measured by', render: (e) => orDash(e.triggered_by) },
                            { key: 'at', label: 'Finished', render: (e) => formatDateTime(e.finished_at) },
                        ]}
                    />
                </Panel>

                <Panel
                    title="Model provider"
                    aside={
                        props.model.reasoning_effort
                            ? t('Serving: {provider}, reasoning effort {effort}', { provider: props.model.serving ?? '-', effort: props.model.reasoning_effort })
                            : t('Serving: {provider}', { provider: props.model.describe })
                    }
                >
                    <DataTable
                        rowKey={(p) => p.key}
                        rows={props.model.providers}
                        empty="No model provider is registered."
                        columns={[
                            { key: 'provider', label: 'Provider', render: (p) => <span className="font-medium">{p.key}</span> },
                            {
                                key: 'key',
                                label: 'Key',
                                render: (p) => (p.credential ? <StatusBadge status={p.credential.status}>{`#${p.credential.id} ${t(p.credential.status)}`}</StatusBadge> : t('none stored')),
                            },
                            {
                                key: 'check',
                                label: 'Last check',
                                render: (p) =>
                                    p.last_check ? (
                                        <span className="text-xs">
                                            <StatusBadge tone={p.last_check.ok ? 'ok' : 'danger'} dot={false}>
                                                {p.last_check.ok ? t('ok') : humanKey(p.last_check.class)}
                                            </StatusBadge>{' '}
                                            {formatDateTime(p.last_check.at)}
                                        </span>
                                    ) : (
                                        t('never')
                                    ),
                            },
                            {
                                key: 'models',
                                label: 'Models',
                                render: (p) => (
                                    <div className="flex flex-col gap-0.5 font-mono text-xs">
                                        {p.models.map((m) => (
                                            <span key={`${m.tier}:${m.model}:${m.priority}`} className={m.on ? '' : 'text-muted-foreground line-through'}>
                                                {m.tier} {m.model}
                                            </span>
                                        ))}
                                    </div>
                                ),
                            },
                        ]}
                    />
                </Panel>

                <Panel
                    title="Credential readiness"
                    aside={props.readiness ? <Link href="/credentials" className="underline">{t('Credentials')}</Link> : undefined}
                >
                    {props.readiness ? (
                        <>
                            <StatGrid>
                                <Stat label="Targets" value={formatNumber(props.readiness.total)} />
                                <Stat label="Verified" value={formatNumber(props.readiness.verified)} detail="The provider accepted the credential last time." />
                                <Stat label="Would be attempted" value={formatNumber(props.readiness.would_attempt)} />
                                <Stat label="Unsupported by design" value={formatNumber(props.readiness.unsupported)} />
                            </StatGrid>
                            <DataTable
                                rowKey={(r, i) => `${r.target}:${r.provider}:${i}`}
                                rows={props.readiness.incidents}
                                empty="No incident: nothing needs a person."
                                columns={[
                                    { key: 'target', label: 'Target', render: (r) => r.target },
                                    { key: 'provider', label: 'Provider', render: (r) => <span className="font-mono text-xs">{r.provider}</span> },
                                    { key: 'state', label: 'State', render: (r) => <StatusBadge status={r.state} /> },
                                    { key: 'reason', label: 'Why', render: (r) => <span lang="en">{r.reason}</span> },
                                ]}
                            />
                        </>
                    ) : (
                        <Note>Shown to people who may see credentials.</Note>
                    )}
                </Panel>

                <Panel title="Capability state" aside="Holding a tool is not being allowed to run it.">
                    <DataTable
                        rowKey={(tool) => tool.key}
                        rows={props.capability.tools}
                        empty="No tool is registered."
                        columns={[
                            {
                                key: 'tool',
                                label: 'Tool',
                                render: (tool) => (
                                    <div className="flex flex-col">
                                        <span className="font-medium" lang="en">
                                            {tool.title}
                                        </span>
                                        <span className="text-muted-foreground font-mono text-xs">{tool.key}</span>
                                    </div>
                                ),
                            },
                            { key: 'risk', label: 'Risk', render: (tool) => <span className="font-mono text-xs uppercase">{tool.risk}</span> },
                            { key: 'writes', label: 'Writes', render: (tool) => <YesNo value={tool.writes} warnWhenYes /> },
                            { key: 'customer', label: 'Customer-facing', render: (tool) => <YesNo value={tool.customer_facing} warnWhenYes /> },
                            { key: 'agent', label: 'An agent may run it', render: (tool) => <YesNo value={tool.agent_executable} warnWhenYes /> },
                            { key: 'human', label: 'People only, always', render: (tool) => <YesNo value={tool.human_only_forever} /> },
                            { key: 'switch', label: 'Switch', render: (tool) => <StatusBadge status={tool.enabled ? 'enabled' : 'disabled'} /> },
                            {
                                key: 'held',
                                label: 'Held by',
                                render: (tool) => (tool.held_by.length === 0 ? <span className="text-muted-foreground">{t('no agent')}</span> : tool.held_by.map(humanKey).join(', ')),
                            },
                        ]}
                    />
                </Panel>

                <Panel title="Uncertain and failed work">
                    <StatGrid>
                        <Stat label="Uncertain tool calls" value={formatNumber(props.reconciliation.uncertain_calls)} detail="A person must decide; nothing repairs them silently." />
                        <Stat label="Dead events" value={formatNumber(props.reconciliation.dead_events)} />
                        <Stat label="Open alerts" value={formatNumber(props.reconciliation.open_alerts)} />
                        <Stat label="Failed queue jobs" value={props.reconciliation.failed_queue_jobs === null ? '-' : formatNumber(props.reconciliation.failed_queue_jobs)} />
                    </StatGrid>
                    <DataTable
                        rowKey={(c) => String(c.id)}
                        rows={props.reconciliation.latest_uncertain}
                        empty="No tool call is uncertain."
                        columns={[
                            { key: 'id', label: 'Call', align: 'right', render: (c) => `#${c.id}` },
                            { key: 'tool', label: 'Tool', render: (c) => <span className="font-mono text-xs">{c.tool_key}</span> },
                            { key: 'class', label: 'Outcome', render: (c) => orDash(humanKey(c.outcome_class)) },
                            { key: 'at', label: 'When', render: (c) => formatDateTime(c.at) },
                        ]}
                    />
                </Panel>

                <Panel title="Audit references" aside={props.audit ? <Link href="/audit" className="underline">{t('Audit log')}</Link> : undefined}>
                    {props.audit ? (
                        <DataTable
                            rowKey={(a) => String(a.id)}
                            rows={props.audit}
                            empty="No change to the posture has been recorded."
                            columns={[
                                {
                                    key: 'id',
                                    label: 'Entry',
                                    align: 'right',
                                    render: (a) => (
                                        <Link href={`/audit?action=${encodeURIComponent(a.action)}`} className="underline">
                                            #{a.id}
                                        </Link>
                                    ),
                                },
                                { key: 'at', label: 'When', render: (a) => formatDateTime(a.at) },
                                { key: 'action', label: 'Action', render: (a) => <span className="font-mono text-xs">{a.action}</span> },
                                { key: 'by', label: 'By', render: (a) => orDash(a.actor) },
                                { key: 'target', label: 'Target', render: (a) => orDash(a.target) },
                            ]}
                        />
                    ) : (
                        <Note>Shown to people who may read the audit log.</Note>
                    )}
                </Panel>
            </div>
        </AppLayout>
    );
}
