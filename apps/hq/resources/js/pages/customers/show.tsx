import { FollowUpsBody, type CustomerFollowUps } from '@/components/customers/follow-ups-section';
import { DataTable, Note, Panel, orDash } from '@/components/observe/panels';
import { JsonBlock, PageHeader, TechnicalDetail } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { StatusBadge, type Tone } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, humanKey } from '@/lib/format';
import { formatNumber, useT } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { type ReactNode } from 'react';

/**
 * Customer 360 (docs/109, ruling docs/103 §5). Read-only and business-scoped: one customer of one business.
 * Every section carries its kind (observed, inferred, source of truth, memory, consent record, promise,
 * hand-over, placeholder) and they are never blended. Nothing on this page changes anything.
 */

type Identity = {
    id: number;
    channel: string;
    external_id: string;
    store: string | null;
    verified_at: string | null;
    first_seen_at: string | null;
    last_seen_at: string | null;
};
type Merge = { id: number; absorbed_id: number; by_person: boolean; undone: boolean; cross_business: boolean; at: string | null };
type QuarantineMerge = { id: number; side: 'kept' | 'absorbed'; at: string | null; flagged: boolean; review: string | null };
type Quarantine = { held: boolean; withheld: string[]; merges: QuarantineMerge[]; link_held: boolean; link_flagged: boolean };
type IdentityLink = { identity_id: number; channel: string; rule: string; confidence: string | null };
type Candidate = { id: number; other_id: number; confidence: string | null; reason: string | null; at: string | null };
type Belief = { id: number; text: string; confidence: number | null; provenance: string; current: boolean; at: string | null };
type Order = {
    id: number;
    ref: string;
    owner_system: 'shop' | 'erp';
    store: string | null;
    erp_order_number: string | null;
    source: string | null;
    provenance: string | null;
    status: string;
    payment_status: string | null;
    gross: string;
    placed_at: string | null;
    delivered_at: string | null;
    cancelled_at: string | null;
    copy_updated_at: string | null;
};
type Thread = {
    uuid: string;
    channel: string;
    brand: string | null;
    state: string;
    state_label: string;
    status: string;
    messages: number;
    opened_at: string | null;
    last_message_at: string | null;
    closed_at: string | null;
};
type MemoryRow = {
    id: number;
    kind: string;
    key: string | null;
    text: string;
    provenance: string;
    source: string | null;
    current: boolean;
    at: string | null;
    closed_at: string | null;
};
type ConsentCurrent = {
    purpose: string | null;
    channel: string | null;
    state: string;
    permits: boolean;
    effective_from: string | null;
    expires_at: string | null;
};
type ConsentEvent = {
    id: number;
    purpose: string | null;
    channel: string | null;
    state: string;
    basis: string;
    occurred_at: string | null;
    recorded_at: string | null;
    expires_at: string | null;
    actor_type: string;
    has_evidence: boolean;
};
type Handover = {
    id: number;
    conversation_uuid: string;
    channel: string;
    direction: 'to_person' | 'to_agent';
    from: string;
    to: string;
    reason: string | null;
    label: string | null;
    by: string | null;
    at: string | null;
};

type Props = {
    customer: { id: number; name: string | null; business: { id: number; name: string; slug: string } | null };
    observed: {
        name: string | null;
        phone: string | null;
        email: string | null;
        first_seen_at: string | null;
        last_seen_at: string | null;
        identities: Identity[];
        merges: Merge[];
    };
    inferred: {
        identity_links: IdentityLink[];
        candidates: Candidate[];
        stranger: boolean;
        arrived_via: string | null;
        aliases: string[];
        orders_count: number;
        lifetime_value: string;
        beliefs: Belief[];
    };
    orders: { total: number; rows: Order[] };
    conversations: { total: number; rows: Thread[] } | null;
    memory: { rows: MemoryRow[] } | null;
    consent: { current: ConsentCurrent[]; ledger: ConsentEvent[]; legacy: Record<string, unknown> | null };
    follow_ups: CustomerFollowUps | null;
    handovers: { rows: Handover[] } | null;
    outcomes: { status: string };
    quarantine: Quarantine;
    can: { inbox: boolean; manage: boolean };
};

type Kind = 'observed' | 'inferred' | 'truth' | 'memory' | 'consent' | 'promise' | 'handover' | 'placeholder';

const KIND_LABELS: Record<Kind, string> = {
    observed: 'Observed',
    inferred: 'Inferred by HQ',
    truth: 'Source of truth: HQ copy',
    memory: 'Memory',
    consent: 'Consent record',
    promise: 'Promise',
    handover: 'Hand-over',
    placeholder: 'Placeholder',
};

const KIND_TONES: Record<Kind, Tone> = {
    observed: 'ok',
    inferred: 'pending',
    truth: 'info',
    memory: 'warn',
    consent: 'info',
    promise: 'neutral',
    handover: 'neutral',
    placeholder: 'neutral',
};

const RULE_LABELS: Record<string, string> = {
    canonical_phone: 'Same canonical phone',
    email: 'Same email',
    shop_record: 'A shop account or order',
    channel_id: 'An already linked channel id',
};

const taka = (v: string | number) => `৳${formatNumber(v)}`;

function KindBadge({ kind }: { kind: Kind }) {
    const t = useT();
    return (
        <StatusBadge tone={KIND_TONES[kind]} dot={false}>
            {t(KIND_LABELS[kind])}
        </StatusBadge>
    );
}

function Section({ kind, title, aside, explain, children }: { kind: Kind; title: string; aside?: ReactNode; explain: string; children: ReactNode }) {
    const t = useT();
    return (
        <Panel
            title={
                <span className="flex flex-wrap items-center gap-2">
                    {t(title)} <KindBadge kind={kind} />
                </span>
            }
            aside={aside}
        >
            <Note>{t(explain)}</Note>
            {children}
        </Panel>
    );
}

function Fact({ label, children }: { label: string; children: ReactNode }) {
    const t = useT();
    return (
        <div className="min-w-0">
            <dt className="text-muted-foreground text-xs">{t(label)}</dt>
            <dd className="text-sm break-words">{children}</dd>
        </div>
    );
}

/**
 * docs/117 (ruling docs/110 §5 (m)): what is held back because an earlier merge crossed a business boundary.
 * The server has already removed it; this panel only says that something is held, and which kinds.
 */
function QuarantinePanel({ quarantine, canReview }: { quarantine: Quarantine; canReview: boolean }) {
    const t = useT();
    const withheldLabel = (w: string) =>
        ({
            phone: t('Phone'),
            email: t('Email'),
            aggregates: t('Order count and lifetime value are counted from this business only'),
            identities: t('Identities moved by that merge'),
            memories: t('Memories that may have come from the other customer'),
            merge_link: t('The link to the other customer'),
            threads: t('Thread rows of another business'),
        })[w] ?? w;
    const decisionLabel = (d: string | null) =>
        d === null
            ? t('No review yet')
            : (({ confirmed_wrong: t('Confirmed wrong'), needs_repair: t('Needs repair'), disputed: t('Disputed (needs a ruling)') }) as Record<string, string>)[d] ??
              d;

    return (
        <div className="flex flex-col gap-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-950 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-100">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <strong>{t('Held for review: records that cross a business boundary')}</strong>
                {canReview && (
                    <Button asChild variant="outline" size="sm">
                        <Link href="/customers/quarantine">{t('Review flags')}</Link>
                    </Button>
                )}
            </div>
            <p>
                {t(
                    'An earlier merge, made before merges across businesses were refused, linked this customer with a customer of another business. What crossed the boundary is not shown here and nothing has been undone. A person reviews it; only a ruled repair can change it.',
                )}
            </p>
            {quarantine.withheld.length > 0 && (
                <div className="flex flex-wrap items-center gap-1">
                    <span className="text-xs">{t('Withheld')}:</span>
                    {quarantine.withheld.map((w) => (
                        <StatusBadge key={w} tone="warn" dot={false}>
                            {withheldLabel(w)}
                        </StatusBadge>
                    ))}
                </div>
            )}
            {quarantine.merges.length > 0 && (
                <DataTable
                    rowKey={(m) => `${m.side}-${m.id}`}
                    rows={quarantine.merges}
                    empty="No merges."
                    columns={[
                        { key: 'merge', label: 'Merge', render: (m) => <span className="font-mono text-xs">#{m.id}</span> },
                        { key: 'side', label: 'This customer was', render: (m) => (m.side === 'kept' ? t('kept') : t('absorbed')) },
                        { key: 'flagged', label: 'Flagged for review', render: (m) => (m.flagged ? t('yes') : t('not yet')) },
                        { key: 'review', label: 'Latest review', render: (m) => decisionLabel(m.review) },
                        { key: 'at', label: 'When', render: (m) => formatDateTime(m.at) },
                    ]}
                />
            )}
        </div>
    );
}

function NeedsInbox() {
    const t = useT();
    return (
        <div className="text-muted-foreground rounded-xl border border-dashed px-4 py-6 text-center text-sm">
            {t('Shown only to people who may read the inbox.')}
        </div>
    );
}

export default function CustomerShow(props: Props) {
    const t = useT();
    const { customer, observed, inferred, orders, conversations, memory, consent, handovers, quarantine } = props;
    const name = customer.name ?? t('Customer #{id}', { id: customer.id });
    // docs/108 (S7): the consent screen is reached from here by people who may read the ledger.
    const permissions = (usePage().props as { auth?: { permissions?: string[] } }).auth?.permissions ?? [];
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Customers', href: '/customers' },
        { title: name, href: `/customers/${customer.id}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={name} />
            <div className="flex max-w-6xl flex-col gap-8 p-4 md:p-6">
                <PageHeader
                    title={name}
                    description={
                        customer.business
                            ? t('A customer of {business} only. Read-only: nothing on this page changes anything.', {
                                  business: customer.business.name,
                              })
                            : t('Read-only: nothing on this page changes anything.')
                    }
                    actions={
                        permissions.includes('hq.consent.view') ? (
                            <Button asChild variant="outline" size="sm">
                                <Link href={`/customers/${customer.id}/consent`}>{t('Consent history')}</Link>
                            </Button>
                        ) : null
                    }
                />

                <div className="rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900 dark:border-sky-900 dark:bg-sky-950 dark:text-sky-200">
                    {t(
                        'Each section says what kind of information it holds. Observed facts, what HQ inferred, memory, consent records, promises, hand-overs and order copies are kept apart. The same phone in another business is a different customer and is not shown here.',
                    )}
                </div>

                {quarantine.held && <QuarantinePanel quarantine={quarantine} canReview={permissions.includes('hq.customers.quarantine.review')} />}

                {/* Observed */}
                <Section kind="observed" title="Identity" explain="What a channel, a shop or a person put on the record.">
                    <section className="bg-card rounded-xl border p-4">
                        <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                            <Fact label="Name">{orDash(observed.name)}</Fact>
                            <Fact label="Phone">{orDash(observed.phone)}</Fact>
                            <Fact label="Email">{orDash(observed.email)}</Fact>
                            <Fact label="First seen">{formatDateTime(observed.first_seen_at)}</Fact>
                            <Fact label="Last seen">{formatDateTime(observed.last_seen_at)}</Fact>
                        </dl>
                    </section>
                    <DataTable
                        rowKey={(i) => String(i.id)}
                        rows={observed.identities}
                        empty="No identities linked."
                        columns={[
                            { key: 'channel', label: 'Channel', render: (i) => humanKey(i.channel) },
                            { key: 'value', label: 'Identity', render: (i) => <span className="font-mono text-xs break-all">{i.external_id}</span> },
                            { key: 'store', label: 'Store', render: (i) => orDash(i.store) },
                            { key: 'verified', label: 'Verified', render: (i) => formatDateTime(i.verified_at) },
                            { key: 'first', label: 'First seen', render: (i) => formatDateTime(i.first_seen_at) },
                            { key: 'last', label: 'Last seen', render: (i) => formatDateTime(i.last_seen_at) },
                        ]}
                    />
                    {observed.merges.length > 0 && (
                        <DataTable
                            rowKey={(m) => String(m.id)}
                            rows={observed.merges}
                            empty="No merges."
                            columns={[
                                { key: 'absorbed', label: 'Merged in', render: (m) => t('Customer #{id}', { id: m.absorbed_id }) },
                                { key: 'by', label: 'Decided by', render: (m) => (m.by_person ? t('a person') : t('the system')) },
                                {
                                    key: 'flags',
                                    label: 'Notes',
                                    render: (m) => (
                                        <div className="flex flex-wrap gap-1">
                                            {m.undone && (
                                                <StatusBadge tone="neutral" dot={false}>
                                                    {t('undone')}
                                                </StatusBadge>
                                            )}
                                            {m.cross_business && (
                                                <StatusBadge tone="danger" dot={false}>
                                                    {t('across businesses: not allowed')}
                                                </StatusBadge>
                                            )}
                                        </div>
                                    ),
                                },
                                { key: 'at', label: 'When', render: (m) => formatDateTime(m.at) },
                            ]}
                        />
                    )}
                </Section>

                {/* Inferred */}
                <Section
                    kind="inferred"
                    title="What HQ inferred or computed"
                    explain="HQ's own reading of the record, never a fact about the person. Nothing here is acted on automatically; merges are decided by a person on the customer list."
                >
                    <section className="bg-card rounded-xl border p-4">
                        <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <Fact label="Order copies counted">{formatNumber(inferred.orders_count)}</Fact>
                            <Fact label="Lifetime value from copies">{taka(inferred.lifetime_value)}</Fact>
                            <Fact label="Stranger">
                                {inferred.stranger ? t('yes: known only by a channel id') : t('no')}
                                {inferred.arrived_via ? ` (${humanKey(inferred.arrived_via)})` : ''}
                            </Fact>
                            <Fact label="Also known as">{inferred.aliases.length > 0 ? inferred.aliases.join(', ') : '-'}</Fact>
                        </dl>
                    </section>
                    <DataTable
                        rowKey={(l) => String(l.identity_id)}
                        rows={inferred.identity_links}
                        empty="No identities linked."
                        columns={[
                            { key: 'channel', label: 'Channel', render: (l) => humanKey(l.channel) },
                            { key: 'rule', label: 'Linked because', render: (l) => t(RULE_LABELS[l.rule] ?? l.rule) },
                            { key: 'confidence', label: 'Confidence', align: 'right', render: (l) => orDash(l.confidence) },
                        ]}
                    />
                    <DataTable
                        rowKey={(c) => String(c.id)}
                        rows={inferred.candidates}
                        empty="No possible duplicates waiting for a person."
                        columns={[
                            {
                                key: 'other',
                                label: 'Possible duplicate',
                                render: (c) => (
                                    <Link href={`/customers/${c.other_id}`} className="underline">
                                        {t('Customer #{id}', { id: c.other_id })}
                                    </Link>
                                ),
                            },
                            { key: 'reason', label: 'Why', render: (c) => orDash(c.reason) },
                            { key: 'confidence', label: 'Confidence', align: 'right', render: (c) => orDash(c.confidence) },
                        ]}
                    />
                    {inferred.beliefs.length > 0 && (
                        <DataTable
                            rowKey={(b) => String(b.id)}
                            rows={inferred.beliefs}
                            empty="No beliefs."
                            columns={[
                                { key: 'text', label: 'Belief', render: (b) => <span className="whitespace-pre-wrap">{b.text}</span> },
                                { key: 'confidence', label: 'Confidence', align: 'right', render: (b) => orDash(b.confidence) },
                                { key: 'provenance', label: 'Provenance', render: (b) => humanKey(b.provenance) },
                                { key: 'at', label: 'When', render: (b) => formatDateTime(b.at) },
                            ]}
                        />
                    )}
                </Section>

                {/* Source-of-truth orders */}
                <Section
                    kind="truth"
                    title="Orders"
                    aside={t('{shown} of {total} shown', { shown: orders.rows.length, total: orders.total })}
                    explain="HQ holds copies. The shop or the ERP named on each row is the truth; the copy may be behind it. Check there before acting on a status."
                >
                    <DataTable
                        rowKey={(o) => String(o.id)}
                        rows={orders.rows}
                        empty="No order copies recorded."
                        columns={[
                            { key: 'ref', label: 'Order', render: (o) => <span className="font-mono text-xs">{o.ref}</span> },
                            {
                                key: 'truth',
                                label: 'Truth held by',
                                render: (o) =>
                                    o.owner_system === 'erp' ? (
                                        <span>
                                            {t('ERP')}
                                            {o.erp_order_number ? (
                                                <span className="text-muted-foreground font-mono text-xs"> {o.erp_order_number}</span>
                                            ) : null}
                                        </span>
                                    ) : (
                                        <span>
                                            {t('Shop')}
                                            {o.store ? <span className="text-muted-foreground text-xs"> {o.store}</span> : null}
                                        </span>
                                    ),
                            },
                            {
                                key: 'source',
                                label: 'Came in by',
                                render: (o) => orDash(o.source ? humanKey(o.source) : o.provenance ? humanKey(o.provenance) : null),
                            },
                            {
                                key: 'status',
                                label: 'Status in the copy',
                                render: (o) => (
                                    <div className="flex flex-wrap gap-1">
                                        <StatusBadge status={o.status} />
                                        {o.payment_status && (
                                            <StatusBadge status={o.payment_status} dot={false}>
                                                {humanKey(o.payment_status).toLowerCase()}
                                            </StatusBadge>
                                        )}
                                    </div>
                                ),
                            },
                            { key: 'gross', label: 'Amount', align: 'right', render: (o) => taka(o.gross) },
                            { key: 'placed', label: 'Placed', render: (o) => formatDateTime(o.placed_at) },
                            { key: 'copy', label: 'Copy last updated', render: (o) => formatDateTime(o.copy_updated_at) },
                        ]}
                    />
                </Section>

                {/* Conversations */}
                <Section
                    kind="observed"
                    title="Conversations"
                    aside={
                        conversations ? t('{shown} of {total} shown', { shown: conversations.rows.length, total: conversations.total }) : undefined
                    }
                    explain="Threads with this customer in this business. Open a thread in the inbox to read it."
                >
                    {conversations === null ? (
                        <NeedsInbox />
                    ) : (
                        <DataTable
                            rowKey={(c) => c.uuid}
                            rows={conversations.rows}
                            empty="No conversations recorded."
                            columns={[
                                {
                                    key: 'thread',
                                    label: 'Thread',
                                    render: (c) => (
                                        <Link href={`/inbox/${c.uuid}`} className="underline">
                                            {humanKey(c.channel)}
                                            {c.brand ? ` · ${c.brand}` : ''}
                                        </Link>
                                    ),
                                },
                                { key: 'state', label: 'State', render: (c) => <StatusBadge status={c.status}>{t(c.state_label)}</StatusBadge> },
                                { key: 'messages', label: 'Messages', align: 'right', render: (c) => formatNumber(c.messages) },
                                { key: 'opened', label: 'Opened', render: (c) => formatDateTime(c.opened_at) },
                                { key: 'last', label: 'Last message', render: (c) => formatDateTime(c.last_message_at) },
                            ]}
                        />
                    )}
                </Section>

                {/* Hand-overs */}
                <Section
                    kind="handover"
                    title="Hand-overs"
                    explain="Every audited move of a thread between the agent and a person, with the reason recorded at the time."
                >
                    {handovers === null ? (
                        <NeedsInbox />
                    ) : (
                        <DataTable
                            rowKey={(h) => String(h.id)}
                            rows={handovers.rows}
                            empty="No hand-overs recorded."
                            columns={[
                                {
                                    key: 'direction',
                                    label: 'Direction',
                                    render: (h) => (h.direction === 'to_person' ? t('Agent to a person') : t('A person to the agent')),
                                },
                                { key: 'reason', label: 'Reason', render: (h) => (h.label ? t(h.label) : '-') },
                                { key: 'by', label: 'By', render: (h) => h.by ?? t('the system') },
                                {
                                    key: 'thread',
                                    label: 'Thread',
                                    render: (h) => (
                                        <Link href={`/inbox/${h.conversation_uuid}`} className="underline">
                                            {humanKey(h.channel)}
                                        </Link>
                                    ),
                                },
                                { key: 'at', label: 'When', render: (h) => formatDateTime(h.at) },
                            ]}
                        />
                    )}
                </Section>

                {/* Memory */}
                <Section
                    kind="memory"
                    title="Memory"
                    explain="What a colleague chose to remember, with where it came from. Memory is not truth: weigh it by its source and date."
                >
                    {memory === null ? (
                        <NeedsInbox />
                    ) : (
                        <DataTable
                            rowKey={(m) => String(m.id)}
                            rows={memory.rows}
                            empty="Nothing remembered."
                            columns={[
                                { key: 'kind', label: 'Kind', render: (m) => (m.kind === 'structured' ? t('Fact') : t('Episode')) },
                                { key: 'key', label: 'About', render: (m) => (m.key ? humanKey(m.key) : '-') },
                                { key: 'text', label: 'Text', render: (m) => <span className="whitespace-pre-wrap">{m.text}</span> },
                                { key: 'provenance', label: 'Provenance', render: (m) => humanKey(m.provenance) },
                                {
                                    key: 'current',
                                    label: 'Current',
                                    render: (m) =>
                                        m.current ? (
                                            <StatusBadge tone="ok" dot={false}>
                                                {t('current')}
                                            </StatusBadge>
                                        ) : (
                                            <StatusBadge tone="neutral" dot={false}>
                                                {t('closed')}
                                            </StatusBadge>
                                        ),
                                },
                                { key: 'at', label: 'When', render: (m) => formatDateTime(m.at) },
                            ]}
                        />
                    )}
                </Section>

                {/* Consent */}
                <Section
                    kind="consent"
                    title="Consent"
                    explain="The current position is derived from the ledger below; if they ever disagree, the ledger is right. Evidence and who recorded it stay in the ledger itself."
                >
                    <DataTable
                        rowKey={(c, i) => `${c.purpose ?? 'all'}-${c.channel ?? 'all'}-${i}`}
                        rows={consent.current}
                        empty="No consent recorded."
                        columns={[
                            { key: 'purpose', label: 'Purpose', render: (c) => (c.purpose ? humanKey(c.purpose) : t('every purpose')) },
                            { key: 'channel', label: 'Channel', render: (c) => (c.channel ? humanKey(c.channel) : t('every channel')) },
                            {
                                key: 'state',
                                label: 'State',
                                render: (c) => (
                                    <StatusBadge tone={c.permits ? 'ok' : 'danger'} dot={false}>
                                        {humanKey(c.state)}
                                    </StatusBadge>
                                ),
                            },
                            { key: 'from', label: 'Since', render: (c) => formatDateTime(c.effective_from) },
                            { key: 'expires', label: 'Expires', render: (c) => formatDateTime(c.expires_at) },
                        ]}
                    />
                    <DataTable
                        rowKey={(e) => String(e.id)}
                        rows={consent.ledger}
                        empty="The ledger holds no events for this customer."
                        columns={[
                            { key: 'purpose', label: 'Purpose', render: (e) => (e.purpose ? humanKey(e.purpose) : t('every purpose')) },
                            { key: 'channel', label: 'Channel', render: (e) => (e.channel ? humanKey(e.channel) : t('every channel')) },
                            { key: 'state', label: 'State', render: (e) => humanKey(e.state) },
                            { key: 'basis', label: 'Basis', render: (e) => humanKey(e.basis) },
                            { key: 'occurred', label: 'Happened', render: (e) => formatDateTime(e.occurred_at) },
                            { key: 'recorded', label: 'Recorded', render: (e) => formatDateTime(e.recorded_at) },
                            { key: 'actor', label: 'Recorded by', render: (e) => humanKey(e.actor_type) },
                            { key: 'evidence', label: 'Evidence', render: (e) => (e.has_evidence ? t('yes') : t('no')) },
                        ]}
                    />
                    {consent.legacy && (
                        <TechnicalDetail summary={t('Legacy consent field (not the ledger)')}>
                            <JsonBlock value={consent.legacy} />
                        </TechnicalDetail>
                    )}
                </Section>

                {/* Follow-ups, promises and opportunities (docs/123): read-only */}
                <Section
                    kind="promise"
                    title="Follow-ups and promises"
                    explain="What the business planned or promised to do for this customer, and the interests it is tracking. Read-only here; decisions are made on the Follow-ups screen. Nothing is inferred from message text."
                >
                    <FollowUpsBody data={props.follow_ups} />
                </Section>

                {/* Outcomes: S3 placeholder */}
                <Section
                    kind="placeholder"
                    title="Outcomes"
                    explain="Waits for the canonical outcome model (S3). Nothing is computed here until it is agreed."
                >
                    <div className="text-muted-foreground rounded-xl border border-dashed px-4 py-6 text-center text-sm">{t('Not built yet.')}</div>
                </Section>
            </div>
        </AppLayout>
    );
}
