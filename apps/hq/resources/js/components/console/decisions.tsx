import { approvalStatus, riskTone } from '@/components/approvals/shared';
import { StatusBadge, type Tone } from '@/components/ui/status-badge';
import { humanKey } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { ago } from './age';
import { type DecisionCardData, type Receipt } from './types';

/**
 * A decision card (docs/135 (f) decision 5). It says what is waiting and opens the approval; it never
 * approves, rejects or edits. ADR-018 binds an approval to the exact payload a person read, and that
 * payload is on the approval's own page, not on this card.
 */
export function DecisionCard({ card }: { card: DecisionCardData }) {
    const t = useT();
    const overdue = card.sla_due_at !== null && new Date(card.sla_due_at).getTime() < Date.now();
    const who = card.agent_key
        ? t('Proposed by the {agent} agent', { agent: humanKey(card.agent_key) })
        : card.requester_kind === 'human'
          ? t('Requested by a person')
          : t('Requested by HQ');
    const where = [card.business, card.store].filter(Boolean).join(', ');

    return (
        <li className="hq-glass flex flex-col gap-3 rounded-2xl p-4 sm:flex-row sm:items-center sm:justify-between">
            <div className="flex min-w-0 flex-col gap-1">
                <div className="flex flex-wrap items-center gap-2">
                    <span className="font-medium">{card.tool_title ?? humanKey(card.tool_key)}</span>
                    {card.risk_tier && (
                        <StatusBadge tone={riskTone(card.risk_tier)} dot={false}>
                            {card.risk_tier.toUpperCase()}
                        </StatusBadge>
                    )}
                    {overdue && <StatusBadge tone="warn">{t('past its deadline')}</StatusBadge>}
                </div>
                <div className="text-muted-foreground text-sm">
                    {who}
                    {where ? `, ${where}` : ''}
                    {', '}
                    {t('waiting {age}', { age: ago(t, card.created_at) })}
                </div>
                {!card.decidable && <div className="text-muted-foreground text-xs">{t('You can read this one; someone else decides it.')}</div>}
            </div>
            <Link
                href={card.href}
                className="bg-primary text-primary-foreground inline-flex h-9 shrink-0 items-center justify-center gap-1.5 self-start rounded-full px-4 text-sm font-medium transition-opacity hover:opacity-90 sm:self-center"
            >
                {t('Open the approval')}
                <ArrowRight className="size-4" aria-hidden />
            </Link>
        </li>
    );
}

export function Decisions({
    decisions,
    others,
}: {
    decisions: { total: number; overdue: number; cards: DecisionCardData[] } | null;
    others: { key: string; label: string; count: number; href: string; tone: Tone }[];
}) {
    const t = useT();
    if (decisions === null && others.length === 0) return null;

    const total = decisions?.total ?? 0;

    return (
        <section className="flex flex-col gap-3" aria-labelledby="console-needs-you">
            <div className="flex items-baseline justify-between gap-2">
                <h2 id="console-needs-you" className="text-muted-foreground text-[11px] font-medium tracking-[0.06em] uppercase">
                    {t('Needs you, {count}', { count: total + others.reduce((n, o) => n + o.count, 0) })}
                </h2>
                {decisions !== null && total > decisions.cards.length && (
                    <Link href="/approvals" className="text-hq-violet text-xs hover:underline">
                        {t('See all {count} in Approvals', { count: total })}
                    </Link>
                )}
            </div>

            {decisions !== null && total === 0 && others.length === 0 && (
                <p className="text-muted-foreground hq-glass rounded-2xl p-4 text-sm">{t('Nothing is waiting for your decision.')}</p>
            )}

            {decisions !== null && decisions.cards.length > 0 && (
                <ul className="flex flex-col gap-2">
                    {decisions.cards.map((card) => (
                        <DecisionCard key={card.id} card={card} />
                    ))}
                </ul>
            )}

            {others.length > 0 && (
                <ul className="flex flex-col gap-1.5">
                    {others.map((o) => (
                        <li key={o.key}>
                            <Link
                                href={o.href}
                                className="hq-glass hover:bg-accent flex items-center justify-between gap-3 rounded-2xl px-4 py-2.5 text-sm"
                            >
                                <span className="min-w-0 truncate">{t(o.label)}</span>
                                <span className="flex items-center gap-2">
                                    <StatusBadge tone={o.tone}>{String(o.count)}</StatusBadge>
                                    <ArrowRight className="text-muted-foreground size-4" aria-hidden />
                                </span>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

/** What was decided in the last day, with the outcome the approvals screen states ("approved, did not run" stays that). */
export function Receipts({ receipts }: { receipts: Receipt[] | null }) {
    const t = useT();
    if (receipts === null || receipts.length === 0) return null;

    return (
        <section className="flex flex-col gap-2" aria-labelledby="console-receipts">
            <h2 id="console-receipts" className="text-muted-foreground text-[11px] font-medium tracking-[0.06em] uppercase">
                {t('Decided in the last day')}
            </h2>
            <ul className="hq-glass flex flex-col divide-y rounded-2xl">
                {receipts.map((r) => {
                    const s = approvalStatus(r);
                    return (
                        <li key={r.id}>
                            <Link href={r.href} className="hover:bg-accent flex items-center justify-between gap-3 px-4 py-2.5 text-sm">
                                <span className="min-w-0 truncate">{r.tool_title ?? humanKey(r.tool_key)}</span>
                                <span className="flex shrink-0 items-center gap-2">
                                    <StatusBadge tone={s.tone}>{s.label}</StatusBadge>
                                    <span className="text-muted-foreground text-xs">{ago(t, r.decided_at)}</span>
                                </span>
                            </Link>
                        </li>
                    );
                })}
            </ul>
        </section>
    );
}
