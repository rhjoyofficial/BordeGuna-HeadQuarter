import { EmptyState } from '@/components/empty-state';
import { Field, FormRow } from '@/components/form/field';
import { PageHeader, SectionHeader } from '@/components/page-header';
import { type ListMeta, Pagination } from '@/components/pagination';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Notice } from '@/components/ui/notice';
import { StatusBadge, type Tone } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatDateTime } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { Landmark } from 'lucide-react';

/** One rate the owner set. Rows are never edited; a change is a new row (ruling 1 Nov 2026). */
type Rate = {
    id: number;
    pair: string;
    reference_rate: string;
    reserve_rate: string;
    safety_margin_percent: string;
    source: string;
    as_of: string | null;
    valid_until: string | null;
    reason: string;
    set_by: string | null;
    created_at: string | null;
};
type Paginated<T> = ListMeta & { data: T[] };
type GroupSwitch = { allowed: boolean; refusal: string | null };

const STATE_TONE: Record<string, Tone> = { valid: 'ok', expired: 'danger', missing: 'danger', not_yet: 'warn', invalid: 'danger' };

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Exchange rate', href: '/fx-rate' }];

/** "136.500000" as "136.50": the stored figure keeps six places, a person reads two (four for a margin). */
function figure(value: string, places = 2): string {
    const n = Number(value);
    return Number.isFinite(n) ? n.toFixed(places) : value;
}

/** Today in Dhaka as YYYY-MM-DD, the default as-of date. */
function dhakaToday(): string {
    return new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Dhaka' });
}

function NewRateForm({ current, maxDays }: { current: Rate | null; maxDays: number }) {
    const t = useT();
    const form = useForm({
        reference_rate: current ? figure(current.reference_rate) : '',
        reserve_rate: current ? figure(current.reserve_rate) : '',
        source: '',
        as_of: dhakaToday(),
        valid_until: '',
        reason: '',
    });
    const reference = Number(form.data.reference_rate);
    const reserve = Number(form.data.reserve_rate);
    const margin = reference > 0 && reserve > 0 ? ((reserve / reference - 1) * 100).toFixed(2) : null;
    const errors = form.errors as Record<string, string | undefined>;

    return (
        <form
            className="flex flex-col gap-4"
            onSubmit={(ev) => {
                ev.preventDefault();
                form.post('/fx-rate', { preserveScroll: true, onSuccess: () => form.reset('reason', 'source', 'valid_until') });
            }}
        >
            {errors.fx_rate && (
                <Notice tone="danger" title={t('Not saved')}>
                    {errors.fx_rate}
                </Notice>
            )}
            <FormRow>
                <Field
                    label={t('Reference rate (BDT per USD)')}
                    htmlFor="fx-reference"
                    size="sm"
                    hint={t('The published rate. AI costs are shown with it.')}
                    error={form.errors.reference_rate}
                >
                    <Input
                        id="fx-reference"
                        inputMode="decimal"
                        value={form.data.reference_rate}
                        onChange={(ev) => form.setData('reference_rate', ev.target.value)}
                        required
                    />
                </Field>
                <Field
                    label={t('Reserve rate (BDT per USD)')}
                    htmlFor="fx-reserve"
                    size="sm"
                    hint={margin === null ? t('The safe rate media budgets reserve at.') : t('Safety margin {margin}%.', { margin })}
                    error={form.errors.reserve_rate}
                >
                    <Input
                        id="fx-reserve"
                        inputMode="decimal"
                        value={form.data.reserve_rate}
                        onChange={(ev) => form.setData('reserve_rate', ev.target.value)}
                        required
                    />
                </Field>
                <Field label={t('As of')} htmlFor="fx-as-of" size="auto" error={form.errors.as_of}>
                    <Input id="fx-as-of" type="date" value={form.data.as_of} onChange={(ev) => form.setData('as_of', ev.target.value)} required />
                </Field>
                <Field
                    label={t('Valid until')}
                    htmlFor="fx-valid-until"
                    size="auto"
                    hint={t('At most {days} days after the as-of date.', { days: String(maxDays) })}
                    error={form.errors.valid_until}
                >
                    <Input
                        id="fx-valid-until"
                        type="date"
                        value={form.data.valid_until}
                        onChange={(ev) => form.setData('valid_until', ev.target.value)}
                        required
                    />
                </Field>
            </FormRow>
            <FormRow>
                <Field label={t('Source')} htmlFor="fx-source" size="grow" error={form.errors.source}>
                    <Input
                        id="fx-source"
                        placeholder={t('Bangladesh Bank reference, date')}
                        value={form.data.source}
                        onChange={(ev) => form.setData('source', ev.target.value)}
                        maxLength={255}
                        required
                    />
                </Field>
                <Field
                    label={t('Why')}
                    htmlFor="fx-reason"
                    size="grow"
                    hint={t('The rate acts on every business. The reason is kept in the audit log.')}
                    error={form.errors.reason}
                >
                    <Input id="fx-reason" value={form.data.reason} onChange={(ev) => form.setData('reason', ev.target.value)} maxLength={500} required />
                </Field>
                <div className="self-end">
                    <Button type="submit" disabled={form.processing}>
                        {t('Set the new rate')}
                    </Button>
                </div>
            </FormRow>
        </form>
    );
}

export default function FxRateIndex({
    current,
    refusal,
    history,
    max_validity_days: maxDays,
    switch: groupSwitch,
}: {
    current: (Rate & { state: string }) | null;
    refusal: string | null;
    history: Paginated<Rate>;
    max_validity_days: number;
    switch: GroupSwitch;
}) {
    const t = useT();
    const { auth } = usePage<{ auth: { permissions: string[] } }>().props;
    const holdsPermission = auth.permissions.includes('hq.fx.manage');
    // The permission alone is not enough: the server also says the person is group-wide.
    const canManage = holdsPermission && groupSwitch.allowed;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Exchange rate')} />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={t('Exchange rate')}
                    description={t(
                        'The USD to BDT rate HQ uses. Paid media budgets reserve at the reserve rate and refuse when it is missing or expired. AI costs are shown in BDT at the reference rate, as an estimate.',
                    )}
                />

                {refusal && (
                    <Notice tone="danger" title={t('Paid media is refused')}>
                        {refusal}. {t('Set a new rate to allow paid media again. AI costs show in USD meanwhile.')}
                    </Notice>
                )}

                {current === null ? (
                    <EmptyState icon={Landmark} title={t('No rate set')} description={t('No USD to BDT rate exists, so paid media is refused.')} />
                ) : (
                    <div className="hq-table-box p-4">
                        <div className="flex flex-wrap items-center gap-3">
                            <span className="text-lg font-semibold tabular-nums">
                                {t('BDT {rate} per USD', { rate: figure(current.reference_rate) })}
                            </span>
                            <StatusBadge status={current.state} tone={STATE_TONE[current.state] ?? 'neutral'} />
                        </div>
                        <dl className="mt-3 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                            <div>
                                <dt className="text-muted-foreground text-xs">{t('Reserve rate for media budgets')}</dt>
                                <dd className="tabular-nums">
                                    {t('BDT {rate} per USD', { rate: figure(current.reserve_rate) })} (
                                    {t('margin {margin}%', { margin: figure(current.safety_margin_percent, 4) })})
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground text-xs">{t('Source')}</dt>
                                <dd>{current.source}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground text-xs">{t('As of')}</dt>
                                <dd className="tabular-nums">{formatDate(current.as_of)}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground text-xs">{t('Valid until')}</dt>
                                <dd className="tabular-nums">{formatDateTime(current.valid_until)}</dd>
                            </div>
                        </dl>
                    </div>
                )}

                {holdsPermission && !groupSwitch.allowed && (
                    <Notice tone="warn" title={t('Group-wide switch')}>
                        {t(
                            groupSwitch.refusal ??
                                'This switch acts on every business, and your access is limited to some businesses. Only a person with group-wide access may change it.',
                        )}
                    </Notice>
                )}

                {canManage && (
                    <section className="flex flex-col gap-3">
                        <SectionHeader title={t('Set a new rate')} />
                        <NewRateForm current={current} maxDays={maxDays} />
                    </section>
                )}

                <section className="flex flex-col gap-3">
                    <SectionHeader title={t('History')} aside={t('Every rate ever set, newest first. A rate is never edited.')} />
                    {history.data.length === 0 ? (
                        <EmptyState icon={Landmark} title={t('No history')} description={t('No rate has been set yet.')} />
                    ) : (
                        <div className="hq-table-box">
                            <table className="w-full text-sm">
                                <thead className="text-left text-xs">
                                    <tr>
                                        <th className="px-3 py-2 font-medium">{t('Set')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Reference')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Reserve')}</th>
                                        <th className="px-3 py-2 font-medium">{t('As of')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Valid until')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Source and reason')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {history.data.map((r) => (
                                        <tr key={r.id} className="border-t align-top">
                                            <td className="px-3 py-2 text-xs whitespace-nowrap">
                                                <div className="tabular-nums">{formatDateTime(r.created_at)}</div>
                                                <div className="text-muted-foreground">{r.set_by ?? t('the initial ruling')}</div>
                                            </td>
                                            <td className="px-3 py-2 tabular-nums">{figure(r.reference_rate)}</td>
                                            <td className="px-3 py-2 tabular-nums">
                                                {figure(r.reserve_rate)}
                                                <div className="text-muted-foreground text-xs">+{figure(r.safety_margin_percent, 4)}%</div>
                                            </td>
                                            <td className="px-3 py-2 whitespace-nowrap tabular-nums">{formatDate(r.as_of)}</td>
                                            <td className="px-3 py-2 whitespace-nowrap tabular-nums">{formatDateTime(r.valid_until)}</td>
                                            <td className="px-3 py-2">
                                                <div>{r.source}</div>
                                                <div className="text-muted-foreground text-xs">{r.reason}</div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                    <Pagination meta={history} />
                </section>
            </div>
        </AppLayout>
    );
}
