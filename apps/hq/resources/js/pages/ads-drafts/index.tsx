import { EmptyState } from '@/components/empty-state';
import { Field, FormRow, NativeSelect } from '@/components/form/field';
import { PageHeader, SectionHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Notice } from '@/components/ui/notice';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { postForm } from '@/lib/post-form';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';

type Draft = {
    uuid: string;
    name: string;
    status: string;
    error: string | null;
    ad_account_ref: string;
    daily_budget: string | null;
    currency: string | null;
    platform_refs: Record<string, string> | null;
    created_at: string | null;
};
type Account = { ref: string; business_id: number | null; business_slug: string | null };
type Props = {
    drafts: Draft[];
    businesses: { slug: string; name: string }[];
    accounts: Account[];
    objectives: string[];
    cap_usd: string;
    can: { draft: boolean };
};
type Checks = {
    ok: boolean;
    budget: { ok: boolean; reason: string | null };
    language: { ok: boolean; failures: { text: string; why: string }[] };
    claims: { ok: boolean; hits: { claim: string; term: string; reason: string; overridable: boolean; proof: string | null }[] };
    error?: string;
};

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Paused ad drafts', href: '/ads-drafts' }];

/**
 * Paused ad drafts (docs/144-meta-ads-drafts). The checks run as you type (they read, they create nothing): the daily
 * budget cap, Bangla-only copy, and health claims (CRPA 2009 section 44). The request waits for approval and creates
 * PAUSED objects only. There is no switch-on button here; that is done in Ads Manager, by a person.
 */
export default function AdsDrafts({ drafts, businesses, accounts, objectives, cap_usd, can }: Props) {
    const t = useT();
    const first = businesses[0]?.slug ?? '';
    const form = useForm({
        business_slug: first,
        ad_account_ref: accounts.find((a) => a.business_slug === first)?.ref ?? '',
        name: '',
        objective: objectives[0] ?? 'OUTCOME_TRAFFIC',
        daily_budget: '5.00',
        currency: 'USD',
        page_id: '',
        link_url: 'https://',
        primary_text: '',
        headline: '',
        description: '',
    });
    const [checks, setChecks] = useState<Checks | null>(null);
    const mine = accounts.filter((a) => a.business_slug === form.data.business_slug);

    const check = async () => {
        const payload = Object.fromEntries(Object.entries(form.data).filter(([, v]) => v !== ''));
        const r = await postForm<Checks>('/ads-drafts/check', payload);
        setChecks(
            r.ok
                ? r.data
                : {
                      ok: false,
                      budget: { ok: true, reason: null },
                      language: { ok: true, failures: [] },
                      claims: { ok: true, hits: [] },
                      error: r.error,
                  },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Paused ad drafts')} />
            <div className="flex max-w-5xl flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={t('Paused ad drafts')}
                    description={t('Prepare an ad campaign that stays paused. A person switches it on in Ads Manager; HQ never does.')}
                />

                {can.draft && (
                    <form
                        className="hq-card flex flex-col gap-3 p-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post('/ads-drafts', { preserveScroll: true });
                        }}
                    >
                        <SectionHeader title={t('New draft')} aside={t('Daily budget cap: USD {cap}', { cap: cap_usd })} />
                        <FormRow>
                            <Field label={t('Business')} htmlFor="a-business" size="lg" error={form.errors.business_slug}>
                                <NativeSelect
                                    id="a-business"
                                    value={form.data.business_slug}
                                    onChange={(e) => {
                                        form.setData('business_slug', e.target.value);
                                        form.setData('ad_account_ref', accounts.find((a) => a.business_slug === e.target.value)?.ref ?? '');
                                    }}
                                >
                                    {businesses.map((b) => (
                                        <option key={b.slug} value={b.slug}>
                                            {b.name}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </Field>
                            <Field
                                label={t('Ad account')}
                                htmlFor="a-account"
                                size="lg"
                                error={form.errors.ad_account_ref}
                                hint={mine.length === 0 ? t('This business has no ad account linked.') : undefined}
                            >
                                <NativeSelect
                                    id="a-account"
                                    value={form.data.ad_account_ref}
                                    onChange={(e) => form.setData('ad_account_ref', e.target.value)}
                                >
                                    {mine.map((a) => (
                                        <option key={a.ref} value={a.ref}>
                                            {a.ref}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </Field>
                            <Field label={t('Goal')} htmlFor="a-objective" size="lg">
                                <NativeSelect
                                    id="a-objective"
                                    value={form.data.objective}
                                    onChange={(e) => form.setData('objective', e.target.value)}
                                >
                                    {objectives.map((o) => (
                                        <option key={o} value={o}>
                                            {o}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </Field>
                        </FormRow>
                        <FormRow>
                            <Field label={t('Name')} htmlFor="a-name" size="grow" error={form.errors.name}>
                                <Input id="a-name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                            </Field>
                            <Field label={t('Daily budget')} htmlFor="a-budget" size="sm" error={form.errors.daily_budget}>
                                <Input
                                    id="a-budget"
                                    inputMode="decimal"
                                    value={form.data.daily_budget}
                                    onChange={(e) => form.setData('daily_budget', e.target.value)}
                                />
                            </Field>
                            <Field label={t('Currency')} htmlFor="a-currency" size="xs">
                                <NativeSelect id="a-currency" value={form.data.currency} onChange={(e) => form.setData('currency', e.target.value)}>
                                    <option>USD</option>
                                    <option>BDT</option>
                                </NativeSelect>
                            </Field>
                        </FormRow>
                        <FormRow>
                            <Field label={t('Facebook Page id')} htmlFor="a-page" size="md" error={form.errors.page_id}>
                                <Input
                                    id="a-page"
                                    inputMode="numeric"
                                    value={form.data.page_id}
                                    onChange={(e) => form.setData('page_id', e.target.value)}
                                />
                            </Field>
                            <Field label={t('Link (https)')} htmlFor="a-link" size="grow" error={form.errors.link_url}>
                                <Input id="a-link" value={form.data.link_url} onChange={(e) => form.setData('link_url', e.target.value)} />
                            </Field>
                        </FormRow>
                        <Field label={t('Headline (Bangla)')} htmlFor="a-headline" error={form.errors.headline}>
                            <Input id="a-headline" value={form.data.headline} onChange={(e) => form.setData('headline', e.target.value)} />
                        </Field>
                        <Field label={t('Main text (Bangla)')} htmlFor="a-text" error={form.errors.primary_text}>
                            <Textarea
                                id="a-text"
                                rows={4}
                                value={form.data.primary_text}
                                onChange={(e) => form.setData('primary_text', e.target.value)}
                            />
                        </Field>
                        <div className="flex flex-wrap gap-2">
                            <Button type="button" variant="outline" onClick={check}>
                                {t('Run the checks')}
                            </Button>
                            <Button type="submit" disabled={form.processing || mine.length === 0}>
                                {t('Ask for a paused draft')}
                            </Button>
                        </div>
                        {checks && (
                            <div className="flex flex-col gap-2" role="status">
                                {checks.error && <Notice tone="danger">{checks.error}</Notice>}
                                <Notice tone={checks.budget.ok ? 'ok' : 'danger'}>
                                    {checks.budget.ok ? t('Budget is within the cap.') : (checks.budget.reason ?? t('Budget refused.'))}
                                </Notice>
                                <Notice tone={checks.language.ok ? 'ok' : 'danger'}>
                                    {checks.language.ok
                                        ? t('Copy is Bangla only.')
                                        : checks.language.failures.map((f) => `${f.why} "${f.text}"`).join('; ')}
                                </Notice>
                                <Notice tone={checks.claims.ok ? 'ok' : 'danger'}>
                                    {checks.claims.hits.length === 0
                                        ? t('No health or quality claim recognised.')
                                        : checks.claims.hits
                                              .map(
                                                  (h) =>
                                                      `${h.term}: ${h.reason}${h.proof ? ' (evidence recorded)' : h.overridable ? ' (needs evidence)' : ' (cannot be used)'}`,
                                              )
                                              .join('; ')}
                                </Notice>
                                <p className="text-muted-foreground text-xs">
                                    {t('The checks recognise words, not meaning. Read the copy yourself before you switch the draft on.')}
                                </p>
                            </div>
                        )}
                    </form>
                )}

                {drafts.length === 0 ? (
                    <EmptyState
                        title={t('No drafts yet')}
                        description={t('A draft appears here once the request is approved and the platform has made it.')}
                    />
                ) : (
                    <ul className="flex flex-col gap-2">
                        {drafts.map((d) => (
                            <li key={d.uuid} className="hq-card flex flex-wrap items-center gap-x-4 gap-y-1 p-3 text-sm">
                                <StatusBadge tone={d.status === 'created_paused' ? 'ok' : 'danger'}>
                                    {d.status === 'created_paused' ? t('Paused draft made') : t('Failed')}
                                </StatusBadge>
                                <span className="font-medium">{d.name}</span>
                                <span>
                                    {d.currency} {d.daily_budget} / {t('day')}
                                </span>
                                <span className="text-muted-foreground font-mono text-xs">{d.platform_refs?.campaign}</span>
                                {d.error && <span className="text-tone-danger text-xs">{d.error}</span>}
                                <span className="text-muted-foreground ml-auto text-xs">{formatDateTime(d.created_at)}</span>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </AppLayout>
    );
}
