import { ReasonAction } from '@/components/completion/reason-action';
import { EmptyState } from '@/components/empty-state';
import { Field, FormRow, NativeSelect } from '@/components/form/field';
import { PageHeader, SectionHeader } from '@/components/page-header';
import { type ListMeta, Pagination } from '@/components/pagination';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Notice } from '@/components/ui/notice';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/format';
import { formatNumber, useT } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

/**
 * Controlled autonomy (docs/89 §3.16, docs/145-d): the evidence ladder per tool per business, from HQ's own
 * records of what people approved and how it went. Joy sets the numbers; with none set, nothing is ever
 * grantable. The grant is a person's act; revoking is instant; Pause All AI overrides every grant.
 */

type Evidence = {
    proposed: number;
    approved: number;
    rejected: number;
    executed: number;
    succeeded: number;
    errors: number;
    rejection_permille: number | null;
    error_permille: number | null;
    window_days: number;
};
type Threshold = {
    max_level: string;
    min_approved_succeeded: number;
    max_error_permille: number;
    max_rejection_permille: number;
    window_days: number;
};
type Row = {
    business_id: number;
    business: string;
    tool_key: string;
    tool: string;
    evidence: Evidence;
    threshold: Threshold | null;
    met: boolean;
    level: string;
    reasons: string[];
};
type Grant = {
    uuid: string;
    name: string;
    business_id: number | null;
    tool_key: string | null;
    agent_key: string | null;
    level: string | null;
    status: string;
    via_ladder: boolean;
    granted_at: string | null;
    revoked_at: string | null;
    revoke_reason: string | null;
    lapsed_at: string | null;
    lapse_code: string | null;
    lapse_reason: string | null;
};
type ThresholdRow = Threshold & { uuid: string; business_id: number; tool_key: string; status: string; reason: string };
type Props = {
    rows: Row[];
    grants: Grant[];
    thresholds: ThresholdRow[];
    grantsPagination: ListMeta;
    thresholdsPagination: ListMeta;
    paused: boolean;
    options: { levels: string[]; businesses: { id: number; name: string }[]; tools: string[] };
    can: { grant: boolean; revoke: boolean };
};

export default function AutonomyIndex({ rows, grants, thresholds, grantsPagination, thresholdsPagination, paused, options, can }: Props) {
    const t = useT();
    const { flash, errors } = usePage<{ flash?: { status?: string }; errors: Record<string, string> }>().props;
    const [showAll, setShowAll] = useState(false);
    const [setting, setSetting] = useState(false);
    const [granting, setGranting] = useState<Row | null>(null);
    const breadcrumbs: BreadcrumbItem[] = [{ title: 'Autonomy ladder', href: '/autonomy' }];

    const thresholdForm = useForm({
        business_id: options.businesses[0] ? String(options.businesses[0].id) : '',
        tool_key: options.tools[0] ?? '',
        max_level: 'a1',
        min_approved_succeeded: '',
        max_error_permille: '',
        max_rejection_permille: '',
        window_days: '90',
        reason: '',
    });
    const grantForm = useForm({ business_id: '', tool_key: '', level: 'a1', reason: '' });

    const shown = showAll ? rows : rows.filter((r) => r.evidence.proposed > 0 || r.threshold !== null);
    const rate = (v: number | null) => (v === null ? t('unknown') : t('{n} per 1,000', { n: String(v) }));
    const businessName = (id: number | null) => options.businesses.find((b) => b.id === id)?.name ?? (id === null ? t('Every business') : `#${id}`);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Autonomy ladder')} />
            <div className="flex max-w-7xl flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={t('Autonomy ladder')}
                    description={t(
                        'Evidence per tool per business: how many proposals people approved, how they ran, how often people said no. Autonomy is earned by evidence against numbers the owner sets, granted only by a person, and revoked at once.',
                    )}
                    actions={
                        can.grant ? (
                            <Button size="sm" variant="outline" onClick={() => setSetting(true)}>
                                {t('Set a threshold')}
                            </Button>
                        ) : undefined
                    }
                />
                {paused && (
                    <Notice tone="danger" title={t('Pause All AI is on')}>
                        {t('Every grant below is overridden: no agent call runs, and no autonomy can be granted, until a person turns AI back on.')}
                    </Notice>
                )}
                {flash?.status && <Notice tone="ok">{t('Recorded.')}</Notice>}
                {errors?.form && (
                    <Notice tone="danger" alert>
                        {errors.form}
                    </Notice>
                )}
                <Notice tone="info">{t('No threshold ships with HQ. Where none is set, autonomy is never granted: that is the default.')}</Notice>

                <section className="flex flex-col gap-3">
                    <SectionHeader
                        title={t('Evidence')}
                        aside={
                            <Button size="sm" variant="ghost" onClick={() => setShowAll((v) => !v)}>
                                {showAll ? t('Only tools with evidence') : t('Every tool')}
                            </Button>
                        }
                    />
                    {shown.length === 0 ? (
                        <EmptyState
                            title={t('No evidence yet')}
                            description={t('Evidence appears when an agent proposes an action and a person decides it. No agent has.')}
                        />
                    ) : (
                        <div className="hq-table-box">
                            <table className="w-full text-sm">
                                <thead className="text-left text-xs">
                                    <tr>
                                        <th className="px-3 py-2 font-medium">{t('Tool')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Proposed')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Approved')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Ran well')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Error rate')}</th>
                                        <th className="px-3 py-2 font-medium">{t('People said no')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Threshold')}</th>
                                        <th className="px-3 py-2" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {shown.map((r) => (
                                        <tr key={`${r.business_id}-${r.tool_key}`} className="border-t align-top">
                                            <td className="px-3 py-2 text-xs">
                                                <div className="font-mono">{r.tool_key}</div>
                                                <div className="text-muted-foreground">{r.business}</div>
                                            </td>
                                            <td className="px-3 py-2 text-xs tabular-nums">{formatNumber(r.evidence.proposed)}</td>
                                            <td className="px-3 py-2 text-xs tabular-nums">{formatNumber(r.evidence.approved)}</td>
                                            <td className="px-3 py-2 text-xs tabular-nums">
                                                {formatNumber(r.evidence.succeeded)} / {formatNumber(r.evidence.executed)}
                                            </td>
                                            <td className="px-3 py-2 text-xs">{rate(r.evidence.error_permille)}</td>
                                            <td className="px-3 py-2 text-xs">{rate(r.evidence.rejection_permille)}</td>
                                            <td className="max-w-xs px-3 py-2 text-xs">
                                                {r.threshold ? (
                                                    <>
                                                        <StatusBadge tone={r.met ? 'ok' : 'neutral'} dot={false}>
                                                            {r.met
                                                                ? t('Met, up to {level}', { level: r.threshold.max_level.toUpperCase() })
                                                                : t('Not met')}
                                                        </StatusBadge>
                                                        {r.reasons.map((x, i) => (
                                                            <div key={i} className="text-muted-foreground mt-1">
                                                                {x}
                                                            </div>
                                                        ))}
                                                    </>
                                                ) : (
                                                    <span className="text-muted-foreground">{t('None set: never granted')}</span>
                                                )}
                                            </td>
                                            <td className="px-3 py-2 text-right">
                                                {can.grant && r.met && !paused && (
                                                    <Button
                                                        size="sm"
                                                        onClick={() => {
                                                            grantForm.setData({
                                                                business_id: String(r.business_id),
                                                                tool_key: r.tool_key,
                                                                level: 'a1',
                                                                reason: '',
                                                            });
                                                            setGranting(r);
                                                        }}
                                                    >
                                                        {t('Grant')}
                                                    </Button>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>

                <section className="flex flex-col gap-3">
                    <SectionHeader title={t('Grants')} />
                    {grants.length === 0 ? (
                        <EmptyState title={t('No grants')} description={t('Every agent works at A0: it proposes and a person decides.')} />
                    ) : (
                        <div className="hq-table-box">
                            <table className="w-full text-sm">
                                <thead className="text-left text-xs">
                                    <tr>
                                        <th className="px-3 py-2 font-medium">{t('Grant')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Level')}</th>
                                        <th className="px-3 py-2 font-medium">{t('State')}</th>
                                        <th className="px-3 py-2" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {grants.map((g) => (
                                        <tr key={g.uuid} className="border-t align-top">
                                            <td className="px-3 py-2 text-xs">
                                                <div>{g.name}</div>
                                                <div className="text-muted-foreground">
                                                    {businessName(g.business_id)} {g.via_ladder ? t('(through the ladder)') : ''}{' '}
                                                    {formatDateTime(g.granted_at)}
                                                </div>
                                            </td>
                                            <td className="px-3 py-2 text-xs">{g.level?.toUpperCase()}</td>
                                            <td className="px-3 py-2 text-xs">
                                                <StatusBadge
                                                    status={g.status}
                                                    tone={g.status === 'active' ? (paused ? 'neutral' : 'warn') : 'neutral'}
                                                />
                                                {g.revoke_reason && <div className="text-muted-foreground mt-1">{g.revoke_reason}</div>}
                                                {g.lapse_reason && (
                                                    <div className="text-tone-warn mt-1">
                                                        {t('Lapsed {when}: {reason}', { when: formatDateTime(g.lapsed_at), reason: g.lapse_reason })}
                                                    </div>
                                                )}
                                                {g.status === 'lapsed' && (
                                                    <div className="text-muted-foreground mt-1">
                                                        {t('A lapsed grant never comes back by itself. A person grants anew after checking again.')}
                                                    </div>
                                                )}
                                            </td>
                                            <td className="px-3 py-2 text-right">
                                                {can.revoke && g.status === 'active' && (
                                                    <ReasonAction
                                                        url={`/autonomy/grants/${g.uuid}/revoke`}
                                                        label={t('Revoke')}
                                                        title={t('Revoke this grant')}
                                                        description={t('Takes effect at the next action: it goes back to a person.')}
                                                        variant="destructive"
                                                    />
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                    <Pagination meta={grantsPagination} />
                </section>

                <section className="flex flex-col gap-3">
                    <SectionHeader title={t('Thresholds')} />
                    {thresholds.length === 0 ? (
                        <EmptyState title={t('No thresholds')} description={t('The owner has set no numbers, so nothing is grantable.')} />
                    ) : (
                        <div className="hq-table-box">
                            <table className="w-full text-sm">
                                <thead className="text-left text-xs">
                                    <tr>
                                        <th className="px-3 py-2 font-medium">{t('Tool')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Numbers')}</th>
                                        <th className="px-3 py-2 font-medium">{t('State')}</th>
                                        <th className="px-3 py-2" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {thresholds.map((th) => (
                                        <tr key={th.uuid} className="border-t align-top">
                                            <td className="px-3 py-2 text-xs">
                                                <div className="font-mono">{th.tool_key}</div>
                                                <div className="text-muted-foreground">{businessName(th.business_id)}</div>
                                            </td>
                                            <td className="px-3 py-2 text-xs">
                                                {t(
                                                    'Up to {level}: at least {min} ran well, errors at most {err} and refusals at most {rej} per 1,000, over {days} days',
                                                    {
                                                        level: th.max_level.toUpperCase(),
                                                        min: String(th.min_approved_succeeded),
                                                        err: String(th.max_error_permille),
                                                        rej: String(th.max_rejection_permille),
                                                        days: String(th.window_days),
                                                    },
                                                )}
                                                <div className="text-muted-foreground">{th.reason}</div>
                                            </td>
                                            <td className="px-3 py-2 text-xs">
                                                <StatusBadge status={th.status} />
                                            </td>
                                            <td className="px-3 py-2 text-right">
                                                {can.grant && th.status === 'active' && (
                                                    <ReasonAction
                                                        url={`/autonomy/thresholds/${th.uuid}/retire`}
                                                        label={t('Retire')}
                                                        title={t('Retire this threshold')}
                                                        description={t('Every grant that rested on it lapses at the same moment, for good.')}
                                                    />
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                    <Pagination meta={thresholdsPagination} />
                </section>

                <Dialog open={setting} onOpenChange={setSetting}>
                    <DialogContent className="sm:max-w-2xl">
                        <DialogHeader>
                            <DialogTitle>{t('Set a threshold')}</DialogTitle>
                            <DialogDescription>
                                {t('Your numbers. A threshold is never edited: setting a new one retires the old one and its grants lapse for good.')}
                            </DialogDescription>
                        </DialogHeader>
                        <form
                            className="flex flex-col gap-3"
                            onSubmit={(ev) => {
                                ev.preventDefault();
                                thresholdForm.post('/autonomy/thresholds', { preserveScroll: true, onSuccess: () => setSetting(false) });
                            }}
                        >
                            <FormRow>
                                <Field label={t('Business')} htmlFor="th-business" error={thresholdForm.errors.business_id}>
                                    <NativeSelect
                                        id="th-business"
                                        value={thresholdForm.data.business_id}
                                        onChange={(ev) => thresholdForm.setData('business_id', ev.target.value)}
                                    >
                                        {options.businesses.map((b) => (
                                            <option key={b.id} value={b.id}>
                                                {b.name}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </Field>
                                <Field label={t('Tool')} htmlFor="th-tool" error={thresholdForm.errors.tool_key}>
                                    <NativeSelect
                                        id="th-tool"
                                        value={thresholdForm.data.tool_key}
                                        onChange={(ev) => thresholdForm.setData('tool_key', ev.target.value)}
                                    >
                                        {options.tools.map((k) => (
                                            <option key={k} value={k}>
                                                {k}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </Field>
                                <Field label={t('Up to level')} htmlFor="th-level" error={thresholdForm.errors.max_level}>
                                    <NativeSelect
                                        id="th-level"
                                        value={thresholdForm.data.max_level}
                                        onChange={(ev) => thresholdForm.setData('max_level', ev.target.value)}
                                    >
                                        {options.levels.map((l) => (
                                            <option key={l} value={l}>
                                                {l.toUpperCase()}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </Field>
                            </FormRow>
                            <FormRow>
                                <Field label={t('Ran well, at least')} htmlFor="th-min" size="sm" error={thresholdForm.errors.min_approved_succeeded}>
                                    <Input
                                        id="th-min"
                                        type="number"
                                        min={1}
                                        value={thresholdForm.data.min_approved_succeeded}
                                        onChange={(ev) => thresholdForm.setData('min_approved_succeeded', ev.target.value)}
                                        required
                                    />
                                </Field>
                                <Field
                                    label={t('Errors per 1,000, at most')}
                                    htmlFor="th-err"
                                    size="sm"
                                    error={thresholdForm.errors.max_error_permille}
                                >
                                    <Input
                                        id="th-err"
                                        type="number"
                                        min={0}
                                        max={1000}
                                        value={thresholdForm.data.max_error_permille}
                                        onChange={(ev) => thresholdForm.setData('max_error_permille', ev.target.value)}
                                        required
                                    />
                                </Field>
                                <Field
                                    label={t('Refusals per 1,000, at most')}
                                    htmlFor="th-rej"
                                    size="sm"
                                    error={thresholdForm.errors.max_rejection_permille}
                                >
                                    <Input
                                        id="th-rej"
                                        type="number"
                                        min={0}
                                        max={1000}
                                        value={thresholdForm.data.max_rejection_permille}
                                        onChange={(ev) => thresholdForm.setData('max_rejection_permille', ev.target.value)}
                                        required
                                    />
                                </Field>
                                <Field label={t('Over days')} htmlFor="th-days" size="xs" error={thresholdForm.errors.window_days}>
                                    <Input
                                        id="th-days"
                                        type="number"
                                        min={7}
                                        max={365}
                                        value={thresholdForm.data.window_days}
                                        onChange={(ev) => thresholdForm.setData('window_days', ev.target.value)}
                                        required
                                    />
                                </Field>
                            </FormRow>
                            <Field label={t('Reason')} htmlFor="th-reason" error={thresholdForm.errors.reason}>
                                <Input
                                    id="th-reason"
                                    value={thresholdForm.data.reason}
                                    onChange={(ev) => thresholdForm.setData('reason', ev.target.value)}
                                    required
                                />
                            </Field>
                            <div>
                                <Button type="submit" disabled={thresholdForm.processing}>
                                    {t('Set a threshold')}
                                </Button>
                            </div>
                        </form>
                    </DialogContent>
                </Dialog>

                <Dialog open={granting !== null} onOpenChange={(o) => !o && setGranting(null)}>
                    <DialogContent className="sm:max-w-lg">
                        <DialogHeader>
                            <DialogTitle>{t('Grant autonomy')}</DialogTitle>
                            <DialogDescription>
                                {t(
                                    'Every call still goes through the gateway, its policy, consent and audit. Revoking is one click and takes effect at the next action.',
                                )}
                            </DialogDescription>
                        </DialogHeader>
                        <form
                            className="flex flex-col gap-3"
                            onSubmit={(ev) => {
                                ev.preventDefault();
                                grantForm.post('/autonomy/grants', { preserveScroll: true, onSuccess: () => setGranting(null) });
                            }}
                        >
                            <div className="text-sm">
                                <span className="font-mono">{granting?.tool_key}</span> / {granting?.business}
                            </div>
                            <Field label={t('Level')} htmlFor="gr-level" error={grantForm.errors.level}>
                                <NativeSelect
                                    id="gr-level"
                                    value={grantForm.data.level}
                                    onChange={(ev) => grantForm.setData('level', ev.target.value)}
                                >
                                    {options.levels
                                        .filter((l) => !granting?.threshold || l <= granting.threshold.max_level)
                                        .map((l) => (
                                            <option key={l} value={l}>
                                                {l.toUpperCase()}
                                            </option>
                                        ))}
                                </NativeSelect>
                            </Field>
                            <Field label={t('Reason')} htmlFor="gr-reason" error={grantForm.errors.reason}>
                                <Input
                                    id="gr-reason"
                                    value={grantForm.data.reason}
                                    onChange={(ev) => grantForm.setData('reason', ev.target.value)}
                                    required
                                />
                            </Field>
                            <div>
                                <Button type="submit" disabled={grantForm.processing}>
                                    {t('Grant autonomy')}
                                </Button>
                            </div>
                        </form>
                    </DialogContent>
                </Dialog>
            </div>
        </AppLayout>
    );
}
