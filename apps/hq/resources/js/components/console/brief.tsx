import { StatusBadge } from '@/components/ui/status-badge';
import { formatNumber, useT } from '@/lib/i18n';
import { Link } from '@inertiajs/react';
import { ago } from './age';
import { NotConnectedChip, SourceChip } from './source-chip';
import { type ConsoleBrief, type ConsoleFigure, type LivePosture, type LiveScheduler } from './types';

/** HQ's voice mark (docs/135 (f) decision 3): the orb marks the assistant, the logo marks the company. */
export function Orb({ size = 28 }: { size?: number }) {
    return <span className="hq-orb inline-block shrink-0 rounded-full" style={{ width: size, height: size }} aria-hidden />;
}

const FIGURE_ORDER = ['placed', 'delivered', 'cancelled', 'gross'] as const;

function FigureTile({ label, figure }: { label: string; figure: ConsoleFigure | undefined }) {
    const t = useT();
    const value = figure === undefined ? null : figure.unit === 'BDT' ? `BDT ${formatNumber(Number(figure.value))}` : formatNumber(figure.value);

    return (
        <div className="hq-glass flex min-w-0 flex-col gap-1 rounded-2xl p-3">
            <div className="text-muted-foreground text-[11px] tracking-[0.06em] uppercase">{label}</div>
            {value === null ? (
                <div className="text-muted-foreground text-sm">{t('no figure')}</div>
            ) : (
                <div className="font-mono text-xl font-semibold tabular-nums">{value}</div>
            )}
            {figure !== undefined && figure.freshness.state !== 'fresh' && <div className="text-hq-orange text-xs">{t('from a stale source')}</div>}
        </div>
    );
}

/**
 * The first message of the console: what HQ read just now, and the newest stored briefing.
 *
 * ⛔ It states, it does not narrate. Every sentence here is built from the props; the only model-written
 * text is a stored briefing's narrative, shown under its own label and only when the briefing check
 * accepted every figure in it.
 */
export function BriefMessage({
    brief,
    greeting,
    pending,
    scheduler,
    posture,
    canOpenBriefings,
}: {
    brief: ConsoleBrief | null;
    greeting: string;
    pending: number | null;
    scheduler: LiveScheduler;
    posture: LivePosture;
    canOpenBriefings: boolean;
}) {
    const t = useT();

    // The rows to show: the group when HQ could total it, otherwise each business that has a total.
    const scopes = (() => {
        if (!brief) return [];
        const group = brief.figures.filter((f) => f.business === null);
        if (group.length) return [{ name: t('All businesses'), figures: group }];
        const slugs = Array.from(new Set(brief.figures.map((f) => f.business).filter((b): b is string => b !== null)));
        return slugs.map((slug) => {
            const figures = brief.figures.filter((f) => f.business === slug);
            return { name: figures[0]?.business_name ?? slug, figures };
        });
    })();

    const labels: Record<(typeof FIGURE_ORDER)[number], string> = {
        placed: t('Orders placed'),
        delivered: t('Orders delivered'),
        cancelled: t('Orders cancelled'),
        gross: t('Gross of orders placed'),
    };

    const agentsAtA0 = posture.agents.filter((a) => (a.autonomy ?? 'a0') === 'a0').length;

    return (
        <article className="hq-glass flex flex-col gap-4 rounded-3xl p-4 md:p-5" aria-labelledby="console-brief-title">
            <header className="flex items-center gap-3">
                <Orb />
                <div className="min-w-0">
                    <h2 id="console-brief-title" className="text-lg font-semibold">
                        {greeting}
                    </h2>
                    <p className="text-muted-foreground text-xs">{t("Read from HQ's own records just now. No model wrote this.")}</p>
                </div>
            </header>

            <ul className="flex flex-col gap-1.5 text-[15px] leading-relaxed">
                {pending !== null && (
                    <li>
                        {pending === 0
                            ? t('No approval is waiting for a person.')
                            : t('Approvals waiting for a person: {count}. Each one opens on its own page.', { count: pending })}
                    </li>
                )}
                {!scheduler.running && (
                    <li className="text-hq-orange">
                        {t('The scheduler is stopped, so alerts, the event drain, sweeps and Telegram alerting are paused.')}
                    </li>
                )}
                <li>
                    {posture.ai_runtime
                        ? t('The AI runtime is on. {count} of {total} agents are at A0, where HQ proposes and a person acts.', {
                              count: agentsAtA0,
                              total: posture.agents.length,
                          })
                        : t('The AI runtime is off, so no agent runs: HQ proposes nothing and acts on nothing by itself.')}
                </li>
            </ul>

            {brief === null ? (
                <p className="text-muted-foreground text-sm">
                    {t('The figures and their sources need the permission to read business facts (hq.tools.facts.read).')}
                </p>
            ) : (
                <>
                    <section className="flex flex-col gap-2" aria-label={t('Sources')}>
                        <div className="text-muted-foreground text-[11px] tracking-[0.06em] uppercase">{t('Sources')}</div>
                        <div className="flex flex-wrap gap-1.5">
                            {brief.sources.map((s) => (
                                <SourceChip key={s.key} source={s} />
                            ))}
                            {brief.not_connected.map((n) => (
                                <NotConnectedChip key={n.key} name={n.key === 'marketing' ? t('Ads and content') : t('Finance')} reason={n.reason} />
                            ))}
                        </div>
                    </section>

                    <section className="flex flex-col gap-2" aria-label={t('Yesterday')}>
                        <div className="text-muted-foreground text-[11px] tracking-[0.06em] uppercase">
                            {t('Yesterday, {date}', { date: brief.date })}
                        </div>
                        {scopes.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                {t('HQ has no order figure for yesterday in your scope. That is not the same as no orders.')}
                            </p>
                        ) : (
                            scopes.map((scope) => (
                                <div key={scope.name} className="flex flex-col gap-1.5">
                                    <div className="text-sm font-medium">{scope.name}</div>
                                    <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                                        {FIGURE_ORDER.map((key) => (
                                            <FigureTile key={key} label={labels[key]} figure={scope.figures.find((f) => f.figure === key)} />
                                        ))}
                                    </div>
                                </div>
                            ))
                        )}
                        {brief.notes.some((n) => n.status === 'total_withheld') && (
                            <p className="text-muted-foreground text-xs">
                                {t('A store has no figure, so HQ gives no total over the stores that do: it would look complete and not be.')}
                            </p>
                        )}
                        <div className="text-muted-foreground text-xs">
                            {posture.ai_runtime
                                ? t('Handled by AI: not measured on this screen yet.')
                                : t('Handled by AI: none, the AI runtime is off.')}
                        </div>
                    </section>

                    <section className="flex flex-col gap-1.5 border-t pt-3 text-sm" aria-label={t('Stored briefing')}>
                        {brief.stored === null ? (
                            <p className="text-muted-foreground">
                                {t(
                                    'No briefing has been stored yet. A briefing is composed by the tool briefing.compose, run by a person or a scheduled job.',
                                )}
                            </p>
                        ) : (
                            <>
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="font-medium">{t('Latest stored briefing, {date}', { date: brief.stored.briefing_date })}</span>
                                    <StatusBadge status={brief.stored.state} />
                                    <span className="text-muted-foreground text-xs">{ago(t, brief.stored.generated_at)}</span>
                                    {canOpenBriefings && (
                                        <Link href={`/observe/briefings/${brief.stored.uuid}`} className="text-hq-violet text-xs hover:underline">
                                            {t('Open the briefing')}
                                        </Link>
                                    )}
                                </div>
                                {brief.stored.narrative ? (
                                    <blockquote className="border-hq-violet/40 border-l-2 pl-3 whitespace-pre-line">
                                        <div className="text-muted-foreground mb-1 text-xs">
                                            {t('Narrated by the model; every figure in it was traced to the evidence.')}
                                        </div>
                                        {brief.stored.narrative}
                                    </blockquote>
                                ) : (
                                    <p className="text-muted-foreground text-xs">{t('No narrative: the briefing holds its evidence only.')}</p>
                                )}
                            </>
                        )}
                    </section>
                </>
            )}
        </article>
    );
}
