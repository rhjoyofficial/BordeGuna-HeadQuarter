import { StatusBadge } from '@/components/ui/status-badge';
import { humanKey, humanUsd } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { Link } from '@inertiajs/react';
import { type ReactNode } from 'react';
import { ago } from './age';
import { type ConsoleData } from './types';

function Block({ title, href, linkLabel, children }: { title: string; href?: string; linkLabel?: string; children: ReactNode }) {
    return (
        <section className="flex flex-col gap-2 border-t pt-3 first:border-t-0 first:pt-0">
            <div className="flex items-baseline justify-between gap-2">
                <h3 className="text-muted-foreground text-[11px] font-medium tracking-[0.06em] uppercase">{title}</h3>
                {href && linkLabel && (
                    <Link href={href} className="text-hq-violet text-xs hover:underline">
                        {linkLabel}
                    </Link>
                )}
            </div>
            {children}
        </section>
    );
}

const SEVERITY_TONE = { critical: 'danger', warning: 'warn', info: 'info' } as const;

/**
 * The live panel (docs/135 (c)): the scheduler, signals, cost, the authority guard and the posture,
 * each read from the screen it summarises and shown only to people who may open that screen.
 * Read-only: nothing here starts, stops, pauses or re-tools anything.
 */
export function LivePanel({ live, generatedAt, permissions }: { live: ConsoleData['live']; generatedAt: string; permissions: string[] }) {
    const t = useT();
    const { scheduler, alerts, cost, guard, posture } = live;
    const can = (p: string) => permissions.includes(p);

    return (
        <aside className="hq-glass flex flex-col gap-4 rounded-3xl p-4 xl:sticky xl:top-4 xl:self-start" aria-label={t('Live')}>
            <div className="flex items-center justify-between gap-2">
                <h2 className="font-semibold">{t('Live')}</h2>
                <span className="text-muted-foreground text-xs">{t('read {age}', { age: ago(t, generatedAt) })}</span>
            </div>

            <Block title={t('Scheduler')} href={scheduler.jobs !== null ? '/scheduler' : undefined} linkLabel={t('Open')}>
                <div className="flex flex-wrap items-center gap-2 text-sm">
                    <StatusBadge tone={scheduler.running ? 'ok' : 'danger'}>{scheduler.running ? t('running') : t('stopped')}</StatusBadge>
                    <span className="text-muted-foreground text-xs">
                        {scheduler.last_tick ? t('last tick {age}', { age: ago(t, scheduler.last_tick) }) : t('no tick recorded')}
                    </span>
                </div>
                {!scheduler.running && (
                    <p className="rounded-xl bg-orange-500/10 p-2.5 text-xs leading-relaxed text-orange-900 dark:text-orange-200" role="status">
                        {t(
                            'The scheduler is not ticking: it is stopped on purpose under containment, or it has failed. Alerts, the event drain, sweeps and Telegram alerting are paused until it runs again.',
                        )}
                    </p>
                )}
                {scheduler.jobs !== null && (
                    <>
                        <div className="text-muted-foreground text-xs">
                            {t('{active} active jobs, {paused} paused, {failed} failed runs in the last day', {
                                active: scheduler.jobs.active,
                                paused: scheduler.jobs.paused,
                                failed: scheduler.jobs.failed_24h,
                            })}
                        </div>
                        {scheduler.jobs.next.length === 0 ? (
                            <div className="text-muted-foreground text-xs">{t('No job is due to run.')}</div>
                        ) : (
                            <ul className="flex flex-col gap-1 text-sm">
                                {scheduler.jobs.next.map((j) => (
                                    <li key={j.id} className="flex items-center justify-between gap-2">
                                        <Link href={`/scheduler/${j.id}`} className="min-w-0 truncate hover:underline">
                                            {j.name}
                                        </Link>
                                        <span className="text-muted-foreground shrink-0 text-xs">{ago(t, j.next_run_at)}</span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </>
                )}
            </Block>

            {alerts !== null && (
                <Block title={t('Signals')} href="/observe/alerts" linkLabel={t('All alerts')}>
                    {alerts.total === 0 ? (
                        <div className="text-muted-foreground text-sm">{t('No open alert.')}</div>
                    ) : (
                        <ul className="flex flex-col gap-1.5 text-sm">
                            {alerts.items.map((a) => (
                                <li key={a.id} className="flex flex-col gap-0.5">
                                    <div className="flex items-start gap-2">
                                        <StatusBadge tone={SEVERITY_TONE[a.severity as keyof typeof SEVERITY_TONE] ?? 'neutral'}>
                                            {a.severity}
                                        </StatusBadge>
                                        <span className="min-w-0">{a.subject}</span>
                                    </div>
                                    <span className="text-muted-foreground text-xs">{t('last seen {age}', { age: ago(t, a.last_seen_at) })}</span>
                                </li>
                            ))}
                            {alerts.total > alerts.items.length && (
                                <li className="text-muted-foreground text-xs">
                                    {t('{count} more open', { count: alerts.total - alerts.items.length })}
                                </li>
                            )}
                        </ul>
                    )}
                    {!scheduler.running && (
                        <div className="text-muted-foreground text-xs">
                            {t('No new alert is raised while the scheduler is stopped, so this list may be out of date.')}
                        </div>
                    )}
                </Block>
            )}

            {cost !== null && (
                <Block title={t('AI cost this month')} href="/observe/cost" linkLabel={t('Open')}>
                    <div className="font-mono text-lg font-semibold tabular-nums">
                        {humanUsd(cost.spent_usd)}
                        {cost.cap_usd !== null && <span className="text-muted-foreground text-sm font-normal"> / {humanUsd(cost.cap_usd)}</span>}
                    </div>
                    <div className="text-muted-foreground text-xs">
                        {cost.cap_usd === null ? t('No monthly cap is set.') : t('{percent}% of the monthly cap', { percent: cost.share_percent })}
                    </div>
                    {(cost.refuse || cost.downgrade) && (
                        <StatusBadge tone={cost.refuse ? 'danger' : 'warn'}>
                            {cost.refuse ? t('over the cap: new AI calls are refused') : t('near the cap: cheaper models are used')}
                        </StatusBadge>
                    )}
                </Block>
            )}

            {guard !== null && (
                <Block title={t('Authority guard')} href="/observe/control-plane" linkLabel={t('Open')}>
                    <p className="text-muted-foreground text-xs leading-relaxed">
                        {t('A chat can ask for anything. HQ only does what policy allows, and a person approves the rest.')}
                    </p>
                    <div className="text-sm">{t('{count} tool calls in the last day', { count: guard.calls_total })}</div>
                    {guard.policy_outcomes.length > 0 && (
                        <ul className="flex flex-wrap gap-1.5">
                            {guard.policy_outcomes.map((o) => (
                                <li key={o.value ?? 'none'}>
                                    <StatusBadge status={o.value ?? 'unknown'}>{`${humanKey(o.value ?? 'unknown')}: ${o.count}`}</StatusBadge>
                                </li>
                            ))}
                        </ul>
                    )}
                    <div className="text-muted-foreground text-xs">
                        {t('{tools} tools switched off by hand, {grants} autonomy grants active, {shadow} shadow periods open', {
                            tools: guard.tools_disabled,
                            grants: guard.active_grants,
                            shadow: guard.open_shadow_periods,
                        })}
                    </div>
                </Block>
            )}

            <Block title={t('Agents')} href={can('hq.agents.view') ? '/observe/posture' : undefined} linkLabel={t('Posture')}>
                <div className="flex items-center gap-2 text-sm">
                    <StatusBadge tone={posture.ai_runtime ? 'warn' : 'neutral'}>
                        {posture.ai_runtime ? t('AI runtime on') : t('AI runtime off')}
                    </StatusBadge>
                </div>
                {posture.agents.length === 0 ? (
                    <div className="text-muted-foreground text-xs">{t('No agent is registered.')}</div>
                ) : (
                    <ul className="flex flex-col gap-1 text-sm">
                        {posture.agents.map((a) => (
                            <li key={a.key} className="flex items-center justify-between gap-2">
                                <span className="min-w-0 truncate">{a.name}</span>
                                <span className="flex shrink-0 items-center gap-1.5">
                                    <StatusBadge status={a.status} />
                                    <span className="text-muted-foreground font-mono text-xs">{(a.autonomy ?? 'a0').toUpperCase()}</span>
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </Block>
        </aside>
    );
}
