import { EmptyState } from '@/components/empty-state';
import { Field, FormRow } from '@/components/form/field';
import { SectionHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Notice } from '@/components/ui/notice';
import { StatusBadge, type Tone } from '@/components/ui/status-badge';
import { formatDateTime } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

export type MediaBudgetBusiness = { slug: string; name: string; limit_bdt: string; used_bdt: string };
export type MediaBudgetEntry = {
    uuid: string;
    business: string | null;
    period: string;
    kind: string;
    provider: string;
    tool_key: string;
    estimate: string;
    estimate_bdt: string;
    actual: string | null;
    actual_bdt: string | null;
    state: string;
    created_at: string | null;
};
export type MediaBudgetData = {
    period: string;
    timezone: string;
    businesses: MediaBudgetBusiness[];
    entries: MediaBudgetEntry[];
    can_manage: boolean;
};

const STATE_TONE: Record<string, Tone> = { reserved: 'pending', committed: 'warn', released: 'neutral', reconciled: 'ok' };

function LimitForm({ business }: { business: MediaBudgetBusiness }) {
    const t = useT();
    const form = useForm({ monthly_limit_bdt: business.limit_bdt, reason: '' });

    return (
        <form
            className="mt-2"
            onSubmit={(ev) => {
                ev.preventDefault();
                form.post(`/connectors/media-budget/${business.slug}`, { preserveScroll: true, onSuccess: () => form.reset('reason') });
            }}
        >
            <FormRow>
                <Field label={t('Monthly limit (BDT)')} htmlFor={`mb-limit-${business.slug}`} size="sm" error={form.errors.monthly_limit_bdt}>
                    <Input
                        id={`mb-limit-${business.slug}`}
                        inputMode="decimal"
                        value={form.data.monthly_limit_bdt}
                        onChange={(ev) => form.setData('monthly_limit_bdt', ev.target.value)}
                        required
                    />
                </Field>
                <Field label={t('Why')} htmlFor={`mb-reason-${business.slug}`} size="grow" error={form.errors.reason}>
                    <Input
                        id={`mb-reason-${business.slug}`}
                        value={form.data.reason}
                        onChange={(ev) => form.setData('reason', ev.target.value)}
                        maxLength={500}
                        required
                    />
                </Field>
                <div className="self-end">
                    <Button type="submit" size="sm" disabled={form.processing}>
                        {t('Save the limit')}
                    </Button>
                </div>
            </FormRow>
        </form>
    );
}

function ReconcileForm({ entry, onDone }: { entry: MediaBudgetEntry; onDone: () => void }) {
    const t = useT();
    const form = useForm({ actual_amount: '', actual_currency: entry.estimate.split(' ')[1] ?? 'USD' });

    return (
        <form
            onSubmit={(ev) => {
                ev.preventDefault();
                form.post(`/connectors/media-budget/entries/${entry.uuid}/reconcile`, { preserveScroll: true, onSuccess: onDone });
            }}
        >
            <FormRow>
                <Field label={t('Billed amount')} htmlFor={`mb-actual-${entry.uuid}`} size="sm" error={form.errors.actual_amount}>
                    <Input
                        id={`mb-actual-${entry.uuid}`}
                        inputMode="decimal"
                        value={form.data.actual_amount}
                        onChange={(ev) => form.setData('actual_amount', ev.target.value)}
                        required
                    />
                </Field>
                <Field label={t('Currency')} htmlFor={`mb-cur-${entry.uuid}`} size="xs" error={form.errors.actual_currency}>
                    <Input
                        id={`mb-cur-${entry.uuid}`}
                        value={form.data.actual_currency}
                        onChange={(ev) => form.setData('actual_currency', ev.target.value.toUpperCase())}
                        maxLength={3}
                        required
                    />
                </Field>
                <div className="self-end">
                    <Button type="submit" size="sm" variant="outline" disabled={form.processing}>
                        {t('Record the billed cost')}
                    </Button>
                </div>
            </FormRow>
        </form>
    );
}

/**
 * The per-business monthly media budget (ruling 31 Oct 2026 item 12, docs/147-r2). BDT 0 is the default and means no paid media
 * generation of any kind runs. Only the owner changes a limit or records a provider's billed cost; nothing an AI does can.
 */
export function MediaBudgetPanel({ data }: { data: MediaBudgetData }) {
    const t = useT();
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const [reconciling, setReconciling] = useState<string | null>(null);
    const stateLabel = (s: string) =>
        (
            ({
                reserved: t('Reserved'),
                committed: t('Spent (estimate)'),
                released: t('Released'),
                reconciled: t('Reconciled'),
            }) as Record<string, string>
        )[s] ?? s;

    return (
        <section className="flex flex-col gap-3" aria-labelledby="media-budget">
            <SectionHeader
                title={t('Media spending limit')}
                aside={t('This month: {period} ({timezone})', { period: data.period, timezone: data.timezone })}
            />
            <Notice tone="info">
                {t(
                    'Each business has its own monthly limit in BDT. BDT 0 means no paid video, image, voice or avatar generation runs. HQ reserves the estimated cost before it asks a provider, and refuses when the cost or the currency rate is unknown. Only the owner sets a limit; no AI can raise it.',
                )}
            </Notice>
            {errors?.media_budget && <Notice tone="danger">{errors.media_budget}</Notice>}
            {data.businesses.length === 0 ? (
                <EmptyState title={t('No business to show')} description={t('You may not use any business here.')} />
            ) : (
                <div className="grid gap-4 md:grid-cols-2">
                    {data.businesses.map((b) => {
                        const zero = Number(b.limit_bdt) === 0;
                        return (
                            <div key={b.slug} className="hq-card flex flex-col gap-1 p-4">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <h3 className="font-semibold">{b.name}</h3>
                                    <StatusBadge tone={zero ? 'neutral' : 'ok'}>{zero ? t('No paid media') : t('Limit set')}</StatusBadge>
                                </div>
                                <p className="text-sm">
                                    {t('Used BDT {used} of BDT {limit}', { used: b.used_bdt, limit: b.limit_bdt })}
                                </p>
                                {data.can_manage ? (
                                    <LimitForm business={b} />
                                ) : (
                                    <p className="text-muted-foreground text-xs">{t('Only the owner changes this limit.')}</p>
                                )}
                            </div>
                        );
                    })}
                </div>
            )}
            {data.entries.length > 0 && (
                <div className="hq-table-box">
                    <table className="w-full text-sm">
                        <thead className="text-left text-xs">
                            <tr>
                                <th className="px-3 py-2 font-medium">{t('When')}</th>
                                <th className="px-3 py-2 font-medium">{t('Business')}</th>
                                <th className="px-3 py-2 font-medium">{t('Kind')}</th>
                                <th className="px-3 py-2 font-medium">{t('Provider')}</th>
                                <th className="px-3 py-2 font-medium">{t('Estimate')}</th>
                                <th className="px-3 py-2 font-medium">{t('Billed')}</th>
                                <th className="px-3 py-2 font-medium">{t('State')}</th>
                                <th className="px-3 py-2 font-medium" />
                            </tr>
                        </thead>
                        <tbody>
                            {data.entries.map((e) => (
                                <tr key={e.uuid} className="border-t align-top">
                                    <td className="px-3 py-2">{e.created_at ? formatDateTime(e.created_at) : '-'}</td>
                                    <td className="px-3 py-2">{e.business ?? '-'}</td>
                                    <td className="px-3 py-2">{t(e.kind)}</td>
                                    <td className="px-3 py-2 font-mono text-xs">{e.provider}</td>
                                    <td className="px-3 py-2">
                                        BDT {e.estimate_bdt}
                                        <span className="text-muted-foreground block text-xs">{e.estimate}</span>
                                    </td>
                                    <td className="px-3 py-2">
                                        {e.actual_bdt === null ? (
                                            '-'
                                        ) : (
                                            <>
                                                BDT {e.actual_bdt}
                                                <span className="text-muted-foreground block text-xs">{e.actual}</span>
                                            </>
                                        )}
                                    </td>
                                    <td className="px-3 py-2">
                                        <StatusBadge tone={STATE_TONE[e.state] ?? 'neutral'}>{stateLabel(e.state)}</StatusBadge>
                                    </td>
                                    <td className="px-3 py-2 text-right">
                                        {data.can_manage && (e.state === 'committed' || e.state === 'reconciled') ? (
                                            reconciling === e.uuid ? (
                                                <ReconcileForm entry={e} onDone={() => setReconciling(null)} />
                                            ) : (
                                                <Button size="sm" variant="outline" onClick={() => setReconciling(e.uuid)}>
                                                    {t('Enter the billed cost')}
                                                </Button>
                                            )
                                        ) : null}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </section>
    );
}
