import { EmptyState } from '@/components/empty-state';
import { Field, FormRow, NativeSelect } from '@/components/form/field';
import { PageHeader, SectionHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Notice } from '@/components/ui/notice';
import { StatusBadge, type Tone } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, humanKey } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';

type Draft = { uuid: string; business: string | null; brief: string; status: string; provider: string | null; error: string | null; review_note: string | null; has_video: boolean; created_at: string | null };
type Props = { drafts: Draft[]; businesses: { slug: string; name: string }[]; aspects: string[]; max_seconds: number; can: { request: boolean; review: boolean } };

const TONE: Record<string, Tone> = { generating: 'info', ready: 'pending', approved: 'ok', rejected: 'neutral', failed: 'danger', queued: 'info' };
const breadcrumbs: BreadcrumbItem[] = [{ title: 'Reel drafts', href: '/media-drafts' }];

/**
 * Reel and video drafts (docs/144-media-pipeline). A draft is a video a provider made from a brief; a person watches it
 * and approves or rejects it. Approving publishes nothing: posting is a separate gate with its own approval.
 */
export default function MediaDrafts({ drafts, businesses, aspects, max_seconds, can }: Props) {
    const t = useT();
    const form = useForm({ business_slug: businesses[0]?.slug ?? '', brief: '', seconds: '8', aspect: aspects[0] ?? '9:16' });
    const label = (s: string) =>
        (({ generating: t('The provider is making it'), ready: t('Ready to review'), approved: t('Approved (not published)'), rejected: t('Rejected'), failed: t('Failed'), queued: t('Queued') }) as Record<string, string>)[s] ?? humanKey(s);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Reel drafts')} />
            <div className="flex max-w-5xl flex-col gap-6 p-4 md:p-6">
                <PageHeader title={t('Reel drafts')} description={t('Short videos made from a brief. A person approves each one; nothing is published from here.')} />

                {can.request && (
                    <form
                        className="hq-card flex flex-col gap-3 p-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post('/media-drafts', { preserveScroll: true, onSuccess: () => form.reset('brief') });
                        }}
                    >
                        <SectionHeader title={t('Ask for a draft')} />
                        <FormRow>
                            <Field label={t('Business')} htmlFor="m-business" size="lg" error={form.errors.business_slug}>
                                <NativeSelect id="m-business" value={form.data.business_slug} onChange={(e) => form.setData('business_slug', e.target.value)}>
                                    {businesses.map((b) => (
                                        <option key={b.slug} value={b.slug}>
                                            {b.name}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </Field>
                            <Field label={t('Seconds')} htmlFor="m-seconds" size="xs" error={form.errors.seconds}>
                                <Input id="m-seconds" type="number" min={1} max={max_seconds} value={form.data.seconds} onChange={(e) => form.setData('seconds', e.target.value)} />
                            </Field>
                            <Field label={t('Shape')} htmlFor="m-aspect" size="sm">
                                <NativeSelect id="m-aspect" value={form.data.aspect} onChange={(e) => form.setData('aspect', e.target.value)}>
                                    {aspects.map((a) => (
                                        <option key={a} value={a}>
                                            {a}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </Field>
                        </FormRow>
                        <Field label={t('What should the video show?')} htmlFor="m-brief" error={form.errors.brief}>
                            <Textarea id="m-brief" rows={3} value={form.data.brief} onChange={(e) => form.setData('brief', e.target.value)} maxLength={2000} />
                        </Field>
                        <div>
                            <Button type="submit" disabled={form.processing || form.data.brief.trim() === ''}>
                                {t('Ask for a draft')}
                            </Button>
                        </div>
                        <Notice tone="neutral">{t('This spends the provider credit, so it waits for an approval. If the provider is not connected yet, this says what is missing.')}</Notice>
                    </form>
                )}

                {drafts.length === 0 ? (
                    <EmptyState title={t('No drafts yet')} description={t('A draft appears here when you ask for one.')} />
                ) : (
                    <ul className="flex flex-col gap-3">
                        {drafts.map((d) => (
                            <li key={d.uuid} className="hq-card flex flex-col gap-2 p-4">
                                <div className="flex flex-wrap items-center gap-2 text-sm">
                                    <StatusBadge tone={TONE[d.status] ?? 'neutral'}>{label(d.status)}</StatusBadge>
                                    <span className="text-muted-foreground text-xs">
                                        {d.business} · {formatDateTime(d.created_at)}
                                    </span>
                                </div>
                                <p className="text-sm">{d.brief}</p>
                                {d.has_video && <video controls preload="metadata" className="max-h-96 max-w-full rounded-lg" src={`/media-drafts/${d.uuid}/video`} />}
                                {d.error && <Notice tone="danger">{d.error}</Notice>}
                                {d.review_note && <Notice tone="neutral">{d.review_note}</Notice>}
                                <div className="flex flex-wrap gap-2">
                                    {d.status === 'generating' && (
                                        <Button size="sm" variant="outline" onClick={() => router.post(`/media-drafts/${d.uuid}/refresh`, {}, { preserveScroll: true })}>
                                            {t('Check again')}
                                        </Button>
                                    )}
                                    {d.status === 'ready' && can.review && (
                                        <>
                                            <Button size="sm" onClick={() => router.post(`/media-drafts/${d.uuid}/decide`, { decision: 'approved' }, { preserveScroll: true })}>
                                                {t('Approve this draft')}
                                            </Button>
                                            <Button size="sm" variant="outline" onClick={() => router.post(`/media-drafts/${d.uuid}/decide`, { decision: 'rejected' }, { preserveScroll: true })}>
                                                {t('Reject')}
                                            </Button>
                                        </>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </AppLayout>
    );
}
