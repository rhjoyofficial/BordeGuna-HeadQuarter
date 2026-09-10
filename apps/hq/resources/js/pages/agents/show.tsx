import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

type Evidence = {
    kind: string;
    tool?: string;
    tool_call_id?: number | null;
    statement?: string;
    source?: string | null;
    as_of?: string | null;
    args?: Record<string, unknown>;
};

type Proposal = { kind: string; tool?: string; reason?: string; why?: string | null; args?: Record<string, unknown> };

type Provenance = { id: string; provenance: string; source: string; digest: string; bytes: number; trusted: boolean };

type Run = {
    id: number;
    uuid: string;
    agent_key: string;
    question: string;
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
    created_at: string | null;
};

type ToolCall = {
    id: number;
    tool_key: string;
    status: string;
    policy_result: string | null;
    policy_reason: string | null;
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

export default function AgentRunShow({
    run,
    tool_calls,
    model_calls,
    can,
}: {
    run: Run;
    tool_calls: ToolCall[];
    model_calls: ModelCall[];
    can: { review: boolean };
}) {
    const [verdict, setVerdict] = useState('approved');
    const [note, setNote] = useState('');

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Agents', href: '/agents' },
        { title: `Run ${run.id}`, href: `/agents/runs/${run.id}` },
    ];

    const claims = run.evidence.filter((e) => e.kind === 'claim');
    const reads = run.evidence.filter((e) => e.kind === 'evidence');

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Agent run ${run.id}`} />

            <div className="flex flex-col gap-6 p-4">
                {run.shadow && (
                    <div className="rounded-xl border border-yellow-300 bg-yellow-50 p-4 text-sm dark:border-yellow-900 dark:bg-yellow-950/40">
                        <strong>This is a shadow run, so what follows is not HQ&rsquo;s answer.</strong> It is what the{' '}
                        <span className="font-mono">{run.agent_key}</span> agent would have said, recorded for review. Autonomy resolved to{' '}
                        <span className="font-mono">{run.autonomy_level?.toUpperCase()}</span>, bound by: {run.autonomy_reason}.
                    </div>
                )}

                <div className="rounded-xl border p-4">
                    <p className="text-muted-foreground text-xs">The question</p>
                    <p className="mt-1 text-base">{run.question}</p>

                    <p className="text-muted-foreground mt-4 text-xs">What it said</p>
                    {run.answer ? (
                        <p className="mt-1 text-base whitespace-pre-wrap">{run.answer}</p>
                    ) : (
                        <p className="text-muted-foreground mt-1 text-sm italic">No answer. {run.error ?? 'The run ended before it produced one.'}</p>
                    )}

                    <dl className="mt-4 grid grid-cols-2 gap-x-6 gap-y-1 text-sm md:grid-cols-4">
                        <dt className="text-muted-foreground">Status</dt>
                        <dd>
                            {run.status}
                            {run.stop_reason ? ` (${run.stop_reason})` : ''}
                        </dd>
                        <dt className="text-muted-foreground">Turns / tools</dt>
                        <dd>
                            {run.turns} / {run.tool_calls_count}
                        </dd>
                        <dt className="text-muted-foreground">Model</dt>
                        <dd className="truncate font-mono text-xs">{run.model ?? '-'}</dd>
                        <dt className="text-muted-foreground">Cost</dt>
                        <dd className="font-mono text-xs">${run.cost_usd.toFixed(4)}</dd>
                        <dt className="text-muted-foreground">Took</dt>
                        <dd>{run.duration_ms ?? '-'} ms</dd>
                        <dt className="text-muted-foreground">Prompt</dt>
                        <dd className="truncate font-mono text-xs">{run.prompt_version ?? '-'}</dd>
                    </dl>
                </div>

                {claims.length > 0 && (
                    <section>
                        <h2 className="mb-2 text-lg font-semibold">Claims, and where each came from</h2>
                        <div className="overflow-x-auto rounded-xl border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        <th className="p-2 font-medium">Statement</th>
                                        <th className="p-2 font-medium">Source</th>
                                        <th className="p-2 font-medium">As of</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {claims.map((c, i) => (
                                        <tr key={i} className="border-t">
                                            <td className="p-2">{c.statement}</td>
                                            <td className="p-2 font-mono text-xs">{c.source ?? '-'}</td>
                                            <td className="text-muted-foreground p-2 text-xs">{c.as_of ?? 'not stated'}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                )}

                {run.proposals.length > 0 && (
                    <section>
                        <h2 className="mb-2 text-lg font-semibold">What it wanted to do and could not</h2>
                        <div className="overflow-x-auto rounded-xl border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        <th className="p-2 font-medium">Kind</th>
                                        <th className="p-2 font-medium">Tool</th>
                                        <th className="p-2 font-medium">Reason it did not run</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {run.proposals.map((p, i) => (
                                        <tr key={i} className="border-t">
                                            <td className="p-2">{p.kind}</td>
                                            <td className="p-2 font-mono text-xs">{p.tool ?? '-'}</td>
                                            <td className="p-2">{p.reason ?? '-'}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                )}

                <section className="grid gap-4 md:grid-cols-2">
                    <div>
                        <h2 className="mb-2 text-lg font-semibold">Tool calls</h2>
                        <div className="overflow-x-auto rounded-xl border">
                            <table className="w-full text-sm">
                                <tbody>
                                    {tool_calls.length === 0 && (
                                        <tr>
                                            <td className="text-muted-foreground p-3 text-sm">None.</td>
                                        </tr>
                                    )}
                                    {tool_calls.map((c) => (
                                        <tr key={c.id} className="border-t">
                                            <td className="p-2 font-mono text-xs">{c.tool_key}</td>
                                            <td className="p-2 text-xs">{c.status}</td>
                                            <td className="text-muted-foreground p-2 text-xs">{c.policy_result ?? '-'}</td>
                                            <td className="p-2 text-xs">{c.duration_ms ?? '-'} ms</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        {reads.length > 0 && (
                            <p className="text-muted-foreground mt-2 text-xs">{reads.length} of these produced evidence the answer rests on.</p>
                        )}
                    </div>

                    <div>
                        <h2 className="mb-2 text-lg font-semibold">Model calls</h2>
                        <div className="overflow-x-auto rounded-xl border">
                            <table className="w-full text-sm">
                                <tbody>
                                    {model_calls.map((m) => (
                                        <tr key={m.id} className="border-t">
                                            <td className="p-2 font-mono text-xs">{m.model}</td>
                                            <td className="p-2 text-xs">{m.status}</td>
                                            <td className="p-2 text-xs">
                                                {m.input_tokens ?? 0} in / {m.output_tokens ?? 0} out
                                            </td>
                                            <td className="p-2 font-mono text-xs">${Number(m.estimated_cost_usd ?? 0).toFixed(4)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>

                <section>
                    <h2 className="mb-2 text-lg font-semibold">What was in front of the model</h2>
                    <p className="text-muted-foreground mb-2 text-xs">
                        Labels and digests only. The text itself is never stored here: a provenance record that carried it would be a second copy of
                        everything a prompt ever held.
                    </p>
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-2 font-medium">Kind</th>
                                    <th className="p-2 font-medium">Source</th>
                                    <th className="p-2 font-medium">Bytes</th>
                                    <th className="p-2 font-medium">Trusted</th>
                                </tr>
                            </thead>
                            <tbody>
                                {run.context_provenance.map((b) => (
                                    <tr key={b.id} className="border-t">
                                        <td className="p-2 text-xs">{b.provenance}</td>
                                        <td className="p-2 font-mono text-xs">{b.source}</td>
                                        <td className="p-2 text-xs">{b.bytes}</td>
                                        <td className="p-2 text-xs">
                                            {b.trusted ? (
                                                'yes'
                                            ) : (
                                                <span className="rounded bg-amber-100 px-1.5 py-0.5 text-amber-900 dark:bg-amber-950 dark:text-amber-200">
                                                    untrusted, caps autonomy
                                                </span>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>

                <section className="rounded-xl border p-4">
                    <h2 className="mb-2 text-lg font-semibold">Review</h2>

                    {run.reviewed_at ? (
                        <p className="text-sm">
                            Reviewed {new Date(run.reviewed_at).toLocaleString()} as <strong>{run.review_verdict}</strong>.
                            {run.review_note && <span className="text-muted-foreground"> {run.review_note}</span>}
                        </p>
                    ) : can.review ? (
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                router.post(`/agents/runs/${run.id}/review`, { verdict, note });
                            }}
                            className="flex flex-col gap-3"
                        >
                            <p className="text-muted-foreground text-sm">
                                A verdict is a dated statement about what you thought at the time, and it is not revised afterwards. It becomes part
                                of the alignment corpus a later evaluation replays.
                            </p>
                            <div className="flex flex-wrap gap-2">
                                {['approved', 'edited', 'rejected'].map((v) => (
                                    <Button
                                        key={v}
                                        type="button"
                                        size="sm"
                                        variant={verdict === v ? 'default' : 'outline'}
                                        onClick={() => setVerdict(v)}
                                    >
                                        {v}
                                    </Button>
                                ))}
                            </div>
                            <textarea
                                value={note}
                                onChange={(e) => setNote(e.target.value)}
                                rows={3}
                                maxLength={2000}
                                placeholder="What was wrong with it, or what you would have said instead."
                                className="w-full rounded-md border p-2 text-sm"
                            />
                            <div>
                                <Button type="submit" size="sm">
                                    Record this verdict
                                </Button>
                            </div>
                        </form>
                    ) : (
                        <p className="text-muted-foreground text-sm">You do not hold the permission to review a run.</p>
                    )}
                </section>
            </div>
        </AppLayout>
    );
}
