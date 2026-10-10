import { EmptyState } from '@/components/empty-state';
import { Field, FormRow, NativeSelect } from '@/components/form/field';
import { PageHeader, SectionHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { StatusBadge, type Tone } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/format';
import { formatNumber, useT } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';

/**
 * The playbook store (docs/112 §7, S6): what guidance is active per business, brand and kind, what is
 * waiting for review or activation, and a form for a new draft. A playbook is read-only guidance for the
 * agents; it grants nothing, and this screen says so where the owner writes it.
 */

export type VersionSummary = {
    uuid: string;
    brand_id: number | null;
    brand: string | null;
    kind: string;
    kind_label: string;
    version: number;
    status: string;
    title: string;
    content_digest: string;
    chars: number;
    drafted_by: string | null;
    drafted_at: string | null;
    activated_by: string | null;
    activated_at: string | null;
};
type Row = { brand_id: number | null; brand: string | null; code: string | null; kind: string; kind_label: string; active: VersionSummary | null; pending: VersionSummary | null };
type BusinessRow = { id: number; name: string; slug: string; brands: { id: number; code: string; name: string }[]; rows: Row[] };
export type Kind = { value: string; label: string; hint: string };
type Props = {
    businesses: BusinessRow[];
    kinds: Kind[];
    limits: { max_chars: number; max_chars_per_run: number };
    can: { draft: boolean; review: boolean; activate: boolean };
};

export const STATUS_TONES: Record<string, Tone> = { draft: 'neutral', in_review: 'pending', reviewed: 'info', active: 'ok', retired: 'neutral', withdrawn: 'warn' };

export function useStatusLabel() {
    const t = useT();
    return (s: string) =>
        ({
            draft: t('Draft'),
            in_review: t('In review'),
            reviewed: t('Reviewed'),
            active: t('Active'),
            retired: t('Retired'),
            withdrawn: t('Withdrawn'),
        })[s] ?? s;
}

export function useKindLabel(kinds: Kind[]) {
    const t = useT();
    return (kind: string) => t(kinds.find((k) => k.value === kind)?.label ?? kind);
}

export default function PlaybooksIndex({ businesses, kinds, limits, can }: Props) {
    const t = useT();
    const statusLabel = useStatusLabel();
    const kindLabel = useKindLabel(kinds);
    const breadcrumbs: BreadcrumbItem[] = [{ title: 'Playbooks', href: '/playbooks' }];

    const form = useForm({
        business_id: businesses[0] ? String(businesses[0].id) : '',
        brand_id: '',
        kind: kinds[0]?.value ?? 'general',
        title: '',
        summary: '',
        body: '',
        reason: '',
    });
    const chosen = businesses.find((b) => String(b.id) === form.data.business_id);
    const kind = kinds.find((k) => k.value === form.data.kind);
    const chars = form.data.body.length;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Playbooks')} />
            <div className="flex max-w-6xl flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={t('Playbooks')}
                    description={t(
                        'Written business guidance the agents read: pricing authority, escalation, product claims and the like. One active version per business, brand and kind. A playbook grants no permission, changes no policy or consent and does not change what an agent may do; those are decided by code that never reads it.',
                    )}
                />

                {businesses.length === 0 ? (
                    <EmptyState title={t('No business in your scope')} description={t('Playbooks are written per business. Ask for a business scope first.')} />
                ) : (
                    businesses.map((business) => (
                        <section key={business.id} className="flex flex-col gap-2">
                            <SectionHeader title={business.name} aside={t('{count} keys with guidance', { count: business.rows.length })} />
                            {business.rows.length === 0 ? (
                                <EmptyState title={t('Nothing written yet')} description={t('No active playbook and no draft for this business. The agents read nothing here until a version is activated.')} />
                            ) : (
                                <div className="bg-card overflow-x-auto rounded-xl border">
                                    <table className="w-full text-sm">
                                        <thead className="bg-muted/50 text-muted-foreground text-left text-xs">
                                            <tr>
                                                <th className="px-3 py-2 font-medium">{t('Scope')}</th>
                                                <th className="px-3 py-2 font-medium">{t('Kind')}</th>
                                                <th className="px-3 py-2 font-medium">{t('Active version')}</th>
                                                <th className="px-3 py-2 font-medium">{t('Activated')}</th>
                                                <th className="px-3 py-2 font-medium">{t('Waiting')}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {business.rows.map((row) => (
                                                <tr key={`${row.brand_id ?? 'all'}-${row.kind}`} className="border-t">
                                                    <td className="px-3 py-2 text-xs whitespace-nowrap">{row.brand ?? t('Business-wide')}</td>
                                                    <td className="px-3 py-2 text-xs whitespace-nowrap">{kindLabel(row.kind)}</td>
                                                    <td className="px-3 py-2">
                                                        {row.active ? (
                                                            <Link href={`/playbooks/${row.active.uuid}`} className="flex flex-col gap-0.5 hover:underline">
                                                                <span>
                                                                    v{row.active.version} {row.active.title}
                                                                </span>
                                                                <span className="text-muted-foreground font-mono text-[11px]">{row.active.content_digest.slice(0, 12)}</span>
                                                            </Link>
                                                        ) : (
                                                            <span className="text-muted-foreground text-xs">{t('None active')}</span>
                                                        )}
                                                    </td>
                                                    <td className="text-muted-foreground px-3 py-2 text-xs whitespace-nowrap">
                                                        {row.active ? (
                                                            <>
                                                                {formatDateTime(row.active.activated_at)}
                                                                <br />
                                                                {row.active.activated_by}
                                                            </>
                                                        ) : (
                                                            '-'
                                                        )}
                                                    </td>
                                                    <td className="px-3 py-2">
                                                        {row.pending ? (
                                                            <Link href={`/playbooks/${row.pending.uuid}`} className="flex items-center gap-2 hover:underline">
                                                                <StatusBadge tone={STATUS_TONES[row.pending.status] ?? 'neutral'} dot={false}>
                                                                    {statusLabel(row.pending.status)}
                                                                </StatusBadge>
                                                                <span className="text-xs">
                                                                    v{row.pending.version} {row.pending.title}
                                                                </span>
                                                            </Link>
                                                        ) : (
                                                            <span className="text-muted-foreground text-xs">-</span>
                                                        )}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </section>
                    ))
                )}

                {can.draft && businesses.length > 0 && (
                    <section className="bg-card flex flex-col gap-4 rounded-xl border p-4">
                        <SectionHeader title={t('New draft')} aside={t('A draft reaches no agent until it is reviewed and activated')} />
                        <form
                            className="flex flex-col gap-4"
                            onSubmit={(e) => {
                                e.preventDefault();
                                form.transform((d) => ({
                                    ...d,
                                    business_id: Number(d.business_id),
                                    brand_id: d.brand_id === '' ? null : Number(d.brand_id),
                                    summary: d.summary === '' ? null : d.summary,
                                    reason: d.reason === '' ? null : d.reason,
                                }));
                                form.post('/playbooks', { preserveScroll: true });
                            }}
                        >
                            <FormRow>
                                <Field label={t('Business')} htmlFor="business" size="auto" error={form.errors.business_id}>
                                    <NativeSelect
                                        id="business"
                                        value={form.data.business_id}
                                        onChange={(e) => form.setData({ ...form.data, business_id: e.target.value, brand_id: '' })}
                                        required
                                    >
                                        {businesses.map((b) => (
                                            <option key={b.id} value={b.id}>
                                                {b.name}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </Field>
                                <Field label={t('Brand')} htmlFor="brand" size="auto" hint={t('Business-wide guidance applies to every brand of the business.')} error={form.errors.brand_id}>
                                    <NativeSelect id="brand" value={form.data.brand_id} onChange={(e) => form.setData('brand_id', e.target.value)}>
                                        <option value="">{t('Business-wide')}</option>
                                        {(chosen?.brands ?? []).map((b) => (
                                            <option key={b.id} value={b.id}>
                                                {b.name}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </Field>
                                <Field label={t('Kind')} htmlFor="kind" size="auto" hint={kind ? t(kind.hint) : undefined} error={form.errors.kind}>
                                    <NativeSelect id="kind" value={form.data.kind} onChange={(e) => form.setData('kind', e.target.value)} required>
                                        {kinds.map((k) => (
                                            <option key={k.value} value={k.value}>
                                                {t(k.label)}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </Field>
                            </FormRow>
                            <FormRow>
                                <Field label={t('Title')} htmlFor="title" size="lg" error={form.errors.title}>
                                    <Input id="title" value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} maxLength={191} required />
                                </Field>
                                <Field label={t('Summary')} htmlFor="summary" size="lg" hint={t('One or two lines for the list. Optional.')} error={form.errors.summary}>
                                    <Input id="summary" value={form.data.summary} onChange={(e) => form.setData('summary', e.target.value)} maxLength={1000} />
                                </Field>
                            </FormRow>
                            <Field
                                label={t('Guidance')}
                                htmlFor="body"
                                hint={t('{count} of {max} characters. Plain text or Markdown. Prices and stock never go here; the agents look them up live.', {
                                    count: formatNumber(chars),
                                    max: formatNumber(limits.max_chars),
                                })}
                                error={form.errors.body}
                            >
                                <textarea
                                    id="body"
                                    value={form.data.body}
                                    onChange={(e) => form.setData('body', e.target.value)}
                                    rows={10}
                                    maxLength={limits.max_chars}
                                    required
                                    className="border-input bg-background focus-visible:ring-ring w-full rounded-md border px-3 py-2 font-mono text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                                />
                            </Field>
                            <FormRow>
                                <Field label={t('Why this draft')} htmlFor="reason" size="lg" hint={t('Optional. Kept in the history.')} error={form.errors.reason}>
                                    <Input id="reason" value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} maxLength={1000} />
                                </Field>
                            </FormRow>
                            <div className="flex items-center gap-3">
                                <Button type="submit" disabled={form.processing}>
                                    {t('Save draft')}
                                </Button>
                                <span className="text-muted-foreground text-xs">{t('Nothing goes live from here. Activation is a separate act with its own permission.')}</span>
                            </div>
                        </form>
                    </section>
                )}
            </div>
        </AppLayout>
    );
}
