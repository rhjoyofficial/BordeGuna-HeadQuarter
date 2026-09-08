import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

type Runtime = {
    enabled: boolean;
    has_row: boolean;
    disabled_reason: string | null;
    disabled_at: string | null;
};

type RecentCall = {
    id: number;
    status: string;
    policy_result: string | null;
    caller_kind: string | null;
    actor_label: string | null;
    duration_ms: number | null;
    created_at: string;
};

type Tool = {
    key: string;
    version: string;
    title: string;
    purpose: string;
    risk_tier: string;
    risk_label: string;
    baseline_action: string;
    permission: string;
    scope: string;
    scope_from: string | null;
    read_write: string;
    agent_executable: boolean;
    idempotent: boolean;
    timeout_seconds: number;
    replay_within_seconds: number;
    supported_businesses: string[];
    supported_stores: string[];
    policy_class: string | null;
    credentials: { provider: string; account_ref_from: string; optional: boolean }[];
    runtime: Runtime;
    calls_24h: number;
    ok_24h: number;
    last_call_at: string | null;
    input_schema: Record<string, unknown>;
    output_schema: Record<string, unknown>;
    governing_policy: { key: string; version: number; rules: Record<string, unknown>; active_from: string | null } | null;
    recent: RecentCall[];
};

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Tools', href: '/tools' }];

const tierTone: Record<string, string> = {
    r0: 'text-muted-foreground',
    r1: 'text-muted-foreground',
    r2: 'text-sky-700',
    r3: 'text-amber-700',
    r4: 'text-orange-700',
    r5: 'text-red-700',
};

const statusTone: Record<string, string> = {
    succeeded: 'text-green-700',
    replayed: 'text-green-700',
    failed: 'text-red-700',
    denied: 'text-orange-700',
    invalid: 'text-orange-700',
    unavailable: 'text-orange-700',
    uncertain: 'text-amber-700',
    started: 'text-muted-foreground',
};

export default function ToolsIndex({
    tools,
    orphans,
    environment,
    filters,
}: {
    tools: Tool[];
    orphans: string[];
    environment: string;
    filters: { q: string; state: string };
}) {
    const { auth } = usePage<{ auth: { permissions: string[] } }>().props;
    const canManage = auth.permissions.includes('hq.tools.manage');
    const [pending, setPending] = useState<string | null>(null);
    const [open, setOpen] = useState<string | null>(null);
    const [query, setQuery] = useState(filters.q ?? '');

    const applyFilters = (q: string, state: string) => {
        router.get('/tools', { q: q || undefined, state: state === 'all' ? undefined : state }, { preserveState: true, replace: true });
    };

    const toggle = (tool: Tool) => {
        if (tool.runtime.enabled) {
            const reason = window.prompt(`Disable ${tool.key} for every caller. Why?`);
            if (!reason || reason.trim() === '') return;
            setPending(tool.key);
            router.post(`/tools/${tool.key}/toggle`, { enabled: false, reason }, { preserveScroll: true, onFinish: () => setPending(null) });
            return;
        }
        setPending(tool.key);
        router.post(`/tools/${tool.key}/toggle`, { enabled: true }, { preserveScroll: true, onFinish: () => setPending(null) });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Tools" />
            <div className="flex flex-col gap-6 p-4">
                <div className="text-muted-foreground text-sm">
                    Everything HQ can do. A tool exists because a class is registered in <span className="font-mono">config/hq.php</span> and
                    deployed; the switch here can only turn one <span className="font-medium">off</span>, never create one. Disabled means
                    unavailable to every caller: the scheduler, the console, an API caller and any future agent. Environment:{' '}
                    <span className="font-mono">{environment}</span>.
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <Input
                        className="max-w-xs"
                        placeholder="Filter by key, title or purpose"
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        onKeyDown={(e) => e.key === 'Enter' && applyFilters(query, filters.state)}
                    />
                    {['all', 'enabled', 'disabled'].map((s) => (
                        <Button key={s} size="sm" variant={filters.state === s ? 'default' : 'outline'} onClick={() => applyFilters(query, s)}>
                            {s}
                        </Button>
                    ))}
                </div>

                {orphans.length > 0 && (
                    <div className="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
                        These have a runtime row but are no longer registered in code. They are kept, not deleted, so a recorded decision to
                        disable something survives the code coming back: <span className="font-mono">{orphans.join(', ')}</span>
                    </div>
                )}

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead className="text-muted-foreground text-left text-xs uppercase">
                            <tr>
                                <th className="px-4 py-2">Tool</th>
                                <th className="px-4 py-2">Risk</th>
                                <th className="px-4 py-2">Approval</th>
                                <th className="px-4 py-2">Agents</th>
                                <th className="px-4 py-2">Permission</th>
                                <th className="px-4 py-2">Scope</th>
                                <th className="px-4 py-2">24 h</th>
                                <th className="px-4 py-2">Runtime</th>
                                <th className="px-4 py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            {tools.map((t) => (
                                <>
                                    <tr key={t.key} className="border-t align-top">
                                        <td className="px-4 py-3">
                                            <button className="text-left font-mono text-xs underline-offset-2 hover:underline" onClick={() => setOpen(open === t.key ? null : t.key)}>
                                                {t.key} <span className="text-muted-foreground">v{t.version}</span>
                                            </button>
                                            <div className="text-muted-foreground max-w-lg text-xs">{t.purpose}</div>
                                            {t.credentials.length > 0 && (
                                                <div className="text-muted-foreground mt-1 font-mono text-[11px]">
                                                    needs: {t.credentials.map((c) => c.provider).join(', ')}
                                                </div>
                                            )}
                                            {t.governing_policy && (
                                                <div className="mt-1 text-[11px] text-amber-700">
                                                    policy {t.governing_policy.key} v{t.governing_policy.version}
                                                </div>
                                            )}
                                        </td>
                                        <td className={`px-4 py-3 text-xs ${tierTone[t.risk_tier] ?? ''}`}>{t.risk_label}</td>
                                        <td className="px-4 py-3 text-xs">
                                            {t.baseline_action === 'approval_required' ? (
                                                <span className="text-orange-700">human approval</span>
                                            ) : (
                                                <span className="text-muted-foreground">none</span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-xs">
                                            {t.agent_executable ? <span className="text-amber-700">may run</span> : <span className="text-muted-foreground">never</span>}
                                        </td>
                                        <td className="px-4 py-3 font-mono text-[11px]">{t.permission}</td>
                                        <td className="px-4 py-3 text-xs">
                                            {t.scope}
                                            {t.scope_from && <div className="text-muted-foreground font-mono text-[11px]">{t.scope_from}</div>}
                                        </td>
                                        <td className="px-4 py-3 text-xs">
                                            {t.calls_24h > 0 ? (
                                                <span>
                                                    {t.ok_24h}/{t.calls_24h} ok
                                                </span>
                                            ) : (
                                                <span className="text-muted-foreground">none</span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-xs">
                                            {t.runtime.enabled ? (
                                                <span className="text-green-700">enabled</span>
                                            ) : (
                                                <span className="text-red-700">
                                                    disabled
                                                    {t.runtime.disabled_reason && (
                                                        <span className="text-muted-foreground block max-w-xs">{t.runtime.disabled_reason}</span>
                                                    )}
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            {canManage && (
                                                <Button size="sm" variant="outline" disabled={pending === t.key} onClick={() => toggle(t)}>
                                                    {t.runtime.enabled ? 'Disable' : 'Enable'}
                                                </Button>
                                            )}
                                        </td>
                                    </tr>
                                    {open === t.key && (
                                        <tr key={`${t.key}-detail`} className="border-t bg-muted/30">
                                            <td colSpan={9} className="px-4 py-4">
                                                <div className="grid gap-4 md:grid-cols-3">
                                                    <div>
                                                        <div className="text-muted-foreground mb-1 text-xs uppercase">Contract</div>
                                                        <dl className="text-xs">
                                                            <div>read/write: {t.read_write}</div>
                                                            <div>idempotent: {String(t.idempotent)}</div>
                                                            <div>timeout: {t.timeout_seconds}s</div>
                                                            <div>replay window: {t.replay_within_seconds}s</div>
                                                            <div>businesses: {t.supported_businesses.join(', ')}</div>
                                                            <div>stores: {t.supported_stores.join(', ')}</div>
                                                            <div>policy class: {t.policy_class ?? 'none'}</div>
                                                        </dl>
                                                    </div>
                                                    <div>
                                                        <div className="text-muted-foreground mb-1 text-xs uppercase">Input schema</div>
                                                        <pre className="max-h-48 overflow-auto rounded bg-background p-2 text-[11px]">
                                                            {JSON.stringify(t.input_schema, null, 2)}
                                                        </pre>
                                                    </div>
                                                    <div>
                                                        <div className="text-muted-foreground mb-1 text-xs uppercase">Recent calls</div>
                                                        {t.recent.length === 0 && <div className="text-muted-foreground text-xs">none</div>}
                                                        <ul className="text-[11px]">
                                                            {t.recent.map((c) => (
                                                                <li key={c.id} className="font-mono">
                                                                    <span className={statusTone[c.status] ?? ''}>{c.status}</span> {c.policy_result ?? '-'}{' '}
                                                                    {c.caller_kind ?? '-'} {c.duration_ms ?? '-'}ms
                                                                </li>
                                                            ))}
                                                        </ul>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    )}
                                </>
                            ))}
                            {tools.length === 0 && (
                                <tr>
                                    <td colSpan={9} className="text-muted-foreground px-4 py-6 text-center">
                                        No tools match that filter.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
        </AppLayout>
    );
}
