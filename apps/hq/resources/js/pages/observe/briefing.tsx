import { NARRATION_LABEL, STATE_TONE, scopeLabel, type BriefingReason, type BriefingSummary } from '@/components/observe/briefing';
import { DataTable, Note, Panel, Stat, StatGrid, orDash } from '@/components/observe/panels';
import { JsonBlock, PageHeader, TechnicalDetail } from '@/components/page-header';
import { InertNotice, Notice } from '@/components/ui/notice';
import { StatusBadge, type Tone } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, humanKey } from '@/lib/format';
import { useT, type Translator } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';

type FreshnessInfo = { state: string; budget_minutes: number | null; age_minutes: number | null; in_future: boolean };

type Scope = { level: string; business: string | null; brand: string | null; store: string | null };

type LineageInfo = {
    formula: string;
    table: string | null;
    rows: Record<string, unknown> | null;
    input_items: string[];
    input_sources: string[];
    filter: Record<string, unknown> | unknown[];
};

type Item = {
    id: string;
    kind: string;
    section: string;
    label: string;
    value: string | number | boolean | null;
    unit: string;
    scope: Scope;
    source: { system: string; read_model: string; ref: string } & Record<string, unknown>;
    as_of: string | null;
    freshness: FreshnessInfo;
    lineage: LineageInfo | null;
    subject: string | null;
    details: Record<string, unknown> | unknown[];
    note: string | null;
};

type Source = {
    key: string;
    read_model: string;
    scope: Scope;
    as_of: string | null;
    freshness: FreshnessInfo;
    rows_held: number | null;
    note: string | null;
};

type Section = { key: string; title: string; status: string; reason: string | null; item_ids: string[]; notes: Record<string, unknown>[] };

type Bundle = {
    version: string;
    notice: string;
    generated_at: string;
    freshness: { stale: string[]; missing: string[]; not_connected: string[]; sources: Source[] };
    yesterday: { date: string; from: string; to: string };
    today: { date: string; from: string; to: string };
    sections: Section[];
    items: Item[];
};

type Detail = BriefingSummary & {
    validation: { state: string; blocks_narration: boolean; reasons: BriefingReason[] };
    narrative: string | null;
    narrative_check: (Record<string, unknown> & { passed?: boolean; unsourced?: { figure: string; why: string | null; excerpt: string }[] }) | null;
    narrator: string | null;
    correlation_id: string | null;
    bundle_digest: string | null;
    bundle_intact: boolean | null;
};

type Props = { briefing: Detail; bundle: Bundle | null };

const FRESHNESS_TONE: Record<string, Tone> = { fresh: 'ok', live: 'ok', stale: 'warn', missing: 'danger', not_connected: 'neutral' };

const SECTION_TONE: Record<string, Tone> = { ok: 'ok', empty: 'neutral', not_connected: 'neutral', withheld: 'warn', not_applicable: 'neutral' };

function minutes(t: Translator, value: number | null): string {
    if (value === null) {
        return '-';
    }
    return value >= 120 ? t('{n} h', { n: Math.round(value / 60) }) : t('{n} min', { n: value });
}

function FreshnessCell({ freshness }: { freshness: FreshnessInfo }) {
    const t = useT();

    return (
        <div className="flex flex-col gap-0.5">
            <StatusBadge tone={FRESHNESS_TONE[freshness.state] ?? 'neutral'}>{t(humanKey(freshness.state))}</StatusBadge>
            {freshness.budget_minutes !== null && (
                <span className="text-muted-foreground text-xs whitespace-nowrap">
                    {t('age {age} of {budget}', { age: minutes(t, freshness.age_minutes), budget: minutes(t, freshness.budget_minutes) })}
                    {freshness.in_future ? t(', as_of is in the future') : ''}
                </span>
            )}
        </div>
    );
}

function scopeText(t: Translator, scope: Scope): string {
    return scope.store ?? scope.brand ?? scope.business ?? t('group');
}

function valueText(t: Translator, item: Item): string {
    if (item.value === null) {
        return '-';
    }
    if (item.unit === 'datetime' && typeof item.value === 'string') {
        return formatDateTime(item.value);
    }
    if (typeof item.value === 'boolean') {
        return item.value ? t('yes') : t('no');
    }
    return `${item.value}${['text', 'boolean', 'datetime'].includes(item.unit) ? '' : ` ${item.unit}`}`;
}

function LineageCell({ item }: { item: Item }) {
    const t = useT();

    if (!item.lineage) {
        return <span className="text-muted-foreground text-xs">{t('read, not computed')}</span>;
    }
    const rows = item.lineage.rows as { count?: number; mode?: string } | null;
    return (
        <div className="flex flex-col gap-1">
            <span className="font-mono text-xs">{item.lineage.formula}</span>
            <span className="text-muted-foreground text-xs">
                {rows ? t('{count} row(s) of {table} ({mode})', { count: rows.count ?? 0, table: item.lineage.table, mode: rows.mode }) : null}
                {item.lineage.input_items.length > 0 ? t('{n} item(s)', { n: item.lineage.input_items.length }) : null}
                {item.lineage.input_sources.length > 0 ? t('{n} source(s)', { n: item.lineage.input_sources.length }) : null}
            </span>
            <TechnicalDetail summary="Lineage">
                <JsonBlock value={item.lineage} />
            </TechnicalDetail>
        </div>
    );
}

export default function BriefingPage({ briefing, bundle }: Props) {
    const t = useT();
    const narration = (state: string): string => (NARRATION_LABEL[state] ? t(NARRATION_LABEL[state]) : state);
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Briefings', href: '/observe/briefings' },
        { title: briefing.briefing_date, href: `/observe/briefings/${briefing.uuid}` },
    ];
    const attention = bundle ? bundle.freshness.sources.filter((s) => ['stale', 'missing'].includes(s.freshness.state)) : [];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Briefing {date}', { date: briefing.briefing_date })} />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={t('Briefing for {date}', { date: briefing.briefing_date })}
                    description={t(
                        '{scope}. Composed {when}. The evidence below is not business truth: it is what a narrator may speak from, each value with its source, its as_of, its freshness and how it was obtained.',
                        { scope: briefing.business ?? t(scopeLabel(briefing)), when: formatDateTime(briefing.generated_at) },
                    )}
                    actions={<StatusBadge tone={STATE_TONE[briefing.state] ?? 'neutral'}>{briefing.state}</StatusBadge>}
                />

                {/* Freshness first, before any number (docs/48 M.3). */}
                {attention.length > 0 && (
                    <Notice tone="pending" title="Stale or missing sources">
                        <ul className="flex flex-col gap-1 text-sm">
                            {attention.map((s) => (
                                <li key={s.key}>
                                    <span className="font-mono text-xs">{s.key}</span>: {t(humanKey(s.freshness.state))}
                                    {s.as_of ? t(', as of {when}', { when: formatDateTime(s.as_of) }) : ''}
                                    {s.note ? `. ${s.note}` : ''}
                                </li>
                            ))}
                        </ul>
                    </Notice>
                )}

                {briefing.degraded_reasons.length > 0 && (
                    <Panel title="Why this briefing is degraded">
                        <DataTable
                            rowKey={(r, i) => `${r.code}-${i}`}
                            rows={briefing.degraded_reasons}
                            empty="-"
                            columns={[
                                { key: 'check', label: 'Check', render: (r) => humanKey(r.check) },
                                { key: 'code', label: 'Reason', render: (r) => <span className="font-mono text-xs">{r.code}</span> },
                                { key: 'subject', label: 'About', render: (r) => <span className="font-mono text-xs">{orDash(r.subject)}</span> },
                                { key: 'message', label: 'What it means', render: (r) => r.message },
                                { key: 'blocks', label: 'Blocks narration', render: (r) => (r.blocks_narration ? t('yes') : t('no')) },
                            ]}
                        />
                    </Panel>
                )}

                <StatGrid>
                    <Stat label="Evidence items" value={briefing.items} detail={briefing.bundle_version} />
                    <Stat label="Yesterday" value={orDash(bundle?.yesterday.date)} detail={briefing.timezone} />
                    <Stat
                        label="Narration"
                        value={briefing.narration_state}
                        detail={NARRATION_LABEL[briefing.narration_state] ? narration(briefing.narration_state) : undefined}
                    />
                    <Stat
                        label="Stored bundle"
                        value={briefing.bundle_intact === null ? t('none') : briefing.bundle_intact ? t('intact') : t('changed')}
                        detail={briefing.bundle_digest ? `sha256 ${briefing.bundle_digest.slice(0, 16)}` : t('no bundle was collected')}
                    />
                </StatGrid>

                <Panel title="Narrative">
                    {briefing.narrative ? (
                        <div className="hq-card p-4 text-sm whitespace-pre-line">{briefing.narrative}</div>
                    ) : briefing.narration_state === 'off' || briefing.narration_state === 'runtime_off' ? (
                        <InertNotice
                            title="Narration"
                            reason={t('{why}. No narrative is stored.', { why: narration(briefing.narration_state) })}
                            gate={
                                briefing.narration_state === 'off'
                                    ? 'A person switches the briefing.narration flag on.'
                                    : 'A person switches the AI runtime on.'
                            }
                        />
                    ) : (
                        <Note>{t('{why}. No narrative is stored.', { why: narration(briefing.narration_state) })}</Note>
                    )}
                    {briefing.narrative_check?.unsourced && briefing.narrative_check.unsourced.length > 0 && (
                        <DataTable
                            rowKey={(f, i) => `${f.figure}-${i}`}
                            rows={briefing.narrative_check.unsourced}
                            empty="-"
                            columns={[
                                { key: 'figure', label: 'Unsourced figure', render: (f) => <span className="font-mono text-xs">{f.figure}</span> },
                                { key: 'why', label: 'Why', render: (f) => orDash(f.why) },
                                { key: 'excerpt', label: 'Where', render: (f) => f.excerpt },
                            ]}
                        />
                    )}
                    {briefing.narrative_check && (
                        <TechnicalDetail summary="Number check">
                            <JsonBlock value={briefing.narrative_check} />
                        </TechnicalDetail>
                    )}
                </Panel>

                {bundle && (
                    <>
                        <Panel title="Sources and their freshness">
                            <DataTable
                                rowKey={(s) => s.key}
                                rows={bundle.freshness.sources}
                                empty="The bundle lists no source."
                                columns={[
                                    { key: 'key', label: 'Source', render: (s) => <span className="font-mono text-xs">{s.key}</span> },
                                    { key: 'scope', label: 'Scope', render: (s) => scopeText(t, s.scope) },
                                    { key: 'as_of', label: 'As of', render: (s) => (s.as_of ? formatDateTime(s.as_of) : '-') },
                                    { key: 'freshness', label: 'Freshness', render: (s) => <FreshnessCell freshness={s.freshness} /> },
                                    { key: 'rows', label: 'Rows held', align: 'right', render: (s) => orDash(s.rows_held) },
                                    { key: 'note', label: 'Note', render: (s) => orDash(s.note) },
                                ]}
                            />
                        </Panel>

                        {bundle.sections.map((section) => {
                            const items = section.item_ids
                                .map((id) => bundle.items.find((i) => i.id === id))
                                .filter((i): i is Item => i !== undefined);

                            return (
                                <Panel
                                    key={section.key}
                                    title={section.title}
                                    aside={<StatusBadge tone={SECTION_TONE[section.status] ?? 'neutral'}>{t(humanKey(section.status))}</StatusBadge>}
                                >
                                    {section.reason && <Note>{section.reason}</Note>}
                                    {section.notes.map((note, i) => (
                                        <Note key={i}>
                                            {t(humanKey(String(note.status ?? 'note')))}
                                            {note.store ? ` (${String(note.store)})` : note.business ? ` (${String(note.business)})` : ''}:{' '}
                                            {String(note.reason ?? '')}
                                        </Note>
                                    ))}
                                    {items.length > 0 && (
                                        <DataTable
                                            rowKey={(i) => i.id}
                                            rows={items}
                                            empty="-"
                                            columns={[
                                                {
                                                    key: 'item',
                                                    label: 'Item',
                                                    render: (i) => (
                                                        <div className="flex flex-col gap-0.5">
                                                            <span>{i.label}</span>
                                                            <span className="text-muted-foreground font-mono text-[11px] break-all">{i.id}</span>
                                                            {i.subject && <span className="text-muted-foreground text-xs">{i.subject}</span>}
                                                        </div>
                                                    ),
                                                },
                                                { key: 'kind', label: 'Kind', render: (i) => <StatusBadge tone="info">{i.kind}</StatusBadge> },
                                                {
                                                    key: 'value',
                                                    label: 'Value',
                                                    align: 'right',
                                                    render: (i) => <span className="font-medium">{valueText(t, i)}</span>,
                                                },
                                                { key: 'scope', label: 'Scope', render: (i) => scopeText(t, i.scope) },
                                                {
                                                    key: 'source',
                                                    label: 'Source',
                                                    render: (i) => (
                                                        <div className="flex flex-col gap-0.5">
                                                            <span className="font-mono text-xs">{i.source.ref}</span>
                                                            <span className="text-muted-foreground text-xs">
                                                                {i.source.system}, {i.source.read_model}
                                                            </span>
                                                        </div>
                                                    ),
                                                },
                                                { key: 'as_of', label: 'As of', render: (i) => (i.as_of ? formatDateTime(i.as_of) : '-') },
                                                { key: 'freshness', label: 'Freshness', render: (i) => <FreshnessCell freshness={i.freshness} /> },
                                                { key: 'lineage', label: 'How it was obtained', render: (i) => <LineageCell item={i} /> },
                                            ]}
                                        />
                                    )}
                                </Panel>
                            );
                        })}
                    </>
                )}

                <Note>
                    {t('Read-only. A stored briefing is never changed. Correlation id {id}; capability {capability} {version}.', {
                        id: briefing.correlation_id ?? t('none'),
                        capability: briefing.capability,
                        version: briefing.capability_version,
                    })}
                </Note>
            </div>
        </AppLayout>
    );
}
