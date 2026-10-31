import { DataTable, Note, Panel, Stat, StatGrid, orDash } from '@/components/observe/panels';
import { ReleaseStatePanel, type ReleaseState } from '@/components/observe/release-state-panel';
import { PageHeader } from '@/components/page-header';
import { EmptyState } from '@/components/empty-state';
import { Notice } from '@/components/ui/notice';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, humanDuration, humanKey, humanMillis, timeAgo } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';

type Props = {
    checked_at: string;
    release: ReleaseState;
    queues: { masters: number; paused: boolean; workload: { name: string; length: number; wait_seconds: number; processes: number }[] } | null;
    scheduler: { last_tick: string | null; age_seconds: number | null; stale_after_seconds: number; stale: boolean };
    events: { waiting: number; oldest_waiting_minutes: number | null; dead: number; dead_last_7_days: number; last_occurred_at: string | null };
    circuits: { provider: string; state: string; consecutive_failures: number }[];
    connectors_24h: { connector: string; calls: number; succeeded: number; failed: number; uncertain: number; p95_ms: number | null }[];
    credentials_expiring: { id: number; provider: string; account_ref: string | null; expires_at: string; expired: boolean }[];
    readiness: { target: string; target_kind: string; provider: string; state: string; incident: boolean; supported: boolean }[];
    integrity: { at: string | null; checked: number; discrepancies: number; without_lines: number } | null;
    snapshots: { business: string; kind: string; polled_at: string; as_of: string | null; age_hours: number; stale: boolean }[];
    snapshot_stale_after_hours: number;
    host: {
        disk: { path: string; total_bytes: number; free_bytes: number; used_percent: number } | null;
        memory: { available_mb: number; total_mb: number } | null;
        paged_by: string;
    };
    retention: {
        last_run: {
            uuid: string;
            status: string;
            policy_version: string;
            started_at: string;
            finished_at: string | null;
            rows_removed: number;
            payloads_compacted: number;
        } | null;
        watermarks: { table: string; through_day: string }[];
        policy: { detail_keep_days: Record<string, number>; event_payload_keep_days: number };
    };
    alerts_open: Record<string, number>;
};

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Platform', href: '/observe/platform' }];

const GB = 1073741824;

export default function Platform(props: Props) {
    const t = useT();
    const { queues, scheduler, events, host, retention } = props;
    const openAlerts = Object.values(props.alerts_open).reduce((a, b) => a + b, 0);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Platform" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Platform"
                    description={t('The machinery under HQ: queues, scheduler, events, connectors, credentials, data freshness, the host and retention. Read {ago}.', { ago: timeAgo(props.checked_at) })}
                    actions={
                        <Link href="/observe/alerts" className="inline-flex min-h-8 items-center rounded-full touch:min-h-11">
                            <StatusBadge tone={openAlerts > 0 ? 'warn' : 'ok'}>{t('{n} alert(s) open', { n: openAlerts })}</StatusBadge>
                        </Link>
                    }
                />

                {scheduler.stale && (
                    <Notice tone="warn" title="The scheduler is not ticking">
                        {scheduler.age_seconds === null
                            ? t('No tick has been recorded.') + ' '
                            : t('The last tick was {ago} ago.', { ago: humanDuration(scheduler.age_seconds) }) + ' '}
                        {t('Until it ticks again, alert checks, sweeps, the event drain and scheduled jobs do not run, so the alert list is not being refreshed.')}
                    </Notice>
                )}

                <ReleaseStatePanel release={props.release} />

                <StatGrid>
                    <Stat
                        label="Scheduler"
                        value={scheduler.age_seconds === null ? t('no tick') : humanDuration(scheduler.age_seconds)}
                        detail={t('since the last tick; stale after {n} s', { n: scheduler.stale_after_seconds })}
                        badge={<StatusBadge status={scheduler.stale ? 'stale' : 'ok'} />}
                    />
                    <Stat
                        label="Queues (Horizon)"
                        value={queues === null ? t('unknown') : t('{n} supervisor(s)', { n: queues.masters })}
                        detail={queues === null ? 'Redis did not answer from here.' : queues.paused ? 'paused' : 'running'}
                        badge={queues === null ? undefined : <StatusBadge status={queues.masters === 0 || queues.paused ? 'stopped' : 'ok'} />}
                    />
                    <Stat
                        label="Events waiting"
                        value={events.waiting}
                        detail={
                            events.oldest_waiting_minutes === null ? 'nothing waiting' : t('oldest {age}', { age: humanDuration(events.oldest_waiting_minutes * 60) })
                        }
                    />
                    <Stat
                        label="Events dead"
                        value={events.dead}
                        detail={t('{n} in the last 7 days; last event {ago}', { n: events.dead_last_7_days, ago: timeAgo(events.last_occurred_at) })}
                        badge={events.dead_last_7_days > 0 ? <StatusBadge status="dead" /> : undefined}
                    />
                </StatGrid>

                <Panel title="Queue workload">
                    <DataTable
                        rowKey={(q) => q.name}
                        rows={queues?.workload ?? []}
                        empty={queues === null ? 'Horizon could not be asked from here.' : 'No queue has work.'}
                        columns={[
                            { key: 'name', label: 'Queue', render: (q) => <span className="font-mono text-xs">{q.name}</span> },
                            { key: 'length', label: 'Jobs', align: 'right', render: (q) => q.length },
                            { key: 'wait', label: 'Wait', align: 'right', render: (q) => humanDuration(q.wait_seconds) },
                            { key: 'processes', label: 'Workers', align: 'right', render: (q) => q.processes },
                        ]}
                    />
                </Panel>

                <div className="grid gap-6 xl:grid-cols-2">
                    <Panel title="Model provider circuits">
                        <DataTable
                            rowKey={(c) => c.provider}
                            rows={props.circuits}
                            empty="No model provider is registered."
                            columns={[
                                { key: 'provider', label: 'Provider', render: (c) => c.provider },
                                {
                                    key: 'state',
                                    label: 'Circuit',
                                    render: (c) => (
                                        <StatusBadge status={c.state === 'closed' ? 'ok' : c.state === 'open' ? 'stopped' : 'pending'}>
                                            {humanKey(c.state)}
                                        </StatusBadge>
                                    ),
                                },
                                { key: 'failures', label: 'Recent failures', align: 'right', render: (c) => c.consecutive_failures },
                            ]}
                        />
                    </Panel>

                    <Panel title="Connector calls, last 24 hours">
                        <DataTable
                            rowKey={(c) => c.connector}
                            rows={props.connectors_24h}
                            empty="No connector call in the last day."
                            columns={[
                                { key: 'connector', label: 'Connector', render: (c) => c.connector },
                                { key: 'calls', label: 'Calls', align: 'right', render: (c) => c.calls },
                                { key: 'ok', label: 'Succeeded', align: 'right', render: (c) => c.succeeded },
                                { key: 'failed', label: 'Failed', align: 'right', render: (c) => c.failed },
                                { key: 'uncertain', label: 'Uncertain', align: 'right', render: (c) => c.uncertain },
                                { key: 'p95', label: '95th pct', align: 'right', render: (c) => humanMillis(c.p95_ms) },
                            ]}
                        />
                    </Panel>
                </div>

                <Panel
                    title="Readiness"
                    aside={
                        <Link href="/credentials" className="inline-flex min-h-8 items-center underline touch:min-h-11">
                            {t('Credentials screen')}
                        </Link>
                    }
                >
                    <DataTable
                        rowKey={(r, i) => `${r.target_kind}-${r.target}-${r.provider}-${i}`}
                        rows={props.readiness}
                        empty="HQ has no store, ERP business or model provider to reach."
                        columns={[
                            { key: 'target', label: 'Target', render: (r) => r.target },
                            { key: 'kind', label: 'Kind', render: (r) => r.target_kind },
                            { key: 'provider', label: 'Credential', render: (r) => <span className="font-mono text-xs">{r.provider}</span> },
                            {
                                key: 'state',
                                label: 'State',
                                render: (r) => (
                                    <StatusBadge tone={r.incident ? 'danger' : r.state === 'verified' ? 'ok' : 'neutral'}>
                                        {humanKey(r.state)}
                                    </StatusBadge>
                                ),
                            },
                        ]}
                    />
                </Panel>

                <div className="grid gap-6 xl:grid-cols-2">
                    <Panel title="Credentials expiring within 30 days">
                        <DataTable
                            rowKey={(c) => String(c.id)}
                            rows={props.credentials_expiring}
                            empty="No active credential expires within 30 days."
                            columns={[
                                { key: 'provider', label: 'Credential', render: (c) => `${c.provider}${c.account_ref ? ` (${c.account_ref})` : ''}` },
                                { key: 'expires', label: 'Expires', render: (c) => formatDateTime(c.expires_at) },
                                {
                                    key: 'state',
                                    label: '',
                                    render: (c) => (
                                        <StatusBadge status={c.expired ? 'expired' : 'pending'}>{c.expired ? t('expired') : t('soon')}</StatusBadge>
                                    ),
                                },
                            ]}
                        />
                    </Panel>

                    <Panel title="Order-fact integrity">
                        {props.integrity === null ? (
                            <EmptyState title="The integrity check has not run yet." description="This panel fills after the first check of the order facts." />
                        ) : (
                            <StatGrid>
                                <Stat label="Checked" value={props.integrity.checked} detail={t('last run {ago}', { ago: timeAgo(props.integrity.at) })} />
                                <Stat
                                    label="Discrepancies"
                                    value={props.integrity.discrepancies}
                                    badge={<StatusBadge status={props.integrity.discrepancies > 0 ? 'failed' : 'ok'} />}
                                />
                                <Stat label="Without line detail" value={props.integrity.without_lines} />
                            </StatGrid>
                        )}
                    </Panel>
                </div>

                <Panel title="ERP snapshots" aside={t('stale after {n} h; the polls are daily', { n: props.snapshot_stale_after_hours })}>
                    <DataTable
                        rowKey={(s) => `${s.business}-${s.kind}`}
                        rows={props.snapshots}
                        empty="HQ holds no ERP snapshot."
                        columns={[
                            { key: 'business', label: 'Business', render: (s) => s.business },
                            { key: 'kind', label: 'Snapshot', render: (s) => humanKey(s.kind) },
                            { key: 'polled', label: 'Fetched', render: (s) => `${formatDateTime(s.polled_at)} (${s.age_hours} h)` },
                            { key: 'as_of', label: 'ERP says as of', render: (s) => orDash(s.as_of && formatDateTime(s.as_of)) },
                            { key: 'state', label: '', render: (s) => <StatusBadge status={s.stale ? 'stale' : 'ok'} /> },
                        ]}
                    />
                </Panel>

                <div className="grid gap-6 xl:grid-cols-2">
                    <Panel title="Host" aside={t('paged by {who}', { who: host.paged_by })}>
                        <StatGrid>
                            <Stat
                                label="Disk used"
                                value={host.disk === null ? t('unknown') : `${host.disk.used_percent}%`}
                                detail={
                                    host.disk === null
                                        ? 'Not readable from here.'
                                        : t('{free} GB free of {total} GB', {
                                              free: (host.disk.free_bytes / GB).toFixed(1),
                                              total: (host.disk.total_bytes / GB).toFixed(1),
                                          })
                                }
                            />
                            <Stat
                                label="Memory available"
                                value={host.memory === null ? t('unknown') : `${host.memory.available_mb} MB`}
                                detail={host.memory === null ? 'Not readable from here.' : t('of {n} MB', { n: host.memory.total_mb })}
                            />
                        </StatGrid>
                        <Note>Backups are checked by the host monitor, which can see the backup directory; the application cannot.</Note>
                    </Panel>

                    <Panel title="Retention">
                        {retention.last_run === null ? (
                            <EmptyState title="No retention pass has run yet." description="This panel fills after the first retention pass." />
                        ) : (
                            <StatGrid>
                                <Stat
                                    label="Last pass"
                                    value={<StatusBadge status={retention.last_run.status} />}
                                    detail={`${formatDateTime(retention.last_run.started_at)}, ${retention.last_run.policy_version}`}
                                />
                                <Stat label="Rows rolled up" value={retention.last_run.rows_removed} />
                                <Stat label="Payloads reduced" value={retention.last_run.payloads_compacted} />
                            </StatGrid>
                        )}
                        <Note>
                            {t('Detail kept {kept}; event payloads {n} days. Watermarks: {marks}.', {
                                kept: Object.entries(retention.policy.detail_keep_days)
                                    .map(([table, d]) => t('{table} {n} days', { table, n: d }))
                                    .join(', '),
                                n: retention.policy.event_payload_keep_days,
                                marks:
                                    retention.watermarks.length === 0
                                        ? t('none yet')
                                        : retention.watermarks.map((w) => t('{table} through {day}', { table: w.table, day: w.through_day })).join(', '),
                            })}
                        </Note>
                    </Panel>
                </div>
            </div>
        </AppLayout>
    );
}
