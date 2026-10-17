import { JsonBlock, SectionHeader, TechnicalDetail } from '@/components/page-header';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateTime, humanKey, timeAgo } from '@/lib/format';
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

function person(p: Person): string {
    if (p.user_id === null) return 'nobody recorded';
    return p.name ? `${p.name} (user #${p.user_id})` : `user #${p.user_id}, who no longer exists`;
}

/** Who asked, said the way the four kinds are accountable (ADR-047). */
function askedSentence(c: Extract<ExecutionContext, { state: 'recorded' }>): string {
    const who = person(c.requester);
    if (c.requester.as_enabled) {
        const door =
            c.requester.kind === 'gate_runner'
                ? 'an evaluation'
                : c.requester.kind === 'console'
                  ? 'a shell on the host'
                  : 'a scheduled or system run';
        return `Nobody separate asked: this was ${door}, so the agent ran as it was enabled and the accountable person is ${who}.`;
    }
    if (c.requester.kind === 'on_behalf_of') return `The scheduler or the system asked, on behalf of ${who}.`;
    return `${who} asked.`;
}

const TERM_LABEL: Record<string, string> = { business: 'Businesses', brand: 'Brands', tool_permission: 'Tools' };

function names(list: Named[], hidden: number): string {
    const shown = list.map((n) => n.name).join(', ');
    const rest = hidden > 0 ? `${shown ? ' and ' : ''}${hidden} outside what you may see` : '';
    return `${shown}${rest}` || 'none';
}

function Fact({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="min-w-0">
            <dt className="hq-eyebrow">{label}</dt>
            <dd className="mt-0.5 text-sm break-words">{children}</dd>
        </div>
    );
}

export function ExecutionContextPanel({ context }: { context: ExecutionContext }) {
    if (context.state === 'before_adr_047') {
        return (
            <section className="hq-card flex flex-col gap-2 p-4">
                <SectionHeader title="Whose authority this run had" />
                <p className="text-muted-foreground text-sm">
                    Recorded before execution contexts existed. This run was made before HQ wrote down who asked, who enabled the agent and what the
                    run could reach, so none of that is known for it. That is a gap in the record, not a statement that the run had no limits.
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
                        <StatusBadge tone="neutral">nothing narrowed it</StatusBadge>
                    ) : (
                        <StatusBadge tone="warn">
                            narrowed {narrowedCount} {narrowedCount === 1 ? 'way' : 'ways'}
                        </StatusBadge>
                    )
                }
            />

            <p className="text-sm">
                {askedSentence(c)}{' '}
                {c.requester.is_enabler
                    ? 'The same person enabled the agent, so the run had that one person’s reach and the agent’s own limits.'
                    : `${person(c.enabler)} enabled the agent. The run could reach only what BOTH of them may, inside the agent’s own limits.`}
            </p>

            <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <Fact label="Asked by">
                    {person(c.requester)}
                    {c.requester.basis && <div className="text-muted-foreground text-xs">read from {c.requester.basis}</div>}
                </Fact>
                <Fact label="Agent enabled by">{person(c.enabler)}</Fact>
                <Fact label="Acted as">
                    the {humanKey(c.agent.key)} agent
                    {c.agent.role && <span className="text-muted-foreground"> · {humanKey(c.agent.role).toLowerCase()} role</span>}
                </Fact>
                <Fact label="Came in through">
                    {c.entry_point ? humanKey(c.entry_point).toLowerCase() : 'not recorded'}
                    {c.environment && <span className="text-muted-foreground"> · {c.environment}</span>}
                </Fact>
                <Fact label="Businesses it could read">
                    {c.business_scope.group_wide
                        ? 'Every business (nothing narrowed it)'
                        : names(c.business_scope.businesses, c.business_scope.outside_your_view)}
                    {c.business_scope.requested && <div className="text-muted-foreground text-xs">opened for {c.business_scope.requested.name}</div>}
                </Fact>
                <Fact label="Brands">
                    {c.brand_scope.every_brand ? 'Every brand of those businesses' : names(c.brand_scope.brands, c.brand_scope.outside_your_view)}
                    {c.brand_scope.requested && <div className="text-muted-foreground text-xs">opened for {c.brand_scope.requested.name}</div>}
                </Fact>
                <Fact label="Resolved">
                    {c.resolved_at ? (
                        `${timeAgo(c.resolved_at)}, ${formatDateTime(c.resolved_at)}`
                    ) : (
                        <span className="text-muted-foreground">The time was not recorded for this run.</span>
                    )}
                </Fact>
            </dl>

            <div>
                <div className="hq-eyebrow">Tools it could reach · {c.tools.length}</div>
                {c.tools.length === 0 ? (
                    <p className="text-muted-foreground mt-1 text-sm">None.</p>
                ) : (
                    <ul className="mt-1 flex flex-wrap gap-1.5">
                        {c.tools.map((t) => (
                            <li key={t} title={t} className="bg-muted border-glass-border rounded-full border px-2.5 py-0.5 text-xs">
                                {humanKey(t)}
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            <div>
                <div className="hq-eyebrow">What narrowed it, and why</div>
                {narrowedCount === 0 ? (
                    <p className="text-muted-foreground mt-1 text-sm">
                        Nothing. The person who asked, the person who enabled the agent and the agent&rsquo;s own settings set no limit beyond the
                        agent&rsquo;s tool list.
                    </p>
                ) : (
                    <ul className="mt-1 flex flex-col gap-1.5 text-sm">
                        {c.narrowed.map((n, i) => (
                            <li key={i} className="flex flex-wrap gap-x-2">
                                <span className="font-medium">{TERM_LABEL[n.term] ?? humanKey(n.term)}</span>
                                <span className="text-muted-foreground">by the {n.by.replace('+', ' and the ')}:</span>
                                <span className="min-w-0 break-words">{n.why}</span>
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            <TechnicalDetail summary="Technical detail: digest, permission names, what is decided per call">
                <p className="text-muted-foreground mb-2 text-xs">
                    Recorded when the run started and not recomputed since. Ids, keys and permission names only; the record holds no credential. Each
                    call was still checked by the gateway for the terms listed as decided per call.
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
