import { JsonBlock, SectionHeader, TechnicalDetail } from '@/components/page-header';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateTime, humanKey, timeAgo } from '@/lib/format';
import { type Translator, useT } from '@/lib/i18n';
import { type ReactNode } from 'react';

type Person = { user_id: number | null; name: string | null };
type Named = { id: number; name: string };

/** The recorded `eec.v1` context of a run (ADR-047), or the statement that the run predates it. */
export type ExecutionContext =
    | { state: 'before_adr_047' }
    | {
          state: 'recorded';
          version: string | null;
          requester: Person & { kind: string | null; as_enabled: boolean; basis: string | null; is_enabler: boolean };
          enabler: Person;
          acting_identity: string | null;
          agent: { key: string; role: string | null };
          entry_point: string | null;
          environment: string | null;
          business_scope: { group_wide: boolean; businesses: Named[]; outside_your_view: number; requested: Named | null };
          brand_scope: { every_brand: boolean; brands: Named[]; outside_your_view: number; requested: Named | null };
          tools: string[];
          permissions: string[];
          narrowed: { term: string; by: string; why: string }[];
          decided_per_call: string[];
          digest: string | null;
          resolved_at: string | null;
      };

function person(p: Person, t: Translator): string {
    if (p.user_id === null) return t('nobody recorded');
    return p.name ? t('{name} (user #{id})', { name: p.name, id: String(p.user_id) }) : t('user #{id}, who no longer exists', { id: String(p.user_id) });
}

/** Who asked, said the way the four kinds are accountable (ADR-047). */
function askedSentence(c: Extract<ExecutionContext, { state: 'recorded' }>, t: Translator): string {
    const who = person(c.requester, t);
    if (c.requester.as_enabled) {
        const door =
            c.requester.kind === 'gate_runner'
                ? t('an evaluation')
                : c.requester.kind === 'console'
                  ? t('a shell on the host')
                  : t('a scheduled or system run');
        return t('Nobody separate asked: this was {door}, so the agent ran as it was enabled and the accountable person is {who}.', { door, who });
    }
    if (c.requester.kind === 'on_behalf_of') return t('The scheduler or the system asked, on behalf of {who}.', { who });
    return t('{who} asked.', { who });
}

const TERM_LABEL: Record<string, string> = { business: 'Businesses', brand: 'Brands', tool_permission: 'Tools' };

function names(list: Named[], hidden: number, t: Translator): string {
    const shown = list.map((n) => n.name).join(', ');
    const rest = hidden > 0 ? `${shown ? ` ${t('and')} ` : ''}${t('{n} outside what you may see', { n: hidden })}` : '';
    return `${shown}${rest}` || t('none');
}

function Fact({ label, children }: { label: string; children: ReactNode }) {
    const t = useT();
    return (
        <div className="min-w-0">
            <dt className="hq-eyebrow">{t(label)}</dt>
            <dd className="mt-0.5 text-sm break-words">{children}</dd>
        </div>
    );
}

export function ExecutionContextPanel({ context }: { context: ExecutionContext }) {
    const t = useT();

    if (context.state === 'before_adr_047') {
        return (
            <section className="hq-card flex flex-col gap-2 p-4">
                <SectionHeader title="Whose authority this run had" />
                <p className="text-muted-foreground text-sm">
                    {t(
                        'Recorded before execution contexts existed. This run was made before HQ wrote down who asked, who enabled the agent and what the run could reach, so none of that is known for it. That is a gap in the record, not a statement that the run had no limits.',
                    )}
                </p>
            </section>
        );
    }

    const c = context;
    const narrowedCount = c.narrowed.length;

    return (
        <section className="hq-card flex flex-col gap-4 p-4">
            <SectionHeader
                title="Whose authority this run had"
                aside={
                    narrowedCount === 0 ? (
                        <StatusBadge tone="neutral">{t('nothing narrowed it')}</StatusBadge>
                    ) : (
                        <StatusBadge tone="warn">
                            {narrowedCount === 1 ? t('narrowed {n} way', { n: narrowedCount }) : t('narrowed {n} ways', { n: narrowedCount })}
                        </StatusBadge>
                    )
                }
            />

            <p className="text-sm">
                {askedSentence(c, t)}{' '}
                {c.requester.is_enabler
                    ? t('The same person enabled the agent, so the run had that one person’s reach and the agent’s own limits.')
                    : t('{who} enabled the agent. The run could reach only what BOTH of them may, inside the agent’s own limits.', {
                          who: person(c.enabler, t),
                      })}
            </p>

            <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <Fact label="Asked by">
                    {person(c.requester, t)}
                    {c.requester.basis && <div className="text-muted-foreground text-xs">{t('read from {basis}', { basis: c.requester.basis })}</div>}
                </Fact>
                <Fact label="Agent enabled by">{person(c.enabler, t)}</Fact>
                <Fact label="Acted as">
                    {t('the {agent} agent', { agent: humanKey(c.agent.key) })}
                    {c.agent.role && (
                        <span className="text-muted-foreground"> · {t('{role} role', { role: humanKey(c.agent.role).toLowerCase() })}</span>
                    )}
                </Fact>
                <Fact label="Came in through">
                    {c.entry_point ? humanKey(c.entry_point).toLowerCase() : t('not recorded')}
                    {c.environment && <span className="text-muted-foreground"> · {c.environment}</span>}
                </Fact>
                <Fact label="Businesses it could read">
                    {c.business_scope.group_wide
                        ? t('Every business (nothing narrowed it)')
                        : names(c.business_scope.businesses, c.business_scope.outside_your_view, t)}
                    {c.business_scope.requested && (
                        <div className="text-muted-foreground text-xs">{t('opened for {name}', { name: c.business_scope.requested.name })}</div>
                    )}
                </Fact>
                <Fact label="Brands">
                    {c.brand_scope.every_brand ? t('Every brand of those businesses') : names(c.brand_scope.brands, c.brand_scope.outside_your_view, t)}
                    {c.brand_scope.requested && (
                        <div className="text-muted-foreground text-xs">{t('opened for {name}', { name: c.brand_scope.requested.name })}</div>
                    )}
                </Fact>
                <Fact label="Resolved">
                    {c.resolved_at ? (
                        `${timeAgo(c.resolved_at)}, ${formatDateTime(c.resolved_at)}`
                    ) : (
                        <span className="text-muted-foreground">{t('The time was not recorded for this run.')}</span>
                    )}
                </Fact>
            </dl>

            <div>
                <div className="hq-eyebrow">{t('Tools it could reach · {n}', { n: c.tools.length })}</div>
                {c.tools.length === 0 ? (
                    <p className="text-muted-foreground mt-1 text-sm">{t('None.')}</p>
                ) : (
                    <ul className="mt-1 flex flex-wrap gap-1.5">
                        {c.tools.map((tool) => (
                            <li key={tool} title={tool} className="bg-muted border-glass-border rounded-full border px-2.5 py-0.5 text-xs">
                                {humanKey(tool)}
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            <div>
                <div className="hq-eyebrow">{t('What narrowed it, and why')}</div>
                {narrowedCount === 0 ? (
                    <p className="text-muted-foreground mt-1 text-sm">
                        {t(
                            'Nothing. The person who asked, the person who enabled the agent and the agent’s own settings set no limit beyond the agent’s tool list.',
                        )}
                    </p>
                ) : (
                    <ul className="mt-1 flex flex-col gap-1.5 text-sm">
                        {c.narrowed.map((n, i) => (
                            <li key={i} className="flex flex-wrap gap-x-2">
                                <span className="font-medium">{TERM_LABEL[n.term] ? t(TERM_LABEL[n.term]) : humanKey(n.term)}</span>
                                <span className="text-muted-foreground">{t('by the {who}:', { who: n.by.replace('+', ' and the ') })}</span>
                                <span className="min-w-0 break-words">{n.why}</span>
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            <TechnicalDetail summary="Technical detail: digest, permission names, what is decided per call">
                <p className="text-muted-foreground mb-2 text-xs">
                    {t(
                        'Recorded when the run started and not recomputed since. Ids, keys and permission names only; the record holds no credential. Each call was still checked by the gateway for the terms listed as decided per call.',
                    )}
                </p>
                <dl className="text-muted-foreground grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 font-mono text-xs">
                    <dt>resolver</dt>
                    <dd>{c.version ?? '-'}</dd>
                    <dt>digest</dt>
                    <dd className="break-all">{c.digest ?? '-'}</dd>
                    <dt>acting identity</dt>
                    <dd className="break-all">{c.acting_identity ?? '-'}</dd>
                    <dt>requester kind</dt>
                    <dd>
                        {c.requester.kind ?? '-'}
                        {c.requester.as_enabled ? ' (as enabled)' : ''}
                    </dd>
                    <dt>permissions</dt>
                    <dd className="break-words">{c.permissions.length ? c.permissions.join(', ') : '-'}</dd>
                    <dt>per call</dt>
                    <dd className="break-words">{c.decided_per_call.length ? c.decided_per_call.join(', ') : '-'}</dd>
                </dl>
                <div className="mt-2">
                    <JsonBlock value={c.tools} />
                </div>
            </TechnicalDetail>
        </section>
    );
}
