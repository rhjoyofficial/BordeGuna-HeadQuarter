import { ReasonAction } from '@/components/completion/reason-action';
import { EmptyState } from '@/components/empty-state';
import { Field, FormRow, NativeSelect } from '@/components/form/field';
import { PageHeader, SectionHeader } from '@/components/page-header';
import { type ListMeta, Pagination } from '@/components/pagination';
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
 * Avatar consent (docs/20, docs/145-b). No consent, no generation. A real person's consent points at the
 * signed document; it always ends; revoking it stops every unfinished generation that rested on it, at once.
 * Asking for a clip is an approval-gated request that is switched off today.
 */

type Consent = {
    uuid: string;
    business: string | null;
    business_id: number;
    person_label: string;
    kind: string;
    modalities: string[];
    permitted_uses: string[];
    brands: { id: number; name: string }[];
    has_document: boolean;
    signed_at: string | null;
    expires_at: string | null;
    state: string;
    revoked_at: string | null;
    revocation_reason: string | null;
};
type Generation = {
    uuid: string;
    consent: string | null;
    brand: string | null;
    use: string;
    modality: string;
    status: string;
    reason: string | null;
    provider: string | null;
    created_at: string | null;
};
type Props = {
    consents: Consent[];
    generations: Generation[];
    consentsPagination: ListMeta;
    generationsPagination: ListMeta;
    posture: { generation_flag: boolean; provider_connected: boolean; tool_registered: boolean };
    options: {
        kinds: string[];
        modalities: string[];
        uses: string[];
        max_months: number;
        businesses: { id: number; name: string }[];
        brands: { id: number; business_id: number; name: string }[];
        documents: { id: number; business_id: number | null; label: string }[];
    };
    can: { manage: boolean; generate: boolean };
};

export default function AvatarIndex({ consents, generations, consentsPagination, generationsPagination, posture, options, can }: Props) {
    const t = useT();
    const { flash, errors } = usePage<{ flash?: { status?: string }; errors: Record<string, string> }>().props;
    const [recording, setRecording] = useState(false);
    const [asking, setAsking] = useState<Consent | null>(null);
    const breadcrumbs: BreadcrumbItem[] = [{ title: 'Avatar consent', href: '/avatar' }];

    const form = useForm<{
        business_id: string;
        person_label: string;
        kind: string;
        modalities: string[];
        permitted_uses: string[];
        brand_ids: number[];
        consent_document_asset_id: string;
        signed_at: string;
        expires_at: string;
    }>({
        business_id: options.businesses[0] ? String(options.businesses[0].id) : '',
        person_label: '',
        kind: 'synthetic_persona',
        modalities: [],
        permitted_uses: [],
        brand_ids: [],
        consent_document_asset_id: '',
        signed_at: '',
        expires_at: '',
    });
    const gen = useForm({ consent_uuid: '', brand_id: '', use: 'product_promo', modality: 'face', script: '' });

    const LABELS: Record<string, string> = {
        real_person: t('Real person'),
        synthetic_persona: t('Synthetic persona'),
        face: t('Face'),
        voice: t('Voice'),
        product_promo: t('Product promotion'),
        brand_presenter: t('Brand presenter'),
        internal: t('Internal only'),
    };
    const label = (v: string) => LABELS[v] ?? v;
    const toggle = (list: string[], v: string, on: boolean) => (on ? [...list, v] : list.filter((x) => x !== v));
    const FLASH: Record<string, string> = {
        'avatar-consent-recorded': t('The consent is recorded.'),
        'avatar-consent-revoked': t('The consent is revoked. Nothing more is generated with it.'),
        'avatar-generation-awaiting-approval': t('The request waits for an approval.'),
        'avatar-generated': t('The clip was generated.'),
    };
    const flashText = flash?.status ? FLASH[flash.status] : undefined;
    const genReady = posture.generation_flag && posture.provider_connected && posture.tool_registered;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Avatar consent')} />
            <div className="flex max-w-7xl flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={t('Avatar consent')}
                    description={t(
                        "Whose face or voice HQ may use, for which brands and uses, and until when. No consent, no generation. A verbal yes is not consent: a real person's consent points at the signed document.",
                    )}
                    actions={
                        can.manage ? (
                            <Button size="sm" onClick={() => setRecording(true)}>
                                {t('Record a consent')}
                            </Button>
                        ) : undefined
                    }
                />
                {flashText && <Notice tone="ok">{flashText}</Notice>}
                {(errors?.form || errors?.generate) && (
                    <Notice tone="danger" alert>
                        {errors.form ?? errors.generate}
                    </Notice>
                )}
                {!genReady && (
                    <Notice tone="info">
                        {t(
                            'Generation is off: the flag avatar.generation, a connected provider and the registered tool are all needed, and every request still waits for an approval.',
                        )}
                    </Notice>
                )}

                <section className="flex flex-col gap-3">
                    <SectionHeader title={t('Consents')} />
                    {consents.length === 0 ? (
                        <EmptyState
                            title={t('No consent recorded')}
                            description={t("Until one is recorded, nothing can be generated with anybody's face or voice.")}
                        />
                    ) : (
                        <div className="hq-table-box">
                            <table className="w-full text-sm">
                                <thead className="text-left text-xs">
                                    <tr>
                                        <th className="px-3 py-2 font-medium">{t('Person')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Covers')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Until')}</th>
                                        <th className="px-3 py-2 font-medium">{t('State')}</th>
                                        <th className="px-3 py-2" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {consents.map((c) => (
                                        <tr key={c.uuid} className="border-t align-top">
                                            <td className="px-3 py-2 text-xs">
                                                <div className="font-medium">{c.person_label}</div>
                                                <div className="text-muted-foreground">
                                                    {label(c.kind)} / {c.business}
                                                </div>
                                                {c.kind === 'real_person' && (
                                                    <div className={c.has_document ? 'text-tone-ok' : 'text-tone-danger'}>
                                                        {c.has_document ? t('Signed document on record') : t('No signed document')}
                                                    </div>
                                                )}
                                            </td>
                                            <td className="px-3 py-2 text-xs">
                                                <div>{c.modalities.map(label).join(', ')}</div>
                                                <div className="text-muted-foreground">{c.permitted_uses.map(label).join(', ')}</div>
                                                <div className="text-muted-foreground">{c.brands.map((b) => b.name).join(', ')}</div>
                                            </td>
                                            <td className="px-3 py-2 text-xs whitespace-nowrap tabular-nums">{formatDateTime(c.expires_at)}</td>
                                            <td className="px-3 py-2 text-xs">
                                                <StatusBadge
                                                    status={c.state}
                                                    tone={c.state === 'active' ? 'ok' : c.state === 'revoked' ? 'danger' : 'neutral'}
                                                />
                                                {c.revocation_reason && <div className="text-muted-foreground mt-1">{c.revocation_reason}</div>}
                                            </td>
                                            <td className="px-3 py-2">
                                                <div className="flex flex-wrap justify-end gap-2">
                                                    {can.generate && c.state === 'active' && (
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            disabled={!genReady}
                                                            onClick={() => {
                                                                gen.setData({
                                                                    consent_uuid: c.uuid,
                                                                    brand_id: c.brands[0] ? String(c.brands[0].id) : '',
                                                                    use: c.permitted_uses[0] ?? '',
                                                                    modality: c.modalities[0] ?? '',
                                                                    script: '',
                                                                });
                                                                setAsking(c);
                                                            }}
                                                        >
                                                            {t('Ask for a clip')}
                                                        </Button>
                                                    )}
                                                    {can.manage && c.state !== 'revoked' && (
                                                        <ReasonAction
                                                            url={`/avatar/consents/${c.uuid}/revoke`}
                                                            label={t('Revoke')}
                                                            title={t('Revoke this consent')}
                                                            description={t(
                                                                'Final and immediate. Every unfinished clip that rested on it is withdrawn.',
                                                            )}
                                                            variant="destructive"
                                                        />
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                    <Pagination meta={consentsPagination} />
                </section>

                <section className="flex flex-col gap-3">
                    <SectionHeader title={t('Generation requests')} />
                    {generations.length === 0 ? (
                        <EmptyState
                            title={t('No requests')}
                            description={t('Every request, refused or not, is listed here with the consent it rested on.')}
                        />
                    ) : (
                        <div className="hq-table-box">
                            <table className="w-full text-sm">
                                <thead className="text-left text-xs">
                                    <tr>
                                        <th className="px-3 py-2 font-medium">{t('When')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Person')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Use')}</th>
                                        <th className="px-3 py-2 font-medium">{t('State')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {generations.map((g) => (
                                        <tr key={g.uuid} className="border-t align-top">
                                            <td className="px-3 py-2 text-xs whitespace-nowrap tabular-nums">{formatDateTime(g.created_at)}</td>
                                            <td className="px-3 py-2 text-xs">
                                                {g.consent} / {g.brand}
                                            </td>
                                            <td className="px-3 py-2 text-xs">
                                                {label(g.use)}, {label(g.modality)}
                                            </td>
                                            <td className="px-3 py-2 text-xs">
                                                <StatusBadge status={g.status} />
                                                {g.reason && <div className="text-muted-foreground mt-1">{g.reason}</div>}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                    <Pagination meta={generationsPagination} />
                </section>

                <Dialog open={recording} onOpenChange={setRecording}>
                    <DialogContent className="sm:max-w-2xl">
                        <DialogHeader>
                            <DialogTitle>{t('Record a consent')}</DialogTitle>
                            <DialogDescription>
                                {t('What a consent covers never changes after it is recorded. To change it, revoke it and record a new one.')}
                            </DialogDescription>
                        </DialogHeader>
                        <form
                            className="flex flex-col gap-3"
                            onSubmit={(ev) => {
                                ev.preventDefault();
                                form.post('/avatar/consents', { preserveScroll: true, onSuccess: () => setRecording(false) });
                            }}
                        >
                            <FormRow>
                                <Field label={t('Business')} htmlFor="ac-business" error={form.errors.business_id}>
                                    <NativeSelect
                                        id="ac-business"
                                        value={form.data.business_id}
                                        onChange={(ev) =>
                                            form.setData({ ...form.data, business_id: ev.target.value, brand_ids: [], consent_document_asset_id: '' })
                                        }
                                    >
                                        {options.businesses.map((b) => (
                                            <option key={b.id} value={b.id}>
                                                {b.name}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </Field>
                                <Field label={t('Name shown')} htmlFor="ac-label" size="md" error={form.errors.person_label}>
                                    <Input
                                        id="ac-label"
                                        maxLength={120}
                                        value={form.data.person_label}
                                        onChange={(ev) => form.setData('person_label', ev.target.value)}
                                        required
                                    />
                                </Field>
                                <Field label={t('Kind')} htmlFor="ac-kind" error={form.errors.kind}>
                                    <NativeSelect id="ac-kind" value={form.data.kind} onChange={(ev) => form.setData('kind', ev.target.value)}>
                                        {options.kinds.map((k) => (
                                            <option key={k} value={k}>
                                                {label(k)}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </Field>
                            </FormRow>
                            <div className="flex flex-wrap gap-6 text-sm">
                                <fieldset className="flex flex-col gap-1">
                                    <legend className="mb-1 font-medium">{t('May use')}</legend>
                                    {options.modalities.map((m) => (
                                        <label key={m} className="flex items-center gap-2">
                                            <Checkbox
                                                checked={form.data.modalities.includes(m)}
                                                onCheckedChange={(c) => form.setData('modalities', toggle(form.data.modalities, m, c === true))}
                                            />
                                            {label(m)}
                                        </label>
                                    ))}
                                </fieldset>
                                <fieldset className="flex flex-col gap-1">
                                    <legend className="mb-1 font-medium">{t('For')}</legend>
                                    {options.uses.map((u) => (
                                        <label key={u} className="flex items-center gap-2">
                                            <Checkbox
                                                checked={form.data.permitted_uses.includes(u)}
                                                onCheckedChange={(c) =>
                                                    form.setData('permitted_uses', toggle(form.data.permitted_uses, u, c === true))
                                                }
                                            />
                                            {label(u)}
                                        </label>
                                    ))}
                                </fieldset>
                                <fieldset className="flex flex-col gap-1">
                                    <legend className="mb-1 font-medium">{t('Brands')}</legend>
                                    {options.brands
                                        .filter((b) => String(b.business_id) === form.data.business_id)
                                        .map((b) => (
                                            <label key={b.id} className="flex items-center gap-2">
                                                <Checkbox
                                                    checked={form.data.brand_ids.includes(b.id)}
                                                    onCheckedChange={(c) =>
                                                        form.setData(
                                                            'brand_ids',
                                                            c ? [...form.data.brand_ids, b.id] : form.data.brand_ids.filter((x) => x !== b.id),
                                                        )
                                                    }
                                                />
                                                {b.name}
                                            </label>
                                        ))}
                                </fieldset>
                            </div>
                            {(form.errors.modalities || form.errors.permitted_uses || form.errors.brand_ids) && (
                                <div className="text-tone-danger text-xs">
                                    {form.errors.modalities ?? form.errors.permitted_uses ?? form.errors.brand_ids}
                                </div>
                            )}
                            {form.data.kind === 'real_person' && (
                                <FormRow>
                                    <Field label={t('Signed document')} htmlFor="ac-doc" error={form.errors.consent_document_asset_id}>
                                        <NativeSelect
                                            id="ac-doc"
                                            value={form.data.consent_document_asset_id}
                                            onChange={(ev) => form.setData('consent_document_asset_id', ev.target.value)}
                                        >
                                            <option value="">{t('Choose from the asset library')}</option>
                                            {options.documents
                                                .filter((d) => String(d.business_id) === form.data.business_id)
                                                .map((d) => (
                                                    <option key={d.id} value={d.id}>
                                                        {d.label}
                                                    </option>
                                                ))}
                                        </NativeSelect>
                                    </Field>
                                    <Field label={t('Signed on')} htmlFor="ac-signed" error={form.errors.signed_at}>
                                        <Input
                                            id="ac-signed"
                                            type="date"
                                            value={form.data.signed_at}
                                            onChange={(ev) => form.setData('signed_at', ev.target.value)}
                                        />
                                    </Field>
                                </FormRow>
                            )}
                            <Field
                                label={t('Ends on')}
                                htmlFor="ac-expires"
                                hint={t('Every consent ends. At most {months} months ahead.', { months: String(options.max_months) })}
                                error={form.errors.expires_at}
                            >
                                <Input
                                    id="ac-expires"
                                    type="date"
                                    value={form.data.expires_at}
                                    onChange={(ev) => form.setData('expires_at', ev.target.value)}
                                    required
                                />
                            </Field>
                            <div>
                                <Button type="submit" disabled={form.processing}>
                                    {t('Record a consent')}
                                </Button>
                            </div>
                        </form>
                    </DialogContent>
                </Dialog>

                <Dialog open={asking !== null} onOpenChange={(o) => !o && setAsking(null)}>
                    <DialogContent className="sm:max-w-xl">
                        <DialogHeader>
                            <DialogTitle>{t('Ask for a clip')}</DialogTitle>
                            <DialogDescription>
                                {t('The request waits for an approval. The consent is checked again before and after the provider works.')}
                            </DialogDescription>
                        </DialogHeader>
                        <form
                            className="flex flex-col gap-3"
                            onSubmit={(ev) => {
                                ev.preventDefault();
                                gen.post('/avatar/generate', { preserveScroll: true, onSuccess: () => setAsking(null) });
                            }}
                        >
                            <FormRow>
                                <Field label={t('Brand')} htmlFor="ag-brand" error={gen.errors.brand_id}>
                                    <NativeSelect id="ag-brand" value={gen.data.brand_id} onChange={(ev) => gen.setData('brand_id', ev.target.value)}>
                                        {(asking?.brands ?? []).map((b) => (
                                            <option key={b.id} value={b.id}>
                                                {b.name}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </Field>
                                <Field label={t('Use')} htmlFor="ag-use" error={gen.errors.use}>
                                    <NativeSelect id="ag-use" value={gen.data.use} onChange={(ev) => gen.setData('use', ev.target.value)}>
                                        {(asking?.permitted_uses ?? []).map((u) => (
                                            <option key={u} value={u}>
                                                {label(u)}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </Field>
                                <Field label={t('Face or voice')} htmlFor="ag-mod" error={gen.errors.modality}>
                                    <NativeSelect id="ag-mod" value={gen.data.modality} onChange={(ev) => gen.setData('modality', ev.target.value)}>
                                        {(asking?.modalities ?? []).map((m) => (
                                            <option key={m} value={m}>
                                                {label(m)}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </Field>
                            </FormRow>
                            <Field label={t('Script')} htmlFor="ag-script" error={gen.errors.script}>
                                <Textarea
                                    id="ag-script"
                                    rows={4}
                                    maxLength={2000}
                                    value={gen.data.script}
                                    onChange={(ev) => gen.setData('script', ev.target.value)}
                                    required
                                />
                            </Field>
                            <div>
                                <Button type="submit" disabled={gen.processing}>
                                    {t('Ask for a clip')}
                                </Button>
                            </div>
                        </form>
                    </DialogContent>
                </Dialog>
            </div>
        </AppLayout>
    );
}
