import { DataTable, Note, Panel, Stat, StatGrid, orDash } from '@/components/observe/panels';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/format';
import { formatNumber, useT } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { type ReactNode } from 'react';

/**
 * The decision corpus (docs/111, ruling docs/103 section 7): what people kept, edited and refused of
 * what the agents proposed, and which corrections recur. Read-only: it posts nothing, links to no
 * action, and never shows the proposal, the final text, the delta or the reason. Every row is a count
 * with references to the approval, run or thread page the viewer is already gated on.
 */

type Example = { id: number; context_type: string; context_id: string; href: string | null; at: string | null };

type Counts = {
    records: number;
    kept: number;
    edited: number;
    rejected: number;
    edit_rate: number | null;
    rejection_rate: number | null;
    examples: Example[];
};

type AgentRow = Counts & { agent_key: string; agent_name: string | null; role_key: string | null };
type ToolRow = Counts & { tool: string };
type KindRow = Counts & { kind: string };
type BusinessRow = Counts & { business_id: number | null; business: string | null; slug: string | null; brand: string | null };
type Category = { category: string; label: string; edits: number; share: number | null; examples: Example[] };
type Field = { tool: string; key: string; edits: number; examples: Example[] };
type Pattern = {
    agent_key: string | null;
    agent_name: string | null;
    role_key: string | null;
    tool: string;
    category: string;
    label: string;
    count: number;
    businesses: string[];
    first_at: string | null;
    last_at: string | null;
    examples: Example[];
};

type Props = {
    window: { key: string; since: string | null; until: string; options: string[] };
    scope: { restricted: boolean; business_id: number | null; businesses: { id: number; name: string; slug: string }[]; unscoped_records: number };
    totals: Omit<Counts, 'examples'> & { by_stored_decision: Record<string, number> };
    by_agent: AgentRow[];
    by_tool: ToolRow[];
    by_kind: KindRow[];
    by_business: BusinessRow[];
    categories: Category[];
    fields: Field[];
    patterns: Pattern[];
    gaps: { edits_without_delta: number; shadow_reviews_edited_without_text: number; contexts_missing: number; edits_with_reason: number };
    can: { approvals: boolean; agents: boolean; inbox: boolean };
};

const WINDOW_LABELS: Record<string, string> = { '30d': 'Last 30 days', '90d': 'Last 90 days', '365d': 'Last 365 days', all: 'All time' };

const KIND_LABELS: Record<string, string> = { tool_arguments: 'Tool arguments', reply_text: 'Reply text', shadow_answer: 'Shadow answer' };

const CONTEXT_LABELS: Record<string, string> = { approval: 'Approval', agent_run: 'Run', conversation: 'Thread' };

const CATEGORY_LABELS: Record<string, string> = {
    language_or_script: 'Language or script changed',
    register: 'Register corrected (informal to apni)',
    refusal_added: 'Refusal or decline added',
    refusal_removed: 'Refusal or decline removed',
    fact_corrected: 'Fact corrected (number, reference, value)',
    shortened: 'Shortened',
    expanded: 'Expanded',
    wording: 'Wording',
    rewritten: 'Rewritten',
    scope_narrowed: 'Scope narrowed',
    scope_widened: 'Scope widened',
    target_changed: 'Target changed (customer, thread, order, product)',
    verdict_only: 'Verdict only (no correction recorded)',
    unclassified: 'Unclassified',
};

function pct(value: number | null): string {
    return value === null ? '-' : `${formatNumber(Math.round(1000 * value) / 10)}%`;
}

export default function DecisionCorpus({
    window,
    scope,
    totals,
    by_agent,
    by_tool,
    by_kind,
    by_business,
    categories,
    fields,
    patterns,
    gaps,
}: Props) {
    const t = useT();
    const breadcrumbs: BreadcrumbItem[] = [{ title: t('Decision corpus'), href: '/observe/decision-corpus' }];
    const query = (changes: Record<string, string | number | null>) => {
        const params: Record<string, string | number> = { window: window.key };
        if (scope.business_id !== null) {
            params.business = scope.business_id;
        }
        for (const [k, v] of Object.entries(changes)) {
            if (v === null) {
                delete params[k];
            } else {
                params[k] = v;
            }
        }
        router.get('/observe/decision-corpus', params, { preserveScroll: true, replace: true });
    };
    const categoryLabel = (c: string) => t(CATEGORY_LABELS[c] ?? c);

    const refs = (examples: Example[]): ReactNode => (
        <span className="text-muted-foreground text-xs">
            {examples.map((e, i) => {
                const text = `#${e.id} ${t(CONTEXT_LABELS[e.context_type] ?? e.context_type)}`;
                return (
                    <span key={e.id} title={e.at ? formatDateTime(e.at) : undefined}>
                        {i > 0 && ', '}
                        {e.href ? (
                            <Link href={e.href} className="hover:underline">
                                {text}
                            </Link>
                        ) : (
                            text
                        )}
                    </span>
                );
            })}
        </span>
    );

    const countColumns = <T extends Counts>() => [
        { key: 'records', label: 'Records', align: 'right' as const, render: (r: T) => formatNumber(r.records) },
        { key: 'kept', label: 'Kept', align: 'right' as const, render: (r: T) => formatNumber(r.kept) },
        { key: 'edited', label: 'Edited', align: 'right' as const, render: (r: T) => formatNumber(r.edited) },
        { key: 'rejected', label: 'Rejected', align: 'right' as const, render: (r: T) => formatNumber(r.rejected) },
        { key: 'edit_rate', label: 'Edit rate', align: 'right' as const, render: (r: T) => pct(r.edit_rate) },
        { key: 'examples', label: 'References', render: (r: T) => refs(r.examples) },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Decision corpus')} />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={t('Decision corpus')}
                    description={t(
                        'What people kept, edited and refused of what the agents proposed, and which corrections recur. Counts and references only: no proposal, reply or reason text appears here. This page reads; it changes no prompt, policy, playbook or autonomy.',
                    )}
                >
                    <div className="flex max-w-full flex-wrap items-center gap-x-4 gap-y-2">
                        <div className="flex flex-wrap items-center gap-2" role="group" aria-label={t('Period')}>
                            {window.options.map((key) => (
                                <Button
                                    key={key}
                                    size="sm"
                                    variant={window.key === key ? 'default' : 'outline'}
                                    onClick={() => query({ window: key })}
                                >
                                    {t(WINDOW_LABELS[key] ?? key)}
                                </Button>
                            ))}
                        </div>
                        {scope.businesses.length > 1 && (
                            <div className="flex flex-wrap items-center gap-2" role="group" aria-label={t('Business')}>
                                <Button
                                    size="sm"
                                    variant={scope.business_id === null ? 'default' : 'outline'}
                                    onClick={() => query({ business: null })}
                                >
                                    {t('All businesses')}
                                </Button>
                                {scope.businesses.map((b) => (
                                    <Button
                                        key={b.id}
                                        size="sm"
                                        variant={scope.business_id === b.id ? 'default' : 'outline'}
                                        onClick={() => query({ business: b.id })}
                                    >
                                        {b.name}
                                    </Button>
                                ))}
                            </div>
                        )}
                    </div>
                </PageHeader>

                <StatGrid>
                    <Stat
                        label="Records"
                        value={formatNumber(totals.records)}
                        detail={t('Decisions people recorded on agent proposals in this period')}
                    />
                    <Stat label="Kept as proposed" value={formatNumber(totals.kept)} />
                    <Stat label="Edited" value={formatNumber(totals.edited)} detail={t('Edit rate {rate}', { rate: pct(totals.edit_rate) })} />
                    <Stat
                        label="Refused"
                        value={formatNumber(totals.rejected)}
                        detail={t('Rejection rate {rate}', { rate: pct(totals.rejection_rate) })}
                    />
                </StatGrid>
                <Note>
                    {t(
                        'Stored decision words: approvals write approved, edited or rejected; the inbox writes send or discard. This page counts them on one axis (kept, edited, refused) and rewrites nothing.',
                    )}
                </Note>
                <div className="flex flex-wrap gap-2">
                    {Object.entries(totals.by_stored_decision).map(([word, n]) => (
                        <StatusBadge key={word} tone="neutral" dot={false}>
                            {word} {formatNumber(n)}
                        </StatusBadge>
                    ))}
                    {scope.unscoped_records > 0 && (
                        <StatusBadge tone="pending" dot={false}>
                            {t('{n} unscoped', { n: formatNumber(scope.unscoped_records) })}
                        </StatusBadge>
                    )}
                </div>

                <Panel title="Recurring corrections" aside={t('agent, tool and category seen at least twice')}>
                    <DataTable<Pattern>
                        columns={[
                            {
                                key: 'agent',
                                label: 'Agent',
                                render: (p) => `${p.agent_name ?? p.agent_key ?? '-'}${p.role_key ? ` (${p.role_key})` : ''}`,
                            },
                            { key: 'tool', label: 'Tool', render: (p) => <code className="text-xs">{p.tool}</code> },
                            { key: 'category', label: 'Category', render: (p) => categoryLabel(p.category) },
                            { key: 'count', label: 'Times', align: 'right', render: (p) => formatNumber(p.count) },
                            { key: 'businesses', label: 'Businesses', render: (p) => orDash(p.businesses.join(', ')) },
                            {
                                key: 'span',
                                label: 'First to last',
                                render: (p) => (
                                    <span className="text-muted-foreground text-xs whitespace-nowrap">
                                        {formatDateTime(p.first_at)} - {formatDateTime(p.last_at)}
                                    </span>
                                ),
                            },
                            { key: 'examples', label: 'References', render: (p) => refs(p.examples) },
                        ]}
                        rows={patterns}
                        empty={t('No correction has recurred in this period.')}
                        rowKey={(p) => `${p.agent_key}|${p.tool}|${p.category}`}
                    />
                </Panel>

                <Panel title="Edit categories" aside={t('an edit may carry several')}>
                    <DataTable<Category>
                        columns={[
                            { key: 'label', label: 'Category', render: (c) => categoryLabel(c.category) },
                            { key: 'edits', label: 'Edits', align: 'right', render: (c) => formatNumber(c.edits) },
                            { key: 'share', label: 'Share of edits', align: 'right', render: (c) => pct(c.share) },
                            { key: 'examples', label: 'References', render: (c) => refs(c.examples) },
                        ]}
                        rows={categories}
                        empty={t('No edit in this period.')}
                        rowKey={(c) => c.category}
                    />
                    <Note>
                        {t(
                            'Each category is a fixed rule over the stored change: the script detected before and after, informal Bangla pronouns, a closed list of decline words, the numbers and references in the text, word counts, and word overlap. No model reads the text.',
                        )}
                    </Note>
                </Panel>

                <div className="grid grid-cols-1 gap-6 xl:grid-cols-2">
                    <Panel title="By agent">
                        <DataTable<AgentRow>
                            columns={[
                                {
                                    key: 'agent',
                                    label: 'Agent',
                                    render: (r) => `${r.agent_name ?? r.agent_key}${r.role_key ? ` (${r.role_key})` : ''}`,
                                },
                                ...countColumns<AgentRow>(),
                            ]}
                            rows={by_agent}
                            empty={t('No decision in this period.')}
                            rowKey={(r) => r.agent_key}
                        />
                    </Panel>
                    <Panel title="By tool">
                        <DataTable<ToolRow>
                            columns={[
                                { key: 'tool', label: 'Tool', render: (r) => <code className="text-xs">{r.tool}</code> },
                                ...countColumns<ToolRow>(),
                            ]}
                            rows={by_tool}
                            empty={t('No decision in this period.')}
                            rowKey={(r) => r.tool}
                        />
                    </Panel>
                    <Panel title="By proposal kind">
                        <DataTable<KindRow>
                            columns={[{ key: 'kind', label: 'Kind', render: (r) => t(KIND_LABELS[r.kind] ?? r.kind) }, ...countColumns<KindRow>()]}
                            rows={by_kind}
                            empty={t('No decision in this period.')}
                            rowKey={(r) => r.kind}
                        />
                    </Panel>
                    <Panel title="By business and brand">
                        <DataTable<BusinessRow>
                            columns={[
                                {
                                    key: 'business',
                                    label: 'Business',
                                    render: (r) => `${r.business ?? t('Unscoped')}${r.brand ? ` / ${r.brand}` : ''}`,
                                },
                                ...countColumns<BusinessRow>(),
                            ]}
                            rows={by_business}
                            empty={t('No decision in this period.')}
                            rowKey={(r) => `${r.business_id ?? 0}:${r.brand ?? ''}`}
                        />
                    </Panel>
                </div>

                <Panel title="Argument keys people change" aside={t('tool-argument edits only')}>
                    <DataTable<Field>
                        columns={[
                            { key: 'tool', label: 'Tool', render: (f) => <code className="text-xs">{f.tool}</code> },
                            { key: 'key', label: 'Key', render: (f) => <code className="text-xs">{f.key}</code> },
                            { key: 'edits', label: 'Edits', align: 'right', render: (f) => formatNumber(f.edits) },
                            { key: 'examples', label: 'References', render: (f) => refs(f.examples) },
                        ]}
                        rows={fields}
                        empty={t('No tool argument was edited in this period.')}
                        rowKey={(f) => `${f.tool}|${f.key}`}
                    />
                </Panel>

                <Panel title="What the corpus does not hold">
                    <ul className="text-muted-foreground list-disc space-y-1 pl-5 text-sm">
                        <li>
                            {t(
                                'Shadow reviews marked edited without the correction: {n}. The review screen records a verdict and a note, not what the person would have sent.',
                                {
                                    n: formatNumber(gaps.shadow_reviews_edited_without_text),
                                },
                            )}
                        </li>
                        <li>{t('Edits without a stored change: {n}.', { n: formatNumber(gaps.edits_without_delta) })}</li>
                        <li>{t('Records whose approval, run or thread row is gone: {n}.', { n: formatNumber(gaps.contexts_missing) })}</li>
                        <li>
                            {t('Edits with a reason written by the person: {n}. Reasons are not shown here.', {
                                n: formatNumber(gaps.edits_with_reason),
                            })}
                        </li>
                    </ul>
                </Panel>
            </div>
        </AppLayout>
    );
}
