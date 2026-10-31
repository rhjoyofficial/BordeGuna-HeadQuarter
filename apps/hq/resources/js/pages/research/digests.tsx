import { EmptyState } from '@/components/empty-state';
import { Field, FormRow, NativeSelect } from '@/components/form/field';
import { PageHeader, SectionHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Notice } from '@/components/ui/notice';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

/**
 * Research digests (docs/89 §3.8, docs/145-a): competitor and market notes assembled ONLY from filed,
 * cited research. Every entry carries its source; a person's note is labelled as theirs and may state no
 * figure that a cited entry does not carry. Nothing on this page searches, fetches or calls a model.
 */

type Entry = {
    claim_id: string;
    artifact_id: number;
    kind: string;
    statement: string;
    citation: {
        url?: string | null;
        retrieved_at?: string | null;
        excerpt?: string | null;
        system?: string | null;
        ref?: string | null;
        as_of?: string | null;
    };
};
type Digest = {
    uuid: string;
    kind: string;
    title: string;
    business: string | null;
    brand: string | null;
    entries: Entry[];
    note: string | null;
    digest: string;
    created_at: string | null;
};
type Artifact = {
    id: number;
    question: string;
    version: number;
    business_id: number | null;
    brand_id: number | null;
    cited_claims: number;
    as_of: string | null;
};
type Props = {
    digests: Digest[];
    artifacts: Artifact[];
    engineOn: boolean;
    options: { kinds: string[]; businesses: { id: number; name: string }[]; brands: { id: number; business_id: number; name: string }[] };
    can: { build: boolean };
};

export default function ResearchDigests({ digests, artifacts, engineOn, options, can }: Props) {
    const t = useT();
    const { flash, errors } = usePage<{ flash?: { status?: string }; errors: Record<string, string> }>().props;
    const [open, setOpen] = useState(false);
    const breadcrumbs: BreadcrumbItem[] = [{ title: 'Research digests', href: '/research/digests' }];
    const form = useForm<{ business_id: string; brand_id: string; kind: string; title: string; artifact_ids: number[]; note: string }>({
        business_id: options.businesses[0] ? String(options.businesses[0].id) : '',
        brand_id: '',
        kind: 'competitor',
        title: '',
        artifact_ids: [],
        note: '',
    });
    const kindLabel = (k: string) => (k === 'market' ? t('Market') : t('Competitors'));
    const forBusiness = artifacts.filter((a) => String(a.business_id) === form.data.business_id && a.cited_claims > 0);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Research digests')} />
            <div className="flex max-w-7xl flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={t('Research digests')}
                    description={t(
                        'Competitor and market notes built only from filed research. Every line carries its source and when it was read; a number nobody can trace back is refused, never estimated.',
                    )}
                    actions={
                        can.build ? (
                            <Button size="sm" onClick={() => setOpen(true)}>
                                {t('Build a digest')}
                            </Button>
                        ) : undefined
                    }
                />
                {flash?.status === 'digest-built' && <Notice tone="ok">{t('The digest is filed.')}</Notice>}
                {errors?.form && (
                    <Notice tone="danger" alert>
                        {errors.form}
                    </Notice>
                )}
                {!engineOn && (
                    <Notice tone="info">
                        {t(
                            'The research engine is off (flag research.engine), so no new research is gathered. Digests can still be built from research already filed.',
                        )}
                    </Notice>
                )}

                <section className="flex flex-col gap-3">
                    <SectionHeader title={t('Digests')} />
                    {digests.length === 0 ? (
                        <EmptyState title={t('No digests yet')} description={t('A digest appears when a person builds one from filed research.')} />
                    ) : (
                        digests.map((d) => (
                            <article key={d.uuid} className="hq-card flex flex-col gap-3 p-4">
                                <div className="flex flex-wrap items-center gap-2">
                                    <h2 className="text-base font-semibold">{d.title}</h2>
                                    <StatusBadge tone="info" dot={false}>
                                        {kindLabel(d.kind)}
                                    </StatusBadge>
                                    <span className="text-muted-foreground text-xs">
                                        {[d.business, d.brand].filter(Boolean).join(' / ')} {formatDateTime(d.created_at)}
                                    </span>
                                </div>
                                <ul className="flex flex-col gap-2 text-sm">
                                    {d.entries.map((e) => (
                                        <li key={`${e.artifact_id}-${e.claim_id}`} className="border-l-2 pl-3">
                                            <div>{e.statement}</div>
                                            <div className="text-muted-foreground text-xs break-all">
                                                {e.kind === 'fact'
                                                    ? t('Source: {url}, read {when}', {
                                                          url: e.citation.url ?? '-',
                                                          when: formatDateTime(e.citation.retrieved_at ?? null),
                                                      })
                                                    : t('From {system}: {ref}', { system: e.citation.system ?? '-', ref: e.citation.ref ?? '-' })}
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                                {d.note && (
                                    <div className="bg-muted rounded-lg p-3 text-sm">
                                        <div className="hq-eyebrow mb-1">{t("A person's note")}</div>
                                        <div className="whitespace-pre-wrap">{d.note}</div>
                                    </div>
                                )}
                            </article>
                        ))
                    )}
                </section>

                <Dialog open={open} onOpenChange={setOpen}>
                    <DialogContent className="sm:max-w-2xl">
                        <DialogHeader>
                            <DialogTitle>{t('Build a digest')}</DialogTitle>
                            <DialogDescription>
                                {t('Pick filed research. Only cited facts and system metrics are copied in, word for word.')}
                            </DialogDescription>
                        </DialogHeader>
                        <form
                            className="flex flex-col gap-3"
                            onSubmit={(ev) => {
                                ev.preventDefault();
                                form.post('/research/digests', { preserveScroll: true, onSuccess: () => setOpen(false) });
                            }}
                        >
                            <FormRow>
                                <Field label={t('Business')} htmlFor="rd-business" error={form.errors.business_id}>
                                    <NativeSelect
                                        id="rd-business"
                                        value={form.data.business_id}
                                        onChange={(ev) =>
                                            form.setData({ ...form.data, business_id: ev.target.value, brand_id: '', artifact_ids: [] })
                                        }
                                    >
                                        {options.businesses.map((b) => (
                                            <option key={b.id} value={b.id}>
                                                {b.name}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </Field>
                                <Field label={t('Brand')} htmlFor="rd-brand" error={form.errors.brand_id}>
                                    <NativeSelect
                                        id="rd-brand"
                                        value={form.data.brand_id}
                                        onChange={(ev) => form.setData('brand_id', ev.target.value)}
                                    >
                                        <option value="">{t('Business-wide')}</option>
                                        {options.brands
                                            .filter((b) => String(b.business_id) === form.data.business_id)
                                            .map((b) => (
                                                <option key={b.id} value={b.id}>
                                                    {b.name}
                                                </option>
                                            ))}
                                    </NativeSelect>
                                </Field>
                                <Field label={t('About')} htmlFor="rd-kind" error={form.errors.kind}>
                                    <NativeSelect id="rd-kind" value={form.data.kind} onChange={(ev) => form.setData('kind', ev.target.value)}>
                                        {options.kinds.map((k) => (
                                            <option key={k} value={k}>
                                                {kindLabel(k)}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </Field>
                            </FormRow>
                            <Field label={t('Title')} htmlFor="rd-title" size="lg" error={form.errors.title}>
                                <Input
                                    id="rd-title"
                                    value={form.data.title}
                                    maxLength={160}
                                    onChange={(ev) => form.setData('title', ev.target.value)}
                                    required
                                />
                            </Field>
                            <fieldset className="flex flex-col gap-2">
                                <legend className="mb-1 text-sm font-medium">{t('Filed research')}</legend>
                                {forBusiness.length === 0 && (
                                    <div className="text-muted-foreground text-xs">{t('No filed research with cited claims for this business.')}</div>
                                )}
                                {forBusiness.map((a) => (
                                    <label key={a.id} className="flex items-start gap-2 text-sm">
                                        <Checkbox
                                            checked={form.data.artifact_ids.includes(a.id)}
                                            onCheckedChange={(c) =>
                                                form.setData(
                                                    'artifact_ids',
                                                    c ? [...form.data.artifact_ids, a.id] : form.data.artifact_ids.filter((x) => x !== a.id),
                                                )
                                            }
                                        />
                                        <span>
                                            {a.question}
                                            <span className="text-muted-foreground block text-xs">
                                                {t('{count} cited claims', { count: String(a.cited_claims) })}
                                            </span>
                                        </span>
                                    </label>
                                ))}
                                {form.errors.artifact_ids && <div className="text-tone-danger text-xs">{form.errors.artifact_ids}</div>}
                            </fieldset>
                            <Field
                                label={t('Your note (optional)')}
                                htmlFor="rd-note"
                                hint={t('Labelled as yours. Any figure in it must appear in a cited entry.')}
                                error={form.errors.note}
                            >
                                <Textarea
                                    id="rd-note"
                                    rows={3}
                                    maxLength={3000}
                                    value={form.data.note}
                                    onChange={(ev) => form.setData('note', ev.target.value)}
                                />
                            </Field>
                            <div>
                                <Button type="submit" disabled={form.processing || form.data.artifact_ids.length === 0}>
                                    {t('Build a digest')}
                                </Button>
                            </div>
                        </form>
                    </DialogContent>
                </Dialog>
            </div>
        </AppLayout>
    );
}
