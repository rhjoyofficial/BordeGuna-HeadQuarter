import { ExecutionContextPanel, type ExecutionContext } from '@/components/agents/execution-context-panel';
import { runStatus } from '@/components/agents/run-status';
import { JsonBlock, PageHeader, SectionHeader, TechnicalDetail } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, humanKey, humanMillis, humanUsd, timeAgo } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';

type Evidence = {
    kind: string;
    tool?: string;
    tool_call_id?: number | null;
    statement?: string;
    source?: string | null;
    as_of?: string | null;
    args?: Record<string, unknown>;
};

type Proposal = {
    kind: string;
    tool?: string;
    reason?: string;
    why?: string | null;
    args?: Record<string, unknown>;
    outcome_class?: string;
    side?: string;
};

/** Two kinds, not one (ADR-043): HQ refusing a call, and HQ permitting a call the outside then failed. */
function proposalKind(p: Proposal): { label: string; tone: 'danger' | 'warn' | 'neutral' } {
    if (p.kind === 'failed') return { label: 'Permitted, failed outside HQ', tone: 'warn' };
    if (p.kind === 'proposal') return { label: 'Proposed for a human', tone: 'neutral' };
    return { label: 'Refused by HQ', tone: 'danger' };
}

type Provenance = { id: string; provenance: string; source: string; digest: string; bytes: number; trusted: boolean };

type Run = {
    id: number;
    uuid: string;
    agent_key: string;
    question: string;
    task: string | null;
    canonical_task: string | null;
    task_grounding: string | null;
    authority_source?: string | null;
    security_intent?: { version?: string; classes?: string[]; evidence?: { class: string; clause: string }[]; error?: string | null } | null;
    prohibited_capability?: { version?: string; kinds?: string[]; evidence?: { kind: string; clause: string }[]; error?: string | null } | null;
    authority_request: string | null;
    answer: string | null;
    status: string;
    stop_reason: string | null;
    shadow: boolean;
    autonomy_level: string | null;
    autonomy_reason: string | null;
    autonomy_constraints: { source: string; level: string; detail: string }[];
    turns: number;
    tool_calls_count: number;
    duration_ms: number | null;
    model: string | null;
    cost_usd: number;
    trigger: string;
    prompt_version: string | null;
    error: string | null;
    evidence: Evidence[];
    proposals: Proposal[];
    context_provenance: Provenance[];
    reviewed_at: string | null;
    review_verdict: string | null;
    review_note: string | null;
    review_suggestion: string | null;
    created_at: string | null;
};

type ToolCall = {
    id: number;
    tool_key: string;
    status: string;
    policy_result: string | null;
    policy_reason: string | null;
    failure_class: string | null;
    outcome_class: string | null;
    error: string | null;
    duration_ms: number | null;
};

type ModelCall = {
    id: number;
    provider: string;
    model: string;
    model_tier: string | null;
    status: string;
    input_tokens: number | null;
    output_tokens: number | null;
    estimated_cost_usd: string | null;
    latency_ms: number | null;
    attempt: number | null;
};

const VERDICTS: { key: string; label: string; hint: string }[] = [
    { key: 'approved', label: 'Good answer', hint: 'I would have said this.' },
    { key: 'edited', label: 'Needs changes', hint: 'Right direction, wrong in places. Say what.' },
    { key: 'rejected', label: 'Wrong', hint: 'I would not have said this. Say why.' },
];

export default function AgentRunShow({
    run,
    execution_context,
    tool_calls,
    model_calls,
    can,
}: {
    run: Run;
    execution_context: ExecutionContext;
    tool_calls: ToolCall[];
    model_calls: ModelCall[];
    can: { review: boolean };
}) {
    const [verdict, setVerdict] = useState('approved');
    const [note, setNote] = useState('');
    const [suggestion, setSuggestion] = useState('');
    const [busy, setBusy] = useState(false);
    const t = useT();

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Agents', href: '/agents' },
        { title: `Run ${run.id}`, href: `/agents/runs/${run.id}` },
    ];

    const claims = run.evidence.filter((e) => e.kind === 'claim');
    const reads = run.evidence.filter((e) => e.kind === 'evidence');
    const st = runStatus(run);
    const agentName = humanKey(run.agent_key);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Run ${run.id}, ${agentName}`} />

            <div className="flex max-w-5xl flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={
                        <span className="flex flex-wrap items-center gap-2">
                            {agentName} agent, run {run.id}
                            <StatusBadge tone={st.tone}>{st.label}</StatusBadge>
                            {run.shadow && <StatusBadge status="shadow" />}
                        </span>
                    }
                    description={
                        <>
                            {run.created_at ? `${timeAgo(run.created_at)}, ${formatDateTime(run.created_at)}` : ''} · started by{' '}
                            {humanKey(run.trigger).toLowerCase()}
                            {run.autonomy_level ? ` · autonomy ${run.autonomy_level.toUpperCase()}` : ''}
                        </>
                    }
                />

                {run.shadow && (
                    <div className="rounded-xl border p-4 text-sm bg-tone-pending-bg border-tone-pending-line text-tone-pending">
                        <strong>This is a shadow run, so what follows is not HQ&rsquo;s answer.</strong> It is what the {agentName} agent would have
                        said, recorded for you to read. {run.autonomy_reason && <>Autonomy was bound by: {run.autonomy_reason}.</>}
                    </div>
                )}

                <section className="hq-card flex flex-col gap-4 p-4">
                    <div>
                        <div className="text-muted-foreground text-xs font-medium uppercase">The question</div>
                        <p className="mt-1 text-base">{run.question}</p>
                    </div>
                    {run.task !== null && (
                        <div>
                            <div className="text-muted-foreground text-xs font-medium uppercase">What it set out to serve</div>
                            {run.canonical_task ? (
                                <p className="mt-1 text-sm">
                                    HQ established the task from your words.{' '}
                                    {run.task && run.task !== run.canonical_task && (
                                        <span className="text-muted-foreground">The agent read it as: {run.task}</span>
                                    )}
                                </p>
                            ) : run.canonical_task === '' ? (
                                <p className="text-muted-foreground mt-1 text-sm italic">
                                    HQ established no task from the message, so nothing was gathered
                                    {run.task ? <> (the agent had read it as: {run.task})</> : null}.
                                </p>
                            ) : run.task ? (
                                <p className="mt-1 text-sm">{run.task}</p>
                            ) : (
                                <p className="text-muted-foreground mt-1 text-sm italic">No task named in the message, so nothing was gathered.</p>
                            )}
                            {run.authority_request === 'refused' && (
                                <p className="mt-1 text-sm text-tone-pending">
                                    The message asked the agent to act outside its authority. It refused, and nothing that depended on that was done
                                    in its place.
                                </p>
                            )}
                            {run.authority_source === 'runtime' && (
                                <p className="text-muted-foreground mt-1 text-xs">
                                    HQ&apos;s own detector{run.security_intent?.version ? ` (${run.security_intent.version})` : ''} read the message
                                    as security-sensitive
                                    {run.security_intent?.classes?.length
                                        ? `: ${run.security_intent.classes.map((c) => c.replace(/_/g, ' ')).join(', ')}`
                                        : ''}
                                    . The agent cannot lower that reading; its own field said{' '}
                                    {run.security_intent?.error ? 'nothing usable (the detector failed and failed closed)' : 'what it read'}.
                                </p>
                            )}
                            {(run.prohibited_capability?.kinds?.length || run.prohibited_capability?.error) && (
                                <p className="mt-1 text-sm text-tone-pending">
                                    The message asked for something HQ does not have
                                    {run.prohibited_capability?.kinds?.length
                                        ? ` (${run.prohibited_capability.kinds.map((k) => k.replace(/_/g, ' ')).join(', ')})`
                                        : ''}
                                    . HQ&apos;s own detector{run.prohibited_capability?.version ? ` (${run.prohibited_capability.version})` : ''}{' '}
                                    refused it whole on the first turn, and nothing was run in its place
                                    {run.prohibited_capability?.error ? '; the detector failed and failed closed' : ''}.
                                </p>
                            )}
                            {run.authority_request === 'in_untrusted_content' && (
                                <p className="text-muted-foreground mt-1 text-sm">
                                    Untrusted content in this run claimed wider authority for the agent. The claim had no standing; your question was
                                    served.
                                </p>
                            )}
                        </div>
                    )}
                    <div>
                        <div className="text-muted-foreground text-xs font-medium uppercase">What it said</div>
                        {run.answer ? (
                            <p className="mt-1 text-base leading-relaxed whitespace-pre-wrap">{run.answer}</p>
                        ) : (
                            <p className="text-muted-foreground mt-1 text-sm italic">
                                No answer. {run.error ?? 'The run ended before it produced one.'}
                            </p>
                        )}
                    </div>
                    <dl className="text-muted-foreground flex flex-wrap gap-x-6 gap-y-1 border-t pt-3 text-xs">
                        <div>
                            <dt className="inline">Work: </dt>
                            <dd className="text-foreground inline">
                                {run.turns} {run.turns === 1 ? 'turn' : 'turns'}, {run.tool_calls_count} tool{' '}
                                {run.tool_calls_count === 1 ? 'call' : 'calls'}
                            </dd>
                        </div>
                        <div>
                            <dt className="inline">Took: </dt>
                            <dd className="text-foreground inline">{humanMillis(run.duration_ms)}</dd>
                        </div>
                        <div>
                            <dt className="inline">Cost: </dt>
                            <dd className="text-foreground inline">{humanUsd(run.cost_usd)}</dd>
                        </div>
                        <div>
                            <dt className="inline">Model: </dt>
                            <dd className="text-foreground inline">{run.model ?? '-'}</dd>
                        </div>
                    </dl>
                </section>

                <ExecutionContextPanel context={execution_context} />

                <section className="hq-card flex flex-col gap-3 p-4">
                    <SectionHeader title="Your review" />
                    {run.reviewed_at ? (
                        <p className="text-sm">
                            Reviewed {timeAgo(run.reviewed_at)} as <StatusBadge status={run.review_verdict} dot={false} />
                            {run.review_note && <span className="text-muted-foreground"> {run.review_note}</span>}
                        </p>
                    ) : null}
                    {run.reviewed_at && run.review_suggestion ? (
                        <SuggestionBox>
                            <p className="text-sm whitespace-pre-wrap">{run.review_suggestion}</p>
                        </SuggestionBox>
                    ) : null}
                    {run.reviewed_at ? null : can.review ? (
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                setBusy(true);
                                router.post(
                                    `/agents/runs/${run.id}/review`,
                                    { verdict, note, would_have_sent: verdict === 'approved' || suggestion.trim() === '' ? null : suggestion },
                                    { onFinish: () => setBusy(false) },
                                );
                            }}
                            className="flex flex-col gap-3"
                        >
                            <p className="text-muted-foreground text-sm">
                                A verdict is a dated statement of what you thought at the time and is not revised afterwards. It becomes part of the
                                record a later evaluation replays.
                            </p>
                            <div className="grid gap-2 sm:grid-cols-3">
                                {VERDICTS.map((v) => (
                                    <button
                                        key={v.key}
                                        type="button"
                                        onClick={() => setVerdict(v.key)}
                                        className={`rounded-lg border p-3 text-left text-sm transition-colors ${
                                            verdict === v.key ? 'border-primary bg-accent' : 'hover:bg-accent/50'
                                        }`}
                                        aria-pressed={verdict === v.key}
                                    >
                                        <div className="font-medium">{v.label}</div>
                                        <div className="text-muted-foreground text-xs">{v.hint}</div>
                                    </button>
                                ))}
                            </div>
                            <textarea
                                value={note}
                                onChange={(e) => setNote(e.target.value)}
                                rows={3}
                                maxLength={2000}
                                placeholder={
                                    verdict === 'approved'
                                        ? 'Optional: anything worth noting.'
                                        : 'What was wrong with it, or what you would have said instead.'
                                }
                                className="border-input hq-field w-full rounded-md border p-2 text-sm"
                            />
                            {verdict !== 'approved' && (
                                <SuggestionBox>
                                    <textarea
                                        value={suggestion}
                                        onChange={(e) => setSuggestion(e.target.value)}
                                        rows={4}
                                        maxLength={2000}
                                        aria-label={t('What I would have sent')}
                                        placeholder={t('Optional: the answer as you would have written it.')}
                                        className="border-input hq-field w-full rounded-md border p-2 text-sm"
                                    />
                                </SuggestionBox>
                            )}
                            <div>
                                <Button type="submit" size="sm" disabled={busy}>
                                    Record this verdict
                                </Button>
                            </div>
                        </form>
                    ) : (
                        <p className="text-muted-foreground text-sm">You do not hold the permission to review a run.</p>
                    )}
                </section>

                {claims.length > 0 && (
                    <section className="flex flex-col gap-2">
                        <SectionHeader
                            title="Claims, and where each came from"
                            aside={`${claims.length} ${claims.length === 1 ? 'claim' : 'claims'}`}
                        />
                        <div className="hq-table-box">
                            <table className="w-full text-sm">
                                <thead className="text-left text-xs">
                                    <tr>
                                        <th className="px-3 py-2 font-medium">Statement</th>
                                        <th className="px-3 py-2 font-medium">Source</th>
                                        <th className="px-3 py-2 font-medium">As of</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {claims.map((c, i) => (
                                        <tr key={i} className="border-t">
                                            <td className="px-3 py-2">{c.statement}</td>
                                            <td className="px-3 py-2 text-xs">
                                                {c.source ? humanKey(c.source) : <span className="text-muted-foreground">unsourced</span>}
                                            </td>
                                            <td className="text-muted-foreground px-3 py-2 text-xs whitespace-nowrap">
                                                {c.as_of ? formatDateTime(c.as_of) : 'not stated'}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                )}

                {run.proposals.length > 0 && (
                    <section className="flex flex-col gap-2">
                        <SectionHeader title="What it wanted to do and could not" />
                        <div className="hq-table-box">
                            <table className="w-full text-sm">
                                <thead className="text-left text-xs">
                                    <tr>
                                        <th className="px-3 py-2 font-medium">Kind</th>
                                        <th className="px-3 py-2 font-medium">Tool</th>
                                        <th className="px-3 py-2 font-medium">Class</th>
                                        <th className="px-3 py-2 font-medium">Why it did not succeed</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {run.proposals.map((p, i) => {
                                        const kind = proposalKind(p);
                                        return (
                                            <tr key={i} className="border-t">
                                                <td className="px-3 py-2 whitespace-nowrap">
                                                    <StatusBadge tone={kind.tone}>{kind.label}</StatusBadge>
                                                </td>
                                                <td className="px-3 py-2 text-xs" title={p.tool ?? ''}>
                                                    {p.tool ? humanKey(p.tool) : '-'}
                                                </td>
                                                <td className="text-muted-foreground px-3 py-2 text-xs whitespace-nowrap">
                                                    {p.outcome_class ? humanKey(p.outcome_class).toLowerCase() : '-'}
                                                </td>
                                                <td className="px-3 py-2">{p.reason ?? p.why ?? '-'}</td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    </section>
                )}

                <section className="grid gap-4 md:grid-cols-2">
                    <div className="flex flex-col gap-2">
                        <SectionHeader title="Tool calls" aside={reads.length > 0 ? `${reads.length} produced evidence` : undefined} />
                        <div className="hq-table-box">
                            <table className="w-full text-sm">
                                <tbody>
                                    {tool_calls.length === 0 && (
                                        <tr>
                                            <td className="text-muted-foreground p-3 text-sm">None.</td>
                                        </tr>
                                    )}
                                    {tool_calls.map((c) => (
                                        <tr key={c.id} className="border-t first:border-0">
                                            <td className="px-3 py-2" title={c.tool_key}>
                                                {humanKey(c.tool_key)}
                                            </td>
                                            <td className="px-3 py-2">
                                                <StatusBadge status={c.status} />
                                            </td>
                                            <td className="text-muted-foreground px-3 py-2 text-xs" title={c.policy_reason ?? ''}>
                                                {c.policy_result ? humanKey(c.policy_result).toLowerCase() : ''}
                                            </td>
                                            <td className="text-muted-foreground px-3 py-2 text-xs" title={c.error ?? ''}>
                                                {c.outcome_class ? humanKey(c.outcome_class).toLowerCase() : ''}
                                            </td>
                                            <td className="text-muted-foreground px-3 py-2 text-right text-xs whitespace-nowrap">
                                                {humanMillis(c.duration_ms)}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div className="flex flex-col gap-2">
                        <SectionHeader title="Model calls" />
                        <div className="hq-table-box">
                            <table className="w-full text-sm">
                                <tbody>
                                    {model_calls.length === 0 && (
                                        <tr>
                                            <td className="text-muted-foreground p-3 text-sm">None.</td>
                                        </tr>
                                    )}
                                    {model_calls.map((m) => (
                                        <tr key={m.id} className="border-t first:border-0">
                                            <td className="px-3 py-2 text-xs">{m.model}</td>
                                            <td className="px-3 py-2">
                                                <StatusBadge status={m.status} />
                                            </td>
                                            <td className="text-muted-foreground px-3 py-2 text-xs whitespace-nowrap tabular-nums">
                                                {(m.input_tokens ?? 0).toLocaleString('en-US')} in, {(m.output_tokens ?? 0).toLocaleString('en-US')}{' '}
                                                out
                                            </td>
                                            <td className="px-3 py-2 text-right text-xs whitespace-nowrap tabular-nums">
                                                {humanUsd(m.estimated_cost_usd)}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>

                <TechnicalDetail summary={`What was in front of the model · ${run.context_provenance.length} blocks`}>
                    <p className="text-muted-foreground mb-2 text-xs">
                        Labels and digests only. The text itself is never stored here: a provenance record that carried it would be a second copy of
                        everything a prompt ever held.
                    </p>
                    <div className="hq-table-box">
                        <table className="w-full text-sm">
                            <thead className="text-left text-xs">
                                <tr>
                                    <th className="px-3 py-2 font-medium">Kind</th>
                                    <th className="px-3 py-2 font-medium">Source</th>
                                    <th className="px-3 py-2 text-right font-medium">Bytes</th>
                                    <th className="px-3 py-2 font-medium">Trusted</th>
                                </tr>
                            </thead>
                            <tbody>
                                {run.context_provenance.map((b) => (
                                    <tr key={b.id} className="border-t">
                                        <td className="px-3 py-2 text-xs">{humanKey(b.provenance)}</td>
                                        <td className="px-3 py-2 font-mono text-xs">{b.source}</td>
                                        <td className="px-3 py-2 text-right text-xs tabular-nums">{b.bytes.toLocaleString('en-US')}</td>
                                        <td className="px-3 py-2 text-xs">
                                            {b.trusted ? (
                                                <StatusBadge status="yes">trusted</StatusBadge>
                                            ) : (
                                                <StatusBadge status="untrusted">untrusted, caps autonomy</StatusBadge>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    {run.autonomy_constraints.length > 0 && (
                        <div className="mt-3">
                            <div className="text-muted-foreground mb-1 text-xs font-medium uppercase">Autonomy constraints</div>
                            <JsonBlock value={run.autonomy_constraints} />
                        </div>
                    )}
                    <dl className="text-muted-foreground mt-3 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 font-mono text-xs">
                        <dt>uuid</dt>
                        <dd className="break-all">{run.uuid}</dd>
                        <dt>agent</dt>
                        <dd>{run.agent_key}</dd>
                        <dt>prompt</dt>
                        <dd>{run.prompt_version ?? '-'}</dd>
                        <dt>stop reason</dt>
                        <dd>{run.stop_reason ?? '-'}</dd>
                    </dl>
                </TechnicalDetail>
            </div>
        </AppLayout>
    );
}

/**
 * Ruling docs/120 item 18: the reviewer's own wording, kept apart from the run and from any message. It is a
 * proposal for the record only: nothing here is sent, queued or shown to a customer.
 */
function SuggestionBox({ children }: { children: ReactNode }) {
    const t = useT();

    return (
        <div className="flex flex-col gap-2 rounded-lg border border-dashed p-3">
            <div className="flex flex-wrap items-center gap-2">
                <span className="text-sm font-medium">{t('What I would have sent')}</span>
                <span className="text-muted-foreground rounded border px-1.5 py-0.5 text-xs">{t('Proposal only. Never sent.')}</span>
            </div>
            <p className="text-muted-foreground text-xs">
                {t(
                    'Saved with your review as a correction for later evaluation. It does not send anything, reaches no customer and is not a message.',
                )}
            </p>
            {children}
        </div>
    );
}
