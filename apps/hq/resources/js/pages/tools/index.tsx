import { Button } from '@/components/ui/button';
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
    read_write: string;
    agent_executable: boolean;
    idempotent: boolean;
    credentials: { provider: string; account_ref_from: string; optional: boolean }[];
    runtime: Runtime;
    calls_24h: number;
    last_call_at: string | null;
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

export default function ToolsIndex({ tools, orphans, environment }: { tools: Tool[]; orphans: string[]; environment: string }) {
    const { auth } = usePage<{ auth: { permissions: string[] } }>().props;
    const canManage = auth.permissions.includes('hq.tools.manage');
    const [pending, setPending] = useState<string | null>(null);

    const toggle = (tool: Tool) => {
        if (tool.runtime.enabled) {
            const reason = window.prompt(`Disable ${tool.key} for every caller. Why?`);
            if (!reason || reason.trim() === '') return;
            setPending(tool.key);
            router.post(
                `/tools/${tool.key}/toggle`,
                { enabled: false, reason },
                { preserveScroll: true, onFinish: () => setPending(null) },
            );
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
                    Everything HQ can do. A tool exists because a class is registered in{' '}
                    <span className="font-mono">config/hq.php</span> and deployed; the switch here can only turn one{' '}
                    <span className="font-medium">off</span>, never create one. Environment: <span className="font-mono">{environment}</span>.
                </div>

                {orphans.length > 0 && (
                    <div className="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
                        These have a runtime row but are no longer registered in code. They are kept, not deleted, so a
                        recorded decision to disable something survives the code coming back:{' '}
                        <span className="font-mono">{orphans.join(', ')}</span>
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
                                <th className="px-4 py-2">24 h</th>
                                <th className="px-4 py-2">Runtime</th>
                                <th className="px-4 py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            {tools.map((t) => (
                                <tr key={t.key} className="border-t align-top">
                                    <td className="px-4 py-3">
                                        <div className="font-mono text-xs">
                                            {t.key} <span className="text-muted-foreground">v{t.version}</span>
                                        </div>
                                        <div className="text-muted-foreground max-w-lg text-xs">{t.purpose}</div>
                                        {t.credentials.length > 0 && (
                                            <div className="text-muted-foreground mt-1 font-mono text-[11px]">
                                                needs: {t.credentials.map((c) => c.provider).join(', ')}
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
                                        {t.agent_executable ? (
                                            <span className="text-amber-700">may run</span>
                                        ) : (
                                            <span className="text-muted-foreground">never</span>
                                        )}
                                    </td>
                                    <td className="px-4 py-3 font-mono text-[11px]">{t.permission}</td>
                                    <td className="px-4 py-3 text-xs">{t.calls_24h}</td>
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
                            ))}
                            {tools.length === 0 && (
                                <tr>
                                    <td colSpan={8} className="text-muted-foreground px-4 py-6 text-center">
                                        No tools registered.
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
