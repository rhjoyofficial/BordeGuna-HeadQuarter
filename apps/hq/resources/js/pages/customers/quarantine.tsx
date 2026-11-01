import { EmptyState } from '@/components/empty-state';
import { Field, FormRow, NativeSelect } from '@/components/form/field';
import { PageHeader, SectionHeader } from '@/components/page-header';
import { type ListMeta, Pagination } from '@/components/pagination';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Notice } from '@/components/ui/notice';
import { StatusBadge, type Tone } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

/**
 * docs/117 (ruling docs/110 §5 (m)): quarantine flags on relationships that cross a business boundary, and
 * the audited human review of each. A review records a decision only: it lifts no suppression, undoes no
 * merge and re-points nothing. A flag is listed only to a person who may see both of its businesses.
 *
 * docs/126 (ruling docs/120 A3, item 36): the repair the reviews lead to. The batches the dry run proposes, each
 * row's review state, the ambiguous rows (never moved), and, for a person holding hq.customers.quarantine.repair,
 * the button that asks the Tool Gateway to apply ONE batch whose every row was reviewed, and the assignment of a
 * business to a memory the repair withheld.
 *
 * 31 Oct (live): the review form opened at the foot of the page, the owner never saw it and his review was not saved.
 * Now Review opens a dialog in front of the page that names the flag, puts the cursor on its first field and keeps
 * the row highlighted; a saved review closes it, scrolls back to the row, marks it and says so in words.
 */

type Review = { decision: string; note: string; by: string | null; at: string | null };
type Flag = {
    id: number;
    kind: string;
    subject: string;
    customer_id: number;
    customer_business: string | null;
    row_business: string | null;
    merge_id: number | null;
    reason: string;
    detected_at: string | null;
    reviews: number;
    review: Review | null;
};
type SplitReview = {
    needs_repair: number;
    disputed: number;
    confirmed_wrong: number;
    unflagged: number;
    authorized: boolean;
    unreviewed_rows: string[];
    flag_ids: number[];
};
type Split = {
    key: string;
    from_customer_id: number;
    from_business: string | null;
    to_customer_id: number | null;
    merge_id: number | null;
    counts: Record<string, number>;
    review: SplitReview;
};
type Batch = { key: string; business_id: number; business: string; authorized: boolean; splits: Split[] };
type Ambiguous = { subject: string; customer_id: number; reason: string; why: string };
type HeldMemory = {
    memory_id: number;
    customer_id: number;
    customer_business: string | null;
    kind: string;
    created_at: string | null;
    excerpt: string | null;
    choice_names: { id: number; name: string }[];
};
type Repair = {
    id: number;
    key: string;
    business: string | null;
    status: string;
    started_at: string | null;
    verified_at: string | null;
    splits: number;
};
type Props = {
    flags: Flag[];
    pagination: ListMeta;
    decisions: string[];
    can_repair: boolean;
    batches: Batch[];
    ambiguous: Ambiguous[];
    held_memories: HeldMemory[];
    repairs: Repair[];
};

const DECISION_TONES: Record<string, Tone> = { confirmed_wrong: 'danger', needs_repair: 'warn', disputed: 'info' };

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Customers', href: '/customers' },
    { title: 'Cross-business review', href: '/customers/quarantine' },
];

export default function CustomerQuarantine({ flags, pagination, decisions, can_repair, batches, ambiguous, held_memories, repairs }: Props) {
    const t = useT();
    const [reviewing, setReviewing] = useState<Flag | null>(null);
    const [saved, setSaved] = useState<{ id: number; decision: string } | null>(null);
    const form = useForm({ decision: decisions[0] ?? '', note: '' });
    const rowRefs = useRef<Record<number, HTMLTableRowElement | null>>({});
    const savedRef = useRef<HTMLDivElement | null>(null);
    const decisionRef = useRef<HTMLSelectElement | null>(null);
    const errors = form.errors as Record<string, string | undefined>;
    const otherError = Object.entries(errors).find(([k, v]) => k !== 'decision' && k !== 'note' && v)?.[1];

    // After a saved review: bring the row (or, on another page, the confirmation) back into view.
    useEffect(() => {
        if (saved === null) return;
        const row = rowRefs.current[saved.id];
        (row ?? savedRef.current)?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }, [saved]);

    const startReview = (f: Flag) => {
        form.reset();
        form.clearErrors();
        setSaved(null);
        setReviewing(f);
    };
    const savedFlag = saved === null ? null : (flags.find((f) => f.id === saved.id) ?? null);

    // A repair or a memory assignment is confirmed at the top of the Repair section, where the batch was; the batch
    // itself leaves the list once applied, so without this the screen would just look shorter.
    const [done, setDone] = useState<string | null>(null);
    const doneRef = useRef<HTMLDivElement | null>(null);
    useEffect(() => {
        if (done !== null) doneRef.current?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }, [done]);

    const decisionLabel = (d: string) =>
        (
            ({ confirmed_wrong: t('Confirmed wrong'), needs_repair: t('Needs repair'), disputed: t('Disputed (needs a ruling)') }) as Record<
                string,
                string
            >
        )[d] ?? d;
    const decisionHint = (d: string) =>
        (
            ({
                confirmed_wrong: t('This relationship should not exist.'),
                needs_repair: t('It should be repaired: the rows go to a customer of their own business, with lineage.'),
                disputed: t('You believe it is legitimate. Explain why; it stays held, because merges across businesses are not allowed.'),
            }) as Record<string, string>
        )[d] ?? '';
    const kindLabel = (k: string) =>
        (
            ({
                merge: t('Merge'),
                merge_pointer: t('Merge link'),
                identity: t('Identity'),
                order: t('Order copy'),
                conversation: t('Conversation'),
                memory: t('Memory'),
                consent_event: t('Consent event'),
                consent_state: t('Consent position'),
                participant: t('Thread participant'),
                message: t('Message'),
                identity_conflict: t('Identity conflict'),
            }) as Record<string, string>
        )[k] ?? k;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Cross-business review')} />
            <div className="flex max-w-6xl flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={t('Cross-business review')}
                    description={t(
                        'Relationships that cross a business boundary, left by merges made before they were refused. They are already hidden from every customer page. Your review is recorded and audited; it does not undo, move or delete anything.',
                    )}
                />

                <section className="flex flex-col gap-2">
                    <SectionHeader title={t('Flags')} aside={t('{count} flags', { count: pagination.total })} />
                    {saved !== null && (
                        <div ref={savedRef}>
                            <Notice tone="ok" title={t('Review saved for flag #{id}', { id: saved.id })}>
                                {t('Recorded as: {decision}. The row below now shows it as the latest review.', {
                                    decision: decisionLabel(savedFlag?.review?.decision ?? saved.decision),
                                })}
                            </Notice>
                        </div>
                    )}
                    {flags.length === 0 ? (
                        <EmptyState
                            title={t('Nothing is flagged')}
                            description={t('No relationship crossing a business boundary has been flagged for review.')}
                        />
                    ) : (
                        <div className="hq-table-box">
                            <table className="w-full text-sm">
                                <thead className="text-left text-xs">
                                    <tr>
                                        <th className="px-3 py-2 font-medium">#</th>
                                        <th className="px-3 py-2 font-medium">{t('Kind')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Record')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Customer')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Record business')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Reason')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Latest review')}</th>
                                        <th className="px-3 py-2" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {flags.map((f) => (
                                        <tr
                                            key={f.id}
                                            ref={(el) => {
                                                rowRefs.current[f.id] = el;
                                            }}
                                            data-flag-id={f.id}
                                            aria-selected={reviewing?.id === f.id || saved?.id === f.id}
                                            className={cn(
                                                'border-t align-top transition-colors',
                                                reviewing?.id === f.id && 'bg-tone-info-bg outline-tone-info-line outline-2 -outline-offset-2',
                                                saved?.id === f.id &&
                                                    reviewing === null &&
                                                    'bg-tone-ok-bg outline-tone-ok-line outline-2 -outline-offset-2',
                                            )}
                                        >
                                            <td className="px-3 py-2 font-mono text-xs">{f.id}</td>
                                            <td className="px-3 py-2 text-xs">{kindLabel(f.kind)}</td>
                                            <td className="px-3 py-2 font-mono text-xs break-all">
                                                {f.subject}
                                                {f.merge_id !== null ? (
                                                    <div className="text-muted-foreground">{t('Merge #{id}', { id: f.merge_id })}</div>
                                                ) : (
                                                    <div className="text-muted-foreground">{t('No merge lineage')}</div>
                                                )}
                                            </td>
                                            <td className="px-3 py-2 text-xs">
                                                <Link href={`/customers/${f.customer_id}`} className="hover:underline">
                                                    {t('Customer #{id}', { id: f.customer_id })}
                                                </Link>
                                                <div className="text-muted-foreground">{f.customer_business ?? '-'}</div>
                                            </td>
                                            <td className="px-3 py-2 text-xs">{f.row_business ?? t('No business on the record')}</td>
                                            <td className="px-3 py-2 font-mono text-xs break-all">
                                                {f.reason}
                                                <div className="text-muted-foreground font-sans">{formatDateTime(f.detected_at)}</div>
                                            </td>
                                            <td className="px-3 py-2 text-xs">
                                                {f.review === null ? (
                                                    <span className="text-muted-foreground">{t('No review yet')}</span>
                                                ) : (
                                                    <div className="flex flex-col gap-1">
                                                        {saved?.id === f.id && (
                                                            <span className="text-tone-ok font-semibold">{t('Saved just now')}</span>
                                                        )}
                                                        <StatusBadge tone={DECISION_TONES[f.review.decision] ?? 'neutral'} dot={false}>
                                                            {decisionLabel(f.review.decision)}
                                                        </StatusBadge>
                                                        <div className="break-words">{f.review.note}</div>
                                                        <div className="text-muted-foreground break-all">
                                                            {f.review.by ?? '-'}, {formatDateTime(f.review.at)}
                                                        </div>
                                                        {f.reviews > 1 && (
                                                            <div className="text-muted-foreground">
                                                                {t('{count} reviews in all', { count: f.reviews })}
                                                            </div>
                                                        )}
                                                    </div>
                                                )}
                                            </td>
                                            <td className="px-3 py-2 text-right">
                                                <Button variant={f.review === null ? 'default' : 'outline'} size="sm" onClick={() => startReview(f)}>
                                                    {f.review === null ? t('Review') : t('Review again')}
                                                </Button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                    <Pagination meta={pagination} />
                </section>

                <section className="flex flex-col gap-2">
                    <SectionHeader title={t('Repair')} aside={t('{count} batches proposed', { count: batches.length })} />
                    {done !== null && (
                        <div ref={doneRef}>
                            <Notice tone="ok">{done}</Notice>
                        </div>
                    )}
                    <p className="text-muted-foreground text-sm">
                        {t(
                            'The dry run: rows of one business that hang under a customer of another, and the customer of their own business they would go to. A batch can be applied only when every row in it has a review, at least one says Needs repair and none is disputed. Nothing is deleted; the original merge row stays; every move is recorded.',
                        )}
                    </p>
                    {batches.length === 0 ? (
                        <EmptyState title={t('Nothing to repair')} description={t('No row of one business hangs under a customer of another.')} />
                    ) : (
                        batches.map((b) => (
                            <RepairBatch
                                key={b.key}
                                batch={b}
                                canRepair={can_repair}
                                onDone={() =>
                                    setDone(
                                        t('Repair batch for {business} applied and verified. It is listed under Repair history.', {
                                            business: b.business,
                                        }),
                                    )
                                }
                            />
                        ))
                    )}
                </section>

                {ambiguous.length > 0 && (
                    <section className="flex flex-col gap-2">
                        <SectionHeader
                            title={t('Not repaired: the business cannot be established')}
                            aside={t('{count} rows', { count: ambiguous.length })}
                        />
                        <ul className="hq-card flex flex-col divide-y text-xs">
                            {ambiguous.map((a) => (
                                <li key={`${a.subject}-${a.reason}`} className="flex flex-col gap-1 px-3 py-2">
                                    <span className="font-mono break-all">{a.subject}</span>
                                    <span className="text-muted-foreground">{t('Customer #{id}', { id: a.customer_id })}</span>
                                    <span className="break-words">{a.why}</span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                {held_memories.length > 0 && (
                    <section className="flex flex-col gap-2">
                        <SectionHeader
                            title={t('Memories held until a person assigns a business')}
                            aside={t('{count} memories', { count: held_memories.length })}
                        />
                        <ul className="hq-card flex flex-col divide-y text-sm">
                            {held_memories.map((m) => (
                                <HeldMemoryRow
                                    key={m.memory_id}
                                    memory={m}
                                    canAssign={can_repair}
                                    onDone={() => setDone(t('Memory #{id} assigned to its business.', { id: m.memory_id }))}
                                />
                            ))}
                        </ul>
                    </section>
                )}

                {repairs.length > 0 && (
                    <section className="flex flex-col gap-2">
                        <SectionHeader title={t('Repair history')} />
                        <ul className="hq-card flex flex-col divide-y text-xs">
                            {repairs.map((r) => (
                                <li key={r.id} className="flex flex-wrap items-center gap-2 px-3 py-2">
                                    <span className="font-mono">#{r.id}</span>
                                    <span>{r.business ?? '-'}</span>
                                    <StatusBadge tone={r.status === 'verified' ? 'ok' : 'warn'} dot={false}>
                                        {r.status}
                                    </StatusBadge>
                                    <span className="font-mono break-all">{r.key.slice(0, 16)}</span>
                                    <span className="text-muted-foreground">{t('{count} splits', { count: r.splits })}</span>
                                    <span className="text-muted-foreground">{formatDateTime(r.verified_at ?? r.started_at)}</span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                <Dialog
                    open={reviewing !== null}
                    onOpenChange={(o) => {
                        if (!o && !form.processing) setReviewing(null);
                    }}
                >
                    <DialogContent
                        className="sm:max-w-xl"
                        onOpenAutoFocus={(e) => {
                            e.preventDefault();
                            decisionRef.current?.focus();
                        }}
                    >
                        <DialogHeader>
                            <DialogTitle>{t('Review flag #{id}', { id: reviewing?.id ?? 0 })}</DialogTitle>
                            <DialogDescription>
                                {t('Your review is recorded and audited; it does not undo, move or delete anything.')}
                            </DialogDescription>
                        </DialogHeader>
                        {reviewing !== null && (
                            <dl className="border-glass-border bg-muted grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 rounded-lg border px-3 py-2 text-xs">
                                <dt className="text-muted-foreground">{t('Kind')}</dt>
                                <dd>{kindLabel(reviewing.kind)}</dd>
                                <dt className="text-muted-foreground">{t('Record')}</dt>
                                <dd className="font-mono break-all">{reviewing.subject}</dd>
                                <dt className="text-muted-foreground">{t('Customer')}</dt>
                                <dd>
                                    {t('Customer #{id}', { id: reviewing.customer_id })} ({reviewing.customer_business ?? '-'})
                                </dd>
                                <dt className="text-muted-foreground">{t('Record business')}</dt>
                                <dd>{reviewing.row_business ?? t('No business on the record')}</dd>
                                <dt className="text-muted-foreground">{t('Reason')}</dt>
                                <dd className="font-mono break-all">{reviewing.reason}</dd>
                                {reviewing.review !== null && (
                                    <>
                                        <dt className="text-muted-foreground">{t('Latest review')}</dt>
                                        <dd>{decisionLabel(reviewing.review.decision)}</dd>
                                    </>
                                )}
                            </dl>
                        )}
                        <form
                            className="flex flex-col gap-4"
                            onSubmit={(e) => {
                                e.preventDefault();
                                if (reviewing === null) return;
                                const id = reviewing.id;
                                const decision = form.data.decision;
                                form.post(`/customers/quarantine/${id}/review`, {
                                    preserveScroll: true,
                                    onSuccess: () => {
                                        form.reset();
                                        setReviewing(null);
                                        setSaved({ id, decision });
                                    },
                                });
                            }}
                        >
                            {otherError && (
                                <Notice tone="danger" alert title={t('The review was not saved')}>
                                    {otherError}
                                </Notice>
                            )}
                            <FormRow>
                                <Field
                                    label={t('Decision')}
                                    htmlFor="decision"
                                    size="auto"
                                    hint={decisionHint(form.data.decision)}
                                    error={form.errors.decision}
                                >
                                    <NativeSelect
                                        ref={decisionRef}
                                        id="decision"
                                        value={form.data.decision}
                                        onChange={(e) => form.setData('decision', e.target.value)}
                                        required
                                    >
                                        {decisions.map((d) => (
                                            <option key={d} value={d}>
                                                {decisionLabel(d)}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </Field>
                            </FormRow>
                            <Field label={t('Note')} htmlFor="note" error={form.errors.note}>
                                <Textarea
                                    id="note"
                                    value={form.data.note}
                                    onChange={(e) => form.setData('note', e.target.value)}
                                    rows={3}
                                    minLength={3}
                                    maxLength={1000}
                                    required
                                />
                            </Field>
                            <div className="flex flex-wrap justify-end gap-2">
                                <Button type="button" variant="outline" disabled={form.processing} onClick={() => setReviewing(null)}>
                                    {t('Cancel')}
                                </Button>
                                <Button type="submit" disabled={form.processing}>
                                    {form.processing ? t('Saving...') : t('Record review')}
                                </Button>
                            </div>
                        </form>
                    </DialogContent>
                </Dialog>
            </div>
        </AppLayout>
    );
}

function RepairBatch({ batch, canRepair, onDone }: { batch: Batch; canRepair: boolean; onDone: () => void }) {
    const t = useT();
    const form = useForm({ business_id: batch.business_id, key: batch.key, reason: '' });
    const errors = form.errors as Record<string, string | undefined>;

    return (
        <div className="hq-card flex flex-col gap-3 p-4 text-sm">
            <div className="flex flex-wrap items-center gap-2">
                <strong>{t('Batch for {business}', { business: batch.business })}</strong>
                <StatusBadge tone={batch.authorized ? 'ok' : 'warn'} dot={false}>
                    {batch.authorized ? t('Every row reviewed') : t('Waiting for reviews')}
                </StatusBadge>
                <span className="text-muted-foreground font-mono text-xs break-all">{batch.key}</span>
            </div>
            <ul className="flex flex-col gap-2">
                {batch.splits.map((s) => (
                    <li key={s.key} className="border-glass-border bg-muted flex flex-col gap-1 rounded-lg border px-3 py-2 text-xs">
                        <span>
                            {t('From customer #{from} ({business}) to {target}', {
                                from: s.from_customer_id,
                                business: s.from_business ?? '-',
                                target: s.to_customer_id === null ? t('a new customer') : t('customer #{id}', { id: s.to_customer_id }),
                            })}
                        </span>
                        <span className="text-muted-foreground">
                            {s.merge_id === null ? t('No merge lineage') : t('Reverses merge #{id}; the merge row stays', { id: s.merge_id })}
                        </span>
                        <span>
                            {t(
                                '{orders} orders, {conversations} conversations, {participants} participants, {messages} messages, {identities} identities, {conflicts} conflicts',
                                {
                                    orders: s.counts.orders ?? 0,
                                    conversations: s.counts.conversations ?? 0,
                                    participants: s.counts.participants ?? 0,
                                    messages: s.counts.messages ?? 0,
                                    identities: s.counts.identities ?? 0,
                                    conflicts: s.counts.conflicts ?? 0,
                                },
                            )}
                        </span>
                        <span className="text-muted-foreground">
                            {t('Held for a person: {memories} memories, {consent} consent records to re-record', {
                                memories: s.counts.memories_withheld ?? 0,
                                consent: s.counts.consent_rerecord ?? 0,
                            })}
                        </span>
                        <span>
                            {t('Reviewed rows: {needs} need repair, {wrong} confirmed wrong, {disputed} disputed, {open} not reviewed', {
                                needs: s.review.needs_repair,
                                wrong: s.review.confirmed_wrong,
                                disputed: s.review.disputed,
                                open: s.review.unflagged,
                            })}
                        </span>
                        {s.review.unreviewed_rows.length > 0 && (
                            <span className="text-tone-pending font-mono break-all">{s.review.unreviewed_rows.join(', ')}</span>
                        )}
                    </li>
                ))}
            </ul>
            {canRepair && batch.authorized && (
                <form
                    className="flex flex-col gap-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/customers/quarantine/repair', { preserveScroll: true, onSuccess: onDone });
                    }}
                >
                    <Field
                        label={t('Why (recorded on the batch, every move and the audit)')}
                        htmlFor={`reason-${batch.key}`}
                        error={errors.reason ?? errors.repair}
                    >
                        <Textarea
                            id={`reason-${batch.key}`}
                            value={form.data.reason}
                            onChange={(e) => form.setData('reason', e.target.value)}
                            rows={2}
                            minLength={10}
                            maxLength={2000}
                            required
                        />
                    </Field>
                    <div>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing ? t('Saving...') : t('Apply this repair batch')}
                        </Button>
                    </div>
                </form>
            )}
        </div>
    );
}

function HeldMemoryRow({ memory, canAssign, onDone }: { memory: HeldMemory; canAssign: boolean; onDone: () => void }) {
    const t = useT();
    const form = useForm({ business_id: memory.choice_names[0]?.id ?? 0, note: '' });
    const errors = form.errors as Record<string, string | undefined>;

    return (
        <li className="flex flex-col gap-2 px-3 py-2">
            <div className="flex flex-wrap items-center gap-2 text-xs">
                <span className="font-mono">{t('Memory #{id}', { id: memory.memory_id })}</span>
                <Link href={`/customers/${memory.customer_id}`} className="hover:underline">
                    {t('Customer #{id}', { id: memory.customer_id })}
                </Link>
                <span className="text-muted-foreground">{memory.customer_business ?? '-'}</span>
                <span className="text-muted-foreground">{formatDateTime(memory.created_at)}</span>
            </div>
            {memory.excerpt !== null && <p className="break-words">{memory.excerpt}</p>}
            {canAssign && (
                <form
                    className="flex flex-col gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(`/customers/quarantine/memories/${memory.memory_id}/assign`, { preserveScroll: true, onSuccess: onDone });
                    }}
                >
                    <FormRow>
                        <Field
                            label={t('Belongs to')}
                            htmlFor={`business-${memory.memory_id}`}
                            size="auto"
                            error={errors.business_id ?? errors.memory}
                        >
                            <NativeSelect
                                id={`business-${memory.memory_id}`}
                                value={String(form.data.business_id)}
                                onChange={(e) => form.setData('business_id', Number(e.target.value))}
                            >
                                {memory.choice_names.map((c) => (
                                    <option key={c.id} value={c.id}>
                                        {c.name}
                                    </option>
                                ))}
                            </NativeSelect>
                        </Field>
                    </FormRow>
                    <Field label={t('Note')} htmlFor={`note-${memory.memory_id}`} error={errors.note}>
                        <Textarea
                            id={`note-${memory.memory_id}`}
                            value={form.data.note}
                            onChange={(e) => form.setData('note', e.target.value)}
                            rows={2}
                            minLength={3}
                            maxLength={1000}
                            required
                        />
                    </Field>
                    <div>
                        <Button type="submit" variant="outline" size="sm" disabled={form.processing}>
                            {t('Assign business')}
                        </Button>
                    </div>
                </form>
            )}
        </li>
    );
}
