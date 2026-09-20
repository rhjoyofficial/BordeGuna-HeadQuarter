import { NARRATION_LABEL, STATE_TONE, scopeLabel, type BriefingReason, type BriefingSummary } from '@/components/observe/briefing';
import { DataTable, Note, Panel, Stat, StatGrid, orDash } from '@/components/observe/panels';
import { JsonBlock, PageHeader, TechnicalDetail } from '@/components/page-header';
import { StatusBadge, type Tone } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, humanKey } from '@/lib/format';
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

function minutes(value: number | null): string {
    if (value === null) {
        return '-';
    }
    return value >= 120 ? `${Math.round(value / 60)} h` : `${value} min`;
}

function FreshnessCell({ freshness }: { freshness: FreshnessInfo }) {
    return (
        <div className="flex flex-col gap-0.5">
            <StatusBadge tone={FRESHNESS_TONE[freshness.state] ?? 'neutral'}>{humanKey(freshness.state)}</StatusBadge>
            {freshness.budget_minutes !== null && (
                <span className="text-muted-foreground text-xs whitespace-nowrap">
                    age {minutes(freshness.age_minutes)} of {minutes(freshness.budget_minutes)}
                    {freshness.in_future ? ', as_of is in the future' : ''}
                </span>
            )}
        </div>
    );
}

function scopeText(scope: Scope): string {
    return scope.store ?? scope.brand ?? scope.business ?? 'group';
}

function valueText(item: Item): string {
    if (item.value === null) {
        return '-';
    }
    if (item.unit === 'datetime' && typeof item.value === 'string') {
        return formatDateTime(item.value);
    }
    if (typeof item.value === 'boolean') {
        return item.value ? 'yes' : 'no';
    }
    return `${item.value}${['text', 'boolean', 'datetime'].includes(item.unit) ? '' : ` ${item.unit}`}`;
}

function LineageCell({ item }: { item: Item }) {
    if (!item.lineage) {
        return <span className="text-muted-foreground text-xs">read, not computed</span>;
    }
    const rows = item.lineage.rows as { count?: number; mode?: string } | null;
    return (
        <div className="flex flex-col gap-1">
            <span className="font-mono text-xs">{item.lineage.formula}</span>
            <span className="text-muted-foreground text-xs">
                {rows ? `${rows.count ?? 0} row(s) of ${item.lineage.table} (${rows.mode})` : null}
                {item.lineage.input_items.length > 0 ? `${item.lineage.input_items.length} item(s)` : null}
                {item.lineage.input_sources.length > 0 ? `${item.lineage.input_sources.length} source(s)` : null}
            </span>
            <TechnicalDetail summary="Lineage">
                <JsonBlock value={item.lineage} />
            </TechnicalDetail>
        </div>
    );
}

export default function BriefingPage({ briefing, bundle }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Briefings', href: '/observe/briefings' },
        { title: briefing.briefing_date, href: `/observe/briefings/${briefing.uuid}` },
    ];
    const attention = bundle ? bundle.freshness.sources.filter((s) => ['stale', 'missing'].includes(s.freshness.state)) : [];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Briefing ${briefing.briefing_date}`} />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={`Briefing for ${briefing.briefing_date}`}
                    description={`${scopeLabel(briefing)}. Composed ${formatDateTime(briefing.generated_at)}. The evidence below is not business truth: it is what a narrator may speak from, each value with its source, its as_of, its freshness and how it was obtained.`}
                    actions={<StatusBadge tone={STATE_TONE[briefing.state] ?? 'neutral'}>{briefing.state}</StatusBadge>}
                />

                {/* Freshness first, before any number (docs/48 M.3). */}
                {attention.length > 0 && (
                    <div className="flex flex-col gap-2 rounded-xl border border-amber-500/40 bg-amber-500/5 p-4">
                        <div className="font-medium">Stale or missing sources</div>
                        <ul className="flex flex-col gap-1 text-sm">
                            {attention.map((s) => (
                                <li key={s.key}>
                                    <span className="font-mono text-xs">{s.key}</span>: {humanKey(s.freshness.state)}
                                    {s.as_of ? `, as of ${formatDateTime(s.as_of)}` : ''}
                                    {s.note ? `. ${s.note}` : ''}
                                </li>
                            ))}
                        </ul>
                    </div>
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
                                { key: 'blocks', label: 'Blocks narration', render: (r) => (r.blocks_narration ? 'yes' : 'no') },
                            ]}
                        />
                    </Panel>
                )}

                <StatGrid>
                    <Stat label="Evidence items" value={briefing.items} detail={briefing.bundle_version} />
                    <Stat label="Yesterday" value={orDash(bundle?.yesterday.date)} detail={briefing.timezone} />
                    <Stat label="Narration" value={briefing.narration_state} detail={NARRATION_LABEL[briefing.narration_state]} />
                    <Stat
                        label="Stored bundle"
                        value={briefing.bundle_intact === null ? 'none' : briefing.bundle_intact ? 'intact' : 'changed'}
                        detail={briefing.bundle_digest ? `sha256 ${briefing.bundle_digest.slice(0, 16)}` : 'no bundle was collected'}
                    />
                </StatGrid>

                <Panel title="Narrative">
                    {briefing.narrative ? (
                        <div className="bg-card rounded-xl border p-4 text-sm whitespace-pre-line">{briefing.narrative}</div>
                    ) : (
                        <Note>{NARRATION_LABEL[briefing.narration_state] ?? briefing.narration_state}. No narrative is stored.</Note>
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
                                    { key: 'scope', label: 'Scope', render: (s) => scopeText(s.scope) },
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
                                    aside={<StatusBadge tone={SECTION_TONE[section.status] ?? 'neutral'}>{humanKey(section.status)}</StatusBadge>}
                                >
                                    {section.reason && <Note>{section.reason}</Note>}
                                    {section.notes.map((note, i) => (
                                        <Note key={i}>
                                            {humanKey(String(note.status ?? 'note'))}
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
                                                    render: (i) => <span className="font-medium">{valueText(i)}</span>,
                                                },
                                                { key: 'scope', label: 'Scope', render: (i) => scopeText(i.scope) },
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
                    Read-only. A stored briefing is never changed. Correlation id {briefing.correlation_id ?? 'none'}; capability{' '}
                    {briefing.capability} {briefing.capability_version}.
                </Note>
            </div>
        </AppLayout>
    );
}
