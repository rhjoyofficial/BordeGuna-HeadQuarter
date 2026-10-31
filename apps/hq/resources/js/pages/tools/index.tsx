import { ConfirmDialog } from '@/components/confirm-dialog';
import { EmptyState } from '@/components/empty-state';
import { JsonBlock, PageHeader, TechnicalDetail, Toolbar } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Notice } from '@/components/ui/notice';
import { StatusBadge, type Tone } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, humanKey, humanMillis, timeAgo } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { ChevronDown, ChevronRight, Wrench } from 'lucide-react';
import { Fragment, useState } from 'react';

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

function riskTone(tier: string): Tone {
    if (tier === 'r5' || tier === 'r4') return 'danger';
    if (tier === 'r3') return 'warn';
    if (tier === 'r2') return 'info';
    return 'neutral';
}

export default function ToolsIndex({
    tools,
    orphans,
    environment,
    filters,
    switch: groupSwitch,
}: {
    tools: Tool[];
    orphans: string[];
    environment: string;
    filters: { q: string; state: string };
    /** docs/138 ruling 13: may this person throw a switch that acts on every business, and if not, why. */
    switch: { allowed: boolean; refusal: string | null };
}) {
    const t = useT();
    const { auth } = usePage<{ auth: { permissions: string[] } }>().props;
    const holdsPermission = auth.permissions.includes('hq.tools.manage');
    // docs/138 ruling 13: the permission alone is not enough; the server also says the person is group-wide.
    const canManage = holdsPermission && groupSwitch.allowed;
    const [pending, setPending] = useState<string | null>(null);
    const [open, setOpen] = useState<string | null>(null);
    const [query, setQuery] = useState(filters.q ?? '');
    const [disabling, setDisabling] = useState<Tool | null>(null);
    const [enabling, setEnabling] = useState<Tool | null>(null);

    const applyFilters = (q: string, state: string) => {
        router.get('/tools', { q: q || undefined, state: state === 'all' ? undefined : state }, { preserveState: true, replace: true });
    };

    const setEnabled = (tool: Tool, enabled: boolean, reason?: string) => {
        setPending(tool.key);
        router.post(
            `/tools/${tool.key}/toggle`,
            { enabled, reason },
            {
                preserveScroll: true,
                onFinish: () => {
                    setPending(null);
                    setDisabling(null);
                    setEnabling(null);
                },
            },
        );
    };

    const enabledCount = tools.filter((t) => t.runtime.enabled).length;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Tools" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Tools"
                    description={
                        <>
                            Everything HQ can do. A tool exists because it is registered in code and deployed; the switch here can only turn one off,
                            for every caller at once: the scheduler, the console, an API caller and any agent. Environment: {environment}.
                        </>
                    }
                    actions={
                        <StatusBadge tone={enabledCount === tools.length ? 'ok' : 'warn'}>
                            {enabledCount} of {tools.length} enabled
                        </StatusBadge>
                    }
                >
                    <Toolbar label="Filter tools">
                        <Input
                            className="w-full sm:w-64"
                            aria-label="Search by name or purpose"
                            placeholder="Search by name or purpose"
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                            onKeyDown={(e) => e.key === 'Enter' && applyFilters(query, filters.state)}
                        />
                        {[
                            { key: 'all', label: 'All' },
                            { key: 'enabled', label: 'Enabled' },
                            { key: 'disabled', label: 'Disabled' },
                        ].map((s) => (
                            <Button
                                key={s.key}
                                size="sm"
                                variant={filters.state === s.key ? 'default' : 'outline'}
                                onClick={() => applyFilters(query, s.key)}
                            >
                                {s.label}
                            </Button>
                        ))}
                    </Toolbar>
                </PageHeader>

                {holdsPermission && !groupSwitch.allowed && (
                    <Notice tone="warn" title="Group-wide switch">
                        {t(
                            groupSwitch.refusal ??
                                'This switch acts on every business, and your access is limited to some businesses. Only a person with group-wide access may change it.',
                        )}
                    </Notice>
                )}

                {orphans.length > 0 && (
                    <Notice tone="pending">
                        <strong>Switch rows without a tool.</strong> These were switched off once and the code has since gone. They are kept, not
                        deleted, so the decision survives the code coming back: <span className="font-mono text-xs">{orphans.join(', ')}</span>
                    </Notice>
                )}

                {tools.length === 0 ? (
                    <EmptyState
                        icon={Wrench}
                        title="No tools match"
                        description="The search or the filter hides every registered tool. Clear the search or pick another filter."
                    />
                ) : (
                    <div className="hq-table-box">
                        <table className="w-full text-sm">
                            <thead className="text-left text-xs">
                                <tr>
                                    <th className="px-3 py-2 font-medium">Tool</th>
                                    <th className="px-3 py-2 font-medium">Risk</th>
                                    <th className="px-3 py-2 font-medium">Before it runs</th>
                                    <th className="px-3 py-2 font-medium">Agents</th>
                                    <th className="px-3 py-2 font-medium">Last 24 h</th>
                                    <th className="px-3 py-2 font-medium">Switch</th>
                                    <th className="px-3 py-2"></th>
                                </tr>
                            </thead>
                            <tbody>
                                {tools.map((t) => {
                                    const expanded = open === t.key;
                                    return (
                                        <Fragment key={t.key}>
                                            <tr className="border-t align-top">
                                                <td className="max-w-md px-3 py-3">
                                                    <button
                                                        type="button"
                                                        className="touch:min-h-11 flex items-start gap-1.5 rounded-md text-left font-medium hover:underline"
                                                        onClick={() => setOpen(expanded ? null : t.key)}
                                                        aria-expanded={expanded}
                                                    >
                                                        {expanded ? (
                                                            <ChevronDown className="mt-0.5 size-4 shrink-0" aria-hidden />
                                                        ) : (
                                                            <ChevronRight className="mt-0.5 size-4 shrink-0" aria-hidden />
                                                        )}
                                                        {t.title}
                                                    </button>
                                                    <div className="text-muted-foreground mt-0.5 pl-5.5 text-xs">{t.purpose}</div>
                                                    <div className="text-muted-foreground mt-1 flex flex-wrap gap-x-3 pl-5.5 text-[11px]">
                                                        <span className="font-mono">
                                                            {t.key} v{t.version}
                                                        </span>
                                                        {t.credentials.length > 0 && (
                                                            <span>needs {t.credentials.map((c) => c.provider).join(', ')}</span>
                                                        )}
                                                        {t.governing_policy && (
                                                            <span className="text-tone-pending">
                                                                policy {t.governing_policy.key} v{t.governing_policy.version}
                                                            </span>
                                                        )}
                                                    </div>
                                                </td>
                                                <td className="px-3 py-3">
                                                    <StatusBadge tone={riskTone(t.risk_tier)} dot={false}>
                                                        {t.risk_label}
                                                    </StatusBadge>
                                                </td>
                                                <td className="px-3 py-3 text-xs whitespace-nowrap">
                                                    {t.baseline_action === 'approval_required' ? (
                                                        <StatusBadge tone="warn">a person approves</StatusBadge>
                                                    ) : (
                                                        <span className="text-muted-foreground">runs on request</span>
                                                    )}
                                                </td>
                                                <td className="px-3 py-3 text-xs whitespace-nowrap">
                                                    {t.agent_executable ? (
                                                        <StatusBadge tone="pending">may run it</StatusBadge>
                                                    ) : (
                                                        <span className="text-muted-foreground">never</span>
                                                    )}
                                                </td>
                                                <td className="px-3 py-3 text-xs whitespace-nowrap">
                                                    {t.calls_24h > 0 ? (
                                                        <div className="flex items-center gap-2">
                                                            <StatusBadge tone={t.ok_24h === t.calls_24h ? 'ok' : 'warn'} dot={false}>
                                                                {t.ok_24h}/{t.calls_24h} ok
                                                            </StatusBadge>
                                                            {t.last_call_at && (
                                                                <span className="text-muted-foreground">{timeAgo(t.last_call_at)}</span>
                                                            )}
                                                        </div>
                                                    ) : (
                                                        <span className="text-muted-foreground">no calls</span>
                                                    )}
                                                </td>
                                                <td className="px-3 py-3 text-xs">
                                                    {t.runtime.enabled ? (
                                                        <StatusBadge status="enabled">on</StatusBadge>
                                                    ) : (
                                                        <div className="flex flex-col gap-1">
                                                            <StatusBadge status="disabled">off</StatusBadge>
                                                            {t.runtime.disabled_reason && (
                                                                <span className="text-muted-foreground max-w-48">{t.runtime.disabled_reason}</span>
                                                            )}
                                                            {t.runtime.disabled_at && (
                                                                <span className="text-muted-foreground">{timeAgo(t.runtime.disabled_at)}</span>
                                                            )}
                                                        </div>
                                                    )}
                                                </td>
                                                <td className="px-3 py-3 text-right">
                                                    {canManage &&
                                                        (t.runtime.enabled ? (
                                                            <Button
                                                                size="sm"
                                                                variant="outline"
                                                                disabled={pending === t.key}
                                                                onClick={() => setDisabling(t)}
                                                            >
                                                                Turn off
                                                            </Button>
                                                        ) : (
                                                            <Button size="sm" disabled={pending === t.key} onClick={() => setEnabling(t)}>
                                                                Turn on
                                                            </Button>
                                                        ))}
                                                </td>
                                            </tr>
                                            {expanded && (
                                                <tr className="bg-muted/40">
                                                    <td colSpan={7} className="px-4 py-4">
                                                        <div className="grid gap-4 md:grid-cols-3">
                                                            <div>
                                                                <div className="hq-eyebrow mb-1">Contract</div>
                                                                <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-0.5 text-xs">
                                                                    <dt className="text-muted-foreground">Reads or writes</dt>
                                                                    <dd>{t.read_write}</dd>
                                                                    <dt className="text-muted-foreground">Safe to repeat</dt>
                                                                    <dd>{t.idempotent ? 'yes' : 'no'}</dd>
                                                                    <dt className="text-muted-foreground">Timeout</dt>
                                                                    <dd>{t.timeout_seconds} s</dd>
                                                                    <dt className="text-muted-foreground">Replay window</dt>
                                                                    <dd>{t.replay_within_seconds} s</dd>
                                                                    <dt className="text-muted-foreground">Scope</dt>
                                                                    <dd>
                                                                        {t.scope}
                                                                        {t.scope_from ? ` from ${t.scope_from}` : ''}
                                                                    </dd>
                                                                    <dt className="text-muted-foreground">Businesses</dt>
                                                                    <dd>{t.supported_businesses.join(', ') || 'any'}</dd>
                                                                    <dt className="text-muted-foreground">Stores</dt>
                                                                    <dd>{t.supported_stores.join(', ') || 'any'}</dd>
                                                                    <dt className="text-muted-foreground">Permission</dt>
                                                                    <dd className="font-mono">{t.permission}</dd>
                                                                    <dt className="text-muted-foreground">Policy class</dt>
                                                                    <dd className="font-mono break-all">{t.policy_class ?? 'none'}</dd>
                                                                </dl>
                                                            </div>
                                                            <div>
                                                                <div className="hq-eyebrow mb-1">Recent calls</div>
                                                                {t.recent.length === 0 && (
                                                                    <div className="text-muted-foreground text-xs">
                                                                        No calls recorded for this tool yet.
                                                                    </div>
                                                                )}
                                                                <ul className="space-y-1 text-xs">
                                                                    {t.recent.map((c) => (
                                                                        <li key={c.id} className="flex flex-wrap items-center gap-2">
                                                                            <StatusBadge status={c.status} dot={false} />
                                                                            <span className="text-muted-foreground">
                                                                                {c.caller_kind
                                                                                    ? humanKey(c.caller_kind).toLowerCase()
                                                                                    : 'unknown caller'}
                                                                                {c.actor_label ? ` (${c.actor_label})` : ''}
                                                                            </span>
                                                                            <span className="text-muted-foreground">
                                                                                {humanMillis(c.duration_ms)}
                                                                            </span>
                                                                            <span
                                                                                className="text-muted-foreground"
                                                                                title={formatDateTime(c.created_at)}
                                                                            >
                                                                                {timeAgo(c.created_at)}
                                                                            </span>
                                                                        </li>
                                                                    ))}
                                                                </ul>
                                                            </div>
                                                            <div className="flex flex-col gap-2">
                                                                <TechnicalDetail summary="Input schema">
                                                                    <JsonBlock value={t.input_schema} />
                                                                </TechnicalDetail>
                                                                <TechnicalDetail summary="Output schema">
                                                                    <JsonBlock value={t.output_schema} />
                                                                </TechnicalDetail>
                                                                {t.governing_policy && (
                                                                    <TechnicalDetail summary="Governing policy rules">
                                                                        <JsonBlock value={t.governing_policy.rules} />
                                                                    </TechnicalDetail>
                                                                )}
                                                            </div>
                                                        </div>
                                                    </td>
                                                </tr>
                                            )}
                                        </Fragment>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>

            <ConfirmDialog
                open={disabling !== null}
                onOpenChange={(o) => !o && setDisabling(null)}
                title={`Turn off ${disabling?.title ?? ''}?`}
                description="Every caller loses it at once: scheduled jobs that use it will fail, and an agent that asks for it is refused. The reason is recorded and shown here until it is turned back on."
                confirmLabel="Turn off"
                destructive
                busy={pending !== null}
                reason={{ label: 'Why', placeholder: 'What happened, and what has to be true before it comes back.', required: true }}
                onConfirm={(reason) => disabling && setEnabled(disabling, false, reason)}
            />

            <ConfirmDialog
                open={enabling !== null}
                onOpenChange={(o) => !o && setEnabling(null)}
                title={`${t('Turn on')} ${enabling?.title ?? ''}?`}
                description="Every caller gets it back at once, for every business. The reason is kept in the audit log with who changed it, before and after."
                confirmLabel={t('Turn on')}
                busy={pending !== null}
                reason={{ label: t('Why'), placeholder: t('What was fixed, and why it is safe to turn back on.'), required: true }}
                onConfirm={(reason) => enabling && setEnabled(enabling, true, reason)}
            />
        </AppLayout>
    );
}
