import { ReasonAction } from '@/components/completion/reason-action';
import { EmptyState } from '@/components/empty-state';
import { Field, FormRow, NativeSelect } from '@/components/form/field';
import { PageHeader, SectionHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Notice } from '@/components/ui/notice';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

/**
 * Content (docs/89 §3.7, docs/145-a): the calendar per brand, its drafts and the owner's claims rules.
 * A plan is never a post. A draft is checked (figures against the ERP, the owner's claims rules, the floor
 * against unproven health claims and testimonials, banned words) and reviewed by a person. Publishing is a
 * separate approval of the exact post, and is off.
 *
 * Ruling 31 Oct 2026: a recorded, consented testimonial may be quoted by a person (item 13; the claims floor
 * still runs on every word); the writer approves their own draft only as the sole eligible reviewer, with a
 * reason, shown as self-decided (item 15).
 */

type Checks = {
    passed: boolean;
    unsourced: string[];
    violations: { phrase: string; reason: string | null }[];
    uncovered: string[];
    banned: string[];
    why: string | null;
};
type Draft = {
    uuid: string;
    text: string;
    status: string;
    text_provenance: string;
    content_digest: string;
    self_decided: boolean;
    self_decided_reason: string | null;
    testimonial: { applies: boolean; edited: boolean; reasons: string[] } | null;
    checks: Checks;
};
type Guidance = { persona: string | null; playbooks: { kind: string; title: string; body: string }[]; forbidden: string[] };
type Entry = {
    uuid: string;
    business_id: number;
    planned_for: string | null;
    brand: string | null;
    platform: string;
    occasion: string | null;
    product_lines: string[];
    idea: string;
    status: string;
    skip_reason: string | null;
    has_brief: boolean;
    draft: Draft | null;
    review: { own: boolean; sole_reviewer: boolean } | null;
    guidance: Guidance | null;
};
type Testimonial = {
    uuid: string;
    business: string | null;
    business_id: number;
    customer_label: string;
    original_statement: string;
    modalities: string[];
    channels: string[];
    valid_from: string | null;
    valid_until: string | null;
    source_channel: string;
    revoked_at: string | null;
};
type Rule = {
    uuid: string;
    business: string | null;
    product_line: string;
    kind: string;
    phrases: string[];
    reason: string;
    legal_ref: string | null;
    proof_ref: string | null;
    status: string;
    retire_reason: string | null;
};
type Props = {
    entries: Entry[];
    claimsRules: Rule[];
    testimonials: Testimonial[];
    floor: { id: string; phrases: string[]; reason: string; legal_ref: string }[];
    posture: { drafting: boolean; ai_drafting: boolean; drafter_connected: boolean; ai_paused: boolean; publishing: boolean };
    options: {
        platforms: string[];
        occasions: string[];
        brands: { id: number; business_id: number; name: string }[];
        businesses: { id: number; name: string }[];
        testimonial_modalities: string[];
        testimonial_sources: string[];
    };
    can: { plan: boolean; review: boolean; claims: boolean };
};

export default function ContentIndex({ entries, claimsRules, testimonials, floor, posture, options, can }: Props) {
    const t = useT();
    const { flash, errors } = usePage<{ flash?: { status?: string }; errors: Record<string, string> }>().props;
    const [planning, setPlanning] = useState(false);
    const [ruling, setRuling] = useState(false);
    const [writing, setWriting] = useState<Entry | null>(null);
    const [quoting, setQuoting] = useState(false);
    const breadcrumbs: BreadcrumbItem[] = [{ title: 'Content', href: '/content' }];

    const plan = useForm({
        brand_id: options.brands[0] ? String(options.brands[0].id) : '',
        planned_for: '',
        platform: 'facebook_page',
        occasion: '',
        product_lines: '',
        idea: '',
    });
    const rule = useForm({
        business_id: options.businesses[0] ? String(options.businesses[0].id) : '',
        product_line: '',
        kind: 'forbidden',
        phrases: '',
        reason: '',
        legal_ref: '',
        proof_ref: '',
    });
    const caption = useForm({ text: '', testimonial_uuid: '', testimonial_edited: false as boolean });
    const quote = useForm({
        business_id: options.businesses[0] ? String(options.businesses[0].id) : '',
        customer_label: '',
        original_statement: '',
        original_evidence_asset_id: '',
        consent_document_asset_id: '',
        consent_given_at: '',
        permitted_modalities: ['text'] as string[],
        brand_ids: [] as string[],
        channels: ['facebook_page'] as string[],
        valid_from: '',
        valid_until: '',
        source_channel: 'whatsapp',
        source_ref: '',
        captured_at: '',
    });
    const toggle = (list: string[], value: string) => (list.includes(value) ? list.filter((v) => v !== value) : [...list, value]);
    const MODALITY: Record<string, string> = { text: t('Text'), image: t('Image'), video: t('Video'), voice: t('Voice') };
    const SOURCE: Record<string, string> = {
        whatsapp: t('WhatsApp'),
        messenger: t('Messenger'),
        instagram: t('Instagram'),
        email: t('Email'),
        phone: t('Phone call'),
        in_person: t('In person'),
        review_site: t('Review site'),
    };

    const platformLabel = (p: string) => (p === 'instagram' ? t('Instagram') : t('Facebook Page'));
    const OCCASIONS: Record<string, string> = {
        everyday: t('Everyday'),
        campaign: t('Campaign'),
        ramadan: t('Ramadan'),
        eid_ul_fitr: t('Eid ul-Fitr'),
        eid_ul_adha: t('Eid ul-Adha'),
        pohela_boishakh: t('Pohela Boishakh'),
        hilsa_season: t('Hilsa season'),
        winter_pitha: t('Winter pitha season'),
        mango_season: t('Mango season'),
        national_day: t('National day'),
    };
    const occasionLabel = (o: string | null) => (o ? (OCCASIONS[o] ?? o) : '');
    const FLASH: Record<string, string> = {
        'content-planned': t('The post is on the calendar.'),
        'content-drafted': t('The draft was checked and waits for a review.'),
        'content-reviewed': t('Your review is recorded.'),
        'content-skipped': t('The post is skipped.'),
        'claims-rule-written': t('The claims rule is active for every new check.'),
        'claims-rule-retired': t('The claims rule is retired.'),
        'testimonial-recorded': t('The testimonial and its consent are recorded.'),
        'testimonial-revoked': t("The customer's consent is withdrawn. No draft may quote it any more."),
    };
    const flashText = flash?.status ? FLASH[flash.status] : undefined;
    const formError = errors?.form;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Content')} />
            <div className="flex max-w-7xl flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={t('Content calendar')}
                    description={t(
                        "Plan posts per brand, write or draft the words, and review them. Every draft is checked against the ERP figures, the owner's claims rules and the floor: no unproven health claim, no invented testimonial. Publishing is a separate approval of the exact post.",
                    )}
                    actions={
                        <>
                            {can.plan && (
                                <Button size="sm" onClick={() => setPlanning(true)}>
                                    {t('Plan a post')}
                                </Button>
                            )}
                            {can.claims && (
                                <Button size="sm" variant="outline" onClick={() => setRuling(true)}>
                                    {t('Write a claims rule')}
                                </Button>
                            )}
                            {can.claims && (
                                <Button size="sm" variant="outline" onClick={() => setQuoting(true)}>
                                    {t('Record a testimonial')}
                                </Button>
                            )}
                        </>
                    }
                />

                {flashText && <Notice tone="ok">{flashText}</Notice>}
                {formError && (
                    <Notice tone="danger" alert>
                        {formError}
                    </Notice>
                )}
                <div className="flex flex-wrap gap-2 text-xs">
                    <StatusBadge tone={posture.drafting ? 'ok' : 'neutral'}>{posture.drafting ? t('Drafting on') : t('Drafting off')}</StatusBadge>
                    <StatusBadge tone={posture.ai_drafting && posture.drafter_connected && !posture.ai_paused ? 'ok' : 'neutral'}>
                        {posture.ai_paused ? t('AI paused') : posture.drafter_connected ? t('AI drafter connected') : t('No AI drafter connected')}
                    </StatusBadge>
                    <StatusBadge tone={posture.publishing ? 'warn' : 'neutral'}>
                        {posture.publishing ? t('Publishing on') : t('Publishing off')}
                    </StatusBadge>
                </div>

                <section className="flex flex-col gap-3">
                    <SectionHeader
                        title={t('Planned posts')}
                        aside={<span className="text-muted-foreground text-xs">{t('Last 30 days and ahead')}</span>}
                    />
                    {entries.length === 0 ? (
                        <EmptyState
                            title={t('Nothing planned')}
                            description={t(
                                'A post appears here when a person plans it for a brand and a date. Nothing is planned or posted by itself.',
                            )}
                        />
                    ) : (
                        <div className="hq-table-box">
                            <table className="w-full text-sm">
                                <thead className="text-left text-xs">
                                    <tr>
                                        <th className="px-3 py-2 font-medium">{t('Date')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Brand')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Idea')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Draft and checks')}</th>
                                        <th className="px-3 py-2 font-medium">{t('State')}</th>
                                        <th className="px-3 py-2" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {entries.map((e) => (
                                        <tr key={e.uuid} className="border-t align-top">
                                            <td className="px-3 py-2 text-xs whitespace-nowrap tabular-nums">
                                                <div>{e.planned_for}</div>
                                                <div className="text-muted-foreground">{occasionLabel(e.occasion)}</div>
                                            </td>
                                            <td className="px-3 py-2 text-xs whitespace-nowrap">
                                                <div className="font-medium">{e.brand}</div>
                                                <div className="text-muted-foreground">{platformLabel(e.platform)}</div>
                                            </td>
                                            <td className="max-w-xs px-3 py-2 text-xs">
                                                <div>{e.idea}</div>
                                                <div className="text-muted-foreground font-mono">{e.product_lines.join(', ')}</div>
                                                {e.skip_reason && (
                                                    <div className="text-muted-foreground">{t('Skipped: {reason}', { reason: e.skip_reason })}</div>
                                                )}
                                            </td>
                                            <td className="max-w-md px-3 py-2 text-xs">
                                                {e.draft ? (
                                                    <div className="flex flex-col gap-1">
                                                        <div className="whitespace-pre-wrap">{e.draft.text}</div>
                                                        <div className="flex flex-wrap gap-1">
                                                            <StatusBadge tone={e.draft.checks.passed ? 'ok' : 'danger'} dot={false}>
                                                                {e.draft.checks.passed ? t('Checks passed') : t('Checks failed')}
                                                            </StatusBadge>
                                                            <StatusBadge tone="neutral" dot={false}>
                                                                {e.draft.text_provenance === 'generated'
                                                                    ? t('Written by AI')
                                                                    : t('Written by a person')}
                                                            </StatusBadge>
                                                            {e.draft.testimonial && (
                                                                <StatusBadge tone={e.draft.testimonial.applies ? 'neutral' : 'danger'} dot={false}>
                                                                    {e.draft.testimonial.edited
                                                                        ? t('Quotes an edited testimonial')
                                                                        : t('Quotes a testimonial')}
                                                                </StatusBadge>
                                                            )}
                                                            {e.draft.self_decided && (
                                                                <StatusBadge tone="warn" dot={false}>
                                                                    {t('Self-decided')}
                                                                </StatusBadge>
                                                            )}
                                                        </div>
                                                        {e.draft.self_decided_reason && (
                                                            <div className="text-muted-foreground">
                                                                {t('Approved by its writer as the only reviewer: {reason}', {
                                                                    reason: e.draft.self_decided_reason,
                                                                })}
                                                            </div>
                                                        )}
                                                        {e.draft.testimonial?.reasons.map((r, i) => (
                                                            <div key={`t${i}`} className="text-tone-danger">
                                                                {r}
                                                            </div>
                                                        ))}
                                                        {e.draft.checks.unsourced.length > 0 && (
                                                            <div className="text-tone-danger">
                                                                {t('Figures with no ERP source: {list}', {
                                                                    list: e.draft.checks.unsourced.join(', '),
                                                                })}
                                                            </div>
                                                        )}
                                                        {e.draft.checks.violations.map((v, i) => (
                                                            <div key={i} className="text-tone-danger">
                                                                {t('Forbidden phrase: {phrase}', { phrase: v.phrase })}
                                                            </div>
                                                        ))}
                                                        {e.draft.checks.uncovered.length > 0 && (
                                                            <div className="text-tone-warn">
                                                                {t('No claims rule covers: {list}', { list: e.draft.checks.uncovered.join(', ') })}
                                                            </div>
                                                        )}
                                                        {e.draft.checks.banned.length > 0 && (
                                                            <div className="text-tone-danger">
                                                                {t('Banned words: {list}', { list: e.draft.checks.banned.join(', ') })}
                                                            </div>
                                                        )}
                                                    </div>
                                                ) : (
                                                    <span className="text-muted-foreground">
                                                        {e.has_brief ? t('No draft yet') : t('This brand has no approved content brief')}
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-3 py-2 text-xs whitespace-nowrap">
                                                <StatusBadge status={e.status} />
                                            </td>
                                            <td className="px-3 py-2">
                                                <div className="flex flex-wrap justify-end gap-2">
                                                    {can.plan && ['planned', 'drafted'].includes(e.status) && (
                                                        <>
                                                            <Button
                                                                size="sm"
                                                                variant="outline"
                                                                onClick={() => {
                                                                    caption.setData({ text: '', testimonial_uuid: '', testimonial_edited: false });
                                                                    setWriting(e);
                                                                }}
                                                            >
                                                                {t('Write caption')}
                                                            </Button>
                                                            <Button
                                                                size="sm"
                                                                variant="ghost"
                                                                disabled={!posture.ai_drafting || !posture.drafter_connected || posture.ai_paused}
                                                                onClick={() =>
                                                                    router.post(`/content/calendar/${e.uuid}/ai-draft`, {}, { preserveScroll: true })
                                                                }
                                                            >
                                                                {t('Draft with AI')}
                                                            </Button>
                                                            <ReasonAction
                                                                url={`/content/calendar/${e.uuid}/skip`}
                                                                label={t('Skip')}
                                                                title={t('Skip this post')}
                                                                variant="ghost"
                                                            />
                                                        </>
                                                    )}
                                                    {can.review && e.status === 'drafted' && e.draft && (
                                                        <>
                                                            {e.review?.own && !e.review.sole_reviewer ? (
                                                                <span className="text-muted-foreground text-xs">
                                                                    {t('You wrote this draft: another reviewer approves it.')}
                                                                </span>
                                                            ) : (
                                                                <ReasonAction
                                                                    url={`/content/calendar/${e.uuid}/review`}
                                                                    field={e.review?.own ? 'self_reason' : 'note'}
                                                                    extra={{
                                                                        verdict: 'approved',
                                                                        ...(e.draft.testimonial?.edited
                                                                            ? { confirm_digest: e.draft.content_digest }
                                                                            : {}),
                                                                    }}
                                                                    label={t('Approve')}
                                                                    title={t('Approve this draft')}
                                                                    description={[
                                                                        t(
                                                                            'Approving the words is not publishing them. Publishing needs its own approval of the exact post.',
                                                                        ),
                                                                        e.review?.own
                                                                            ? t(
                                                                                  'You wrote this draft and are the only eligible reviewer for this business. Say why you approve it yourself; it is recorded as self-decided.',
                                                                              )
                                                                            : '',
                                                                        e.draft.testimonial?.edited
                                                                            ? t(
                                                                                  'It quotes an edited testimonial: you approve exactly these words: {text}',
                                                                                  {
                                                                                      text: e.draft.text,
                                                                                  },
                                                                              )
                                                                            : '',
                                                                    ]
                                                                        .filter(Boolean)
                                                                        .join(' ')}
                                                                    variant="default"
                                                                    disabled={!e.draft.checks.passed}
                                                                />
                                                            )}
                                                            <ReasonAction
                                                                url={`/content/calendar/${e.uuid}/review`}
                                                                field="note"
                                                                extra={{ verdict: 'rejected' }}
                                                                label={t('Reject')}
                                                                title={t('Reject this draft')}
                                                            />
                                                        </>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>

                <section className="flex flex-col gap-3">
                    <SectionHeader
                        title={t('Claims rules')}
                        aside={<span className="text-muted-foreground text-xs">{t('Written by the owner; never inferred')}</span>}
                    />
                    <div className="hq-card flex flex-col gap-2 p-4 text-xs">
                        <div className="font-medium">{t('The floor, applied to every draft')}</div>
                        {floor.map((f) => (
                            <div key={f.id}>
                                <span className="text-tone-danger font-medium">{f.legal_ref}</span> <span>{f.reason}</span>
                                <div className="text-muted-foreground">{f.phrases.join(', ')}</div>
                            </div>
                        ))}
                    </div>
                    {claimsRules.length === 0 ? (
                        <EmptyState
                            title={t('No claims rules yet')}
                            description={t(
                                'Until the owner writes a rule for a product line, every draft about it fails closed: HQ never infers what may be claimed.',
                            )}
                        />
                    ) : (
                        <div className="hq-table-box">
                            <table className="w-full text-sm">
                                <thead className="text-left text-xs">
                                    <tr>
                                        <th className="px-3 py-2 font-medium">{t('Product line')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Kind')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Phrases')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Reason')}</th>
                                        <th className="px-3 py-2 font-medium">{t('State')}</th>
                                        <th className="px-3 py-2" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {claimsRules.map((r) => (
                                        <tr key={r.uuid} className="border-t align-top">
                                            <td className="px-3 py-2 font-mono text-xs">
                                                {r.product_line}
                                                <div className="text-muted-foreground font-sans">{r.business}</div>
                                            </td>
                                            <td className="px-3 py-2 text-xs">
                                                <StatusBadge tone={r.kind === 'forbidden' ? 'danger' : 'ok'} dot={false}>
                                                    {r.kind === 'forbidden' ? t('Forbidden') : t('Allowed')}
                                                </StatusBadge>
                                            </td>
                                            <td className="max-w-xs px-3 py-2 text-xs">{r.phrases.join(', ')}</td>
                                            <td className="max-w-sm px-3 py-2 text-xs">
                                                <div>{r.reason}</div>
                                                {r.legal_ref && <div className="text-muted-foreground">{r.legal_ref}</div>}
                                                {r.proof_ref && (
                                                    <div className="text-muted-foreground">{t('Proof: {ref}', { ref: r.proof_ref })}</div>
                                                )}
                                                {r.retire_reason && (
                                                    <div className="text-muted-foreground">{t('Retired: {reason}', { reason: r.retire_reason })}</div>
                                                )}
                                            </td>
                                            <td className="px-3 py-2 text-xs">
                                                <StatusBadge status={r.status} />
                                            </td>
                                            <td className="px-3 py-2 text-right">
                                                {can.claims && r.status === 'active' && (
                                                    <ReasonAction
                                                        url={`/content/claims-rules/${r.uuid}/retire`}
                                                        label={t('Retire')}
                                                        title={t('Retire this claims rule')}
                                                    />
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
                    <SectionHeader
                        title={t('Customer testimonials')}
                        aside={<span className="text-muted-foreground text-xs">{t('Only with recorded consent; never written by AI')}</span>}
                    />
                    <Notice tone="info">
                        {t(
                            "A real customer's words may be quoted only with their recorded consent for that exact use, evidence of what they said, and a person's review of the final post. Consent never makes a health or medical claim acceptable: the floor still applies to every quoted word.",
                        )}
                    </Notice>
                    {testimonials.length === 0 ? (
                        <EmptyState
                            title={t('No testimonials recorded')}
                            description={t('Until one is recorded with its consent, every draft that quotes a customer fails the floor.')}
                        />
                    ) : (
                        <div className="hq-table-box">
                            <table className="w-full text-sm">
                                <thead className="text-left text-xs">
                                    <tr>
                                        <th className="px-3 py-2 font-medium">{t('Customer')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Original words')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Consent covers')}</th>
                                        <th className="px-3 py-2 font-medium">{t('State')}</th>
                                        <th className="px-3 py-2" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {testimonials.map((q) => (
                                        <tr key={q.uuid} className="border-t align-top">
                                            <td className="px-3 py-2 text-xs">
                                                <div className="font-medium">{q.customer_label}</div>
                                                <div className="text-muted-foreground">{q.business}</div>
                                                <div className="text-muted-foreground">{SOURCE[q.source_channel] ?? q.source_channel}</div>
                                            </td>
                                            <td className="max-w-sm px-3 py-2 text-xs whitespace-pre-wrap">{q.original_statement}</td>
                                            <td className="px-3 py-2 text-xs">
                                                <div>{q.modalities.map((m) => MODALITY[m] ?? m).join(', ')}</div>
                                                <div className="text-muted-foreground">{q.channels.map(platformLabel).join(', ')}</div>
                                                <div className="text-muted-foreground tabular-nums">
                                                    {q.valid_from} - {q.valid_until}
                                                </div>
                                            </td>
                                            <td className="px-3 py-2 text-xs">
                                                <StatusBadge status={q.revoked_at ? 'revoked' : 'active'} />
                                            </td>
                                            <td className="px-3 py-2 text-right">
                                                {can.claims && !q.revoked_at && (
                                                    <ReasonAction
                                                        url={`/content/testimonials/${q.uuid}/revoke`}
                                                        label={t('Withdraw')}
                                                        title={t('Withdraw this consent')}
                                                        description={t(
                                                            'Final. No draft may quote it again, and a post waiting to publish is stopped.',
                                                        )}
                                                    />
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>

                <Dialog open={planning} onOpenChange={setPlanning}>
                    <DialogContent className="sm:max-w-2xl">
                        <DialogHeader>
                            <DialogTitle>{t('Plan a post')}</DialogTitle>
                            <DialogDescription>{t('A plan is never a post. Nothing is written or published by planning it.')}</DialogDescription>
                        </DialogHeader>
                        <form
                            className="flex flex-col gap-3"
                            onSubmit={(ev) => {
                                ev.preventDefault();
                                plan.post('/content/calendar', { preserveScroll: true, onSuccess: () => setPlanning(false) });
                            }}
                        >
                            <FormRow>
                                <Field label={t('Brand')} htmlFor="cp-brand" error={plan.errors.brand_id}>
                                    <NativeSelect
                                        id="cp-brand"
                                        value={plan.data.brand_id}
                                        onChange={(ev) => plan.setData('brand_id', ev.target.value)}
                                    >
                                        {options.brands.map((b) => (
                                            <option key={b.id} value={b.id}>
                                                {b.name}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </Field>
                                <Field label={t('Date')} htmlFor="cp-date" error={plan.errors.planned_for}>
                                    <Input
                                        id="cp-date"
                                        type="date"
                                        value={plan.data.planned_for}
                                        onChange={(ev) => plan.setData('planned_for', ev.target.value)}
                                        required
                                    />
                                </Field>
                                <Field label={t('Where')} htmlFor="cp-platform" error={plan.errors.platform}>
                                    <NativeSelect
                                        id="cp-platform"
                                        value={plan.data.platform}
                                        onChange={(ev) => plan.setData('platform', ev.target.value)}
                                    >
                                        {options.platforms.map((p) => (
                                            <option key={p} value={p}>
                                                {platformLabel(p)}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </Field>
                                <Field label={t('Occasion')} htmlFor="cp-occasion" error={plan.errors.occasion}>
                                    <NativeSelect
                                        id="cp-occasion"
                                        value={plan.data.occasion}
                                        onChange={(ev) => plan.setData('occasion', ev.target.value)}
                                    >
                                        <option value="">{t('None')}</option>
                                        {options.occasions.map((o) => (
                                            <option key={o} value={o}>
                                                {occasionLabel(o)}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </Field>
                            </FormRow>
                            <Field
                                label={t('Product lines')}
                                htmlFor="cp-lines"
                                size="lg"
                                hint={t('Keys like mustard_oil, honey; separated by commas. The claims rules are applied per line.')}
                                error={plan.errors.product_lines}
                            >
                                <Input
                                    id="cp-lines"
                                    value={plan.data.product_lines}
                                    onChange={(ev) => plan.setData('product_lines', ev.target.value)}
                                    required
                                />
                            </Field>
                            <Field label={t('What the post is about')} htmlFor="cp-idea" error={plan.errors.idea}>
                                <Textarea
                                    id="cp-idea"
                                    rows={3}
                                    maxLength={1000}
                                    value={plan.data.idea}
                                    onChange={(ev) => plan.setData('idea', ev.target.value)}
                                    required
                                />
                            </Field>
                            <div>
                                <Button type="submit" disabled={plan.processing}>
                                    {t('Plan a post')}
                                </Button>
                            </div>
                        </form>
                    </DialogContent>
                </Dialog>

                <Dialog open={writing !== null} onOpenChange={(o) => !o && setWriting(null)}>
                    <DialogContent className="sm:max-w-3xl">
                        <DialogHeader>
                            <DialogTitle>{t('Write the caption')}</DialogTitle>
                            <DialogDescription>
                                {t('Your words are checked like any draft. Figures must match the ERP; forbidden phrases fail the check.')}
                            </DialogDescription>
                        </DialogHeader>
                        {writing?.guidance && (
                            <div className="hq-card flex max-h-64 flex-col gap-2 overflow-y-auto p-3 text-xs">
                                <div className="font-medium">{t('From the playbooks')}</div>
                                {writing.guidance.persona && <div>{t('Voice: {persona}', { persona: writing.guidance.persona })}</div>}
                                {writing.guidance.playbooks.length === 0 && (
                                    <div className="text-muted-foreground">{t('No active playbook for this brand yet.')}</div>
                                )}
                                {writing.guidance.playbooks.map((p) => (
                                    <div key={p.kind}>
                                        <div className="font-medium">{p.title}</div>
                                        <div className="text-muted-foreground whitespace-pre-wrap">{p.body}</div>
                                    </div>
                                ))}
                                {writing.guidance.forbidden.length > 0 && (
                                    <div className="text-tone-danger">
                                        {t('Never write: {list}', { list: writing.guidance.forbidden.join(', ') })}
                                    </div>
                                )}
                            </div>
                        )}
                        <form
                            className="flex flex-col gap-3"
                            onSubmit={(ev) => {
                                ev.preventDefault();
                                if (writing)
                                    caption.post(`/content/calendar/${writing.uuid}/draft`, {
                                        preserveScroll: true,
                                        onSuccess: () => setWriting(null),
                                    });
                            }}
                        >
                            <Field label={t('Caption')} htmlFor="cw-text" error={caption.errors.text}>
                                <Textarea
                                    id="cw-text"
                                    rows={6}
                                    maxLength={5000}
                                    value={caption.data.text}
                                    onChange={(ev) => caption.setData('text', ev.target.value)}
                                    required
                                />
                            </Field>
                            <FormRow>
                                <Field
                                    label={t('Quote a testimonial')}
                                    htmlFor="cw-quote"
                                    size="lg"
                                    hint={t("Copy the customer's words exactly. Only recorded, consented testimonials of this business are listed.")}
                                    error={caption.errors.testimonial_uuid}
                                >
                                    <NativeSelect
                                        id="cw-quote"
                                        value={caption.data.testimonial_uuid}
                                        onChange={(ev) => caption.setData('testimonial_uuid', ev.target.value)}
                                    >
                                        <option value="">{t('None')}</option>
                                        {testimonials
                                            .filter((q) => !q.revoked_at && q.business_id === writing?.business_id)
                                            .map((q) => (
                                                <option key={q.uuid} value={q.uuid}>
                                                    {q.customer_label}: {q.original_statement.slice(0, 60)}
                                                </option>
                                            ))}
                                    </NativeSelect>
                                </Field>
                                {caption.data.testimonial_uuid !== '' && (
                                    <label className="flex items-center gap-2 text-xs">
                                        <input
                                            type="checkbox"
                                            checked={caption.data.testimonial_edited}
                                            onChange={(ev) => caption.setData('testimonial_edited', ev.target.checked)}
                                        />
                                        {t("I edited the customer's words (the exact final post must be approved as edited)")}
                                    </label>
                                )}
                            </FormRow>
                            <div>
                                <Button type="submit" disabled={caption.processing}>
                                    {t('Check and save the draft')}
                                </Button>
                            </div>
                        </form>
                    </DialogContent>
                </Dialog>

                <Dialog open={quoting} onOpenChange={setQuoting}>
                    <DialogContent className="sm:max-w-2xl">
                        <DialogHeader>
                            <DialogTitle>{t('Record a testimonial')}</DialogTitle>
                            <DialogDescription>
                                {t(
                                    "The customer's own words, the evidence of what they said and their recorded consent, both from the asset library. What it covers never changes; withdrawal is final.",
                                )}
                            </DialogDescription>
                        </DialogHeader>
                        <form
                            className="flex max-h-[70vh] flex-col gap-3 overflow-y-auto"
                            onSubmit={(ev) => {
                                ev.preventDefault();
                                quote.post('/content/testimonials', { preserveScroll: true, onSuccess: () => setQuoting(false) });
                            }}
                        >
                            <FormRow>
                                <Field label={t('Business')} htmlFor="ct-business" error={quote.errors.business_id}>
                                    <NativeSelect
                                        id="ct-business"
                                        value={quote.data.business_id}
                                        onChange={(ev) => quote.setData({ ...quote.data, business_id: ev.target.value, brand_ids: [] })}
                                    >
                                        {options.businesses.map((b) => (
                                            <option key={b.id} value={b.id}>
                                                {b.name}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </Field>
                                <Field
                                    label={t('Customer name as shown')}
                                    htmlFor="ct-label"
                                    size="md"
                                    hint={t('No phone number or address.')}
                                    error={quote.errors.customer_label}
                                >
                                    <Input
                                        id="ct-label"
                                        value={quote.data.customer_label}
                                        onChange={(ev) => quote.setData('customer_label', ev.target.value)}
                                        required
                                    />
                                </Field>
                            </FormRow>
                            <Field label={t('Original words, exactly')} htmlFor="ct-statement" error={quote.errors.original_statement}>
                                <Textarea
                                    id="ct-statement"
                                    rows={3}
                                    maxLength={2000}
                                    value={quote.data.original_statement}
                                    onChange={(ev) => quote.setData('original_statement', ev.target.value)}
                                    required
                                />
                            </Field>
                            <FormRow>
                                <Field
                                    label={t('Evidence asset number')}
                                    htmlFor="ct-evidence"
                                    hint={t('The screenshot, recording or note of what the customer said.')}
                                    error={quote.errors.original_evidence_asset_id}
                                >
                                    <Input
                                        id="ct-evidence"
                                        inputMode="numeric"
                                        value={quote.data.original_evidence_asset_id}
                                        onChange={(ev) => quote.setData('original_evidence_asset_id', ev.target.value)}
                                        required
                                    />
                                </Field>
                                <Field
                                    label={t('Consent record asset number')}
                                    htmlFor="ct-consent"
                                    hint={t("The customer's recorded consent for this exact use.")}
                                    error={quote.errors.consent_document_asset_id}
                                >
                                    <Input
                                        id="ct-consent"
                                        inputMode="numeric"
                                        value={quote.data.consent_document_asset_id}
                                        onChange={(ev) => quote.setData('consent_document_asset_id', ev.target.value)}
                                        required
                                    />
                                </Field>
                            </FormRow>
                            <FormRow>
                                <Field label={t('Said on')} htmlFor="ct-captured" error={quote.errors.captured_at}>
                                    <Input
                                        id="ct-captured"
                                        type="date"
                                        value={quote.data.captured_at}
                                        onChange={(ev) => quote.setData('captured_at', ev.target.value)}
                                        required
                                    />
                                </Field>
                                <Field label={t('Where')} htmlFor="ct-source" error={quote.errors.source_channel}>
                                    <NativeSelect
                                        id="ct-source"
                                        value={quote.data.source_channel}
                                        onChange={(ev) => quote.setData('source_channel', ev.target.value)}
                                    >
                                        {options.testimonial_sources.map((src) => (
                                            <option key={src} value={src}>
                                                {SOURCE[src] ?? src}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </Field>
                                <Field label={t('Consent given on')} htmlFor="ct-given" error={quote.errors.consent_given_at}>
                                    <Input
                                        id="ct-given"
                                        type="date"
                                        value={quote.data.consent_given_at}
                                        onChange={(ev) => quote.setData('consent_given_at', ev.target.value)}
                                        required
                                    />
                                </Field>
                            </FormRow>
                            <FormRow>
                                <Field label={t('Valid from')} htmlFor="ct-from" error={quote.errors.valid_from}>
                                    <Input
                                        id="ct-from"
                                        type="date"
                                        value={quote.data.valid_from}
                                        onChange={(ev) => quote.setData('valid_from', ev.target.value)}
                                        required
                                    />
                                </Field>
                                <Field label={t('Valid until')} htmlFor="ct-until" error={quote.errors.valid_until}>
                                    <Input
                                        id="ct-until"
                                        type="date"
                                        value={quote.data.valid_until}
                                        onChange={(ev) => quote.setData('valid_until', ev.target.value)}
                                        required
                                    />
                                </Field>
                            </FormRow>
                            <fieldset className="flex flex-wrap gap-3 text-xs">
                                <legend className="mb-1 font-medium">{t('Consent covers these uses')}</legend>
                                {options.testimonial_modalities.map((m) => (
                                    <label key={m} className="flex items-center gap-1">
                                        <input
                                            type="checkbox"
                                            checked={quote.data.permitted_modalities.includes(m)}
                                            onChange={() => quote.setData('permitted_modalities', toggle(quote.data.permitted_modalities, m))}
                                        />
                                        {MODALITY[m] ?? m}
                                    </label>
                                ))}
                            </fieldset>
                            <fieldset className="flex flex-wrap gap-3 text-xs">
                                <legend className="mb-1 font-medium">{t('Consent covers these channels')}</legend>
                                {options.platforms.map((p) => (
                                    <label key={p} className="flex items-center gap-1">
                                        <input
                                            type="checkbox"
                                            checked={quote.data.channels.includes(p)}
                                            onChange={() => quote.setData('channels', toggle(quote.data.channels, p))}
                                        />
                                        {platformLabel(p)}
                                    </label>
                                ))}
                            </fieldset>
                            <fieldset className="flex flex-wrap gap-3 text-xs">
                                <legend className="mb-1 font-medium">{t('Consent covers these brands')}</legend>
                                {options.brands
                                    .filter((b) => String(b.business_id) === quote.data.business_id)
                                    .map((b) => (
                                        <label key={b.id} className="flex items-center gap-1">
                                            <input
                                                type="checkbox"
                                                checked={quote.data.brand_ids.includes(String(b.id))}
                                                onChange={() => quote.setData('brand_ids', toggle(quote.data.brand_ids, String(b.id)))}
                                            />
                                            {b.name}
                                        </label>
                                    ))}
                            </fieldset>
                            {(quote.errors.permitted_modalities || quote.errors.channels || quote.errors.brand_ids) && (
                                <div className="text-tone-danger text-xs">
                                    {quote.errors.permitted_modalities ?? quote.errors.channels ?? quote.errors.brand_ids}
                                </div>
                            )}
                            <div>
                                <Button type="submit" disabled={quote.processing}>
                                    {t('Record a testimonial')}
                                </Button>
                            </div>
                        </form>
                    </DialogContent>
                </Dialog>

                <Dialog open={ruling} onOpenChange={setRuling}>
                    <DialogContent className="sm:max-w-2xl">
                        <DialogHeader>
                            <DialogTitle>{t('Write a claims rule')}</DialogTitle>
                            <DialogDescription>{t('A rule is never edited. To change one, retire it and write a new one.')}</DialogDescription>
                        </DialogHeader>
                        <form
                            className="flex flex-col gap-3"
                            onSubmit={(ev) => {
                                ev.preventDefault();
                                rule.post('/content/claims-rules', { preserveScroll: true, onSuccess: () => setRuling(false) });
                            }}
                        >
                            <FormRow>
                                <Field label={t('Business')} htmlFor="cr-business" error={rule.errors.business_id}>
                                    <NativeSelect
                                        id="cr-business"
                                        value={rule.data.business_id}
                                        onChange={(ev) => rule.setData('business_id', ev.target.value)}
                                    >
                                        {options.businesses.map((b) => (
                                            <option key={b.id} value={b.id}>
                                                {b.name}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </Field>
                                <Field
                                    label={t('Product line')}
                                    htmlFor="cr-line"
                                    size="md"
                                    hint={t('A key like mustard_oil, or * for every line.')}
                                    error={rule.errors.product_line}
                                >
                                    <Input
                                        id="cr-line"
                                        value={rule.data.product_line}
                                        onChange={(ev) => rule.setData('product_line', ev.target.value)}
                                        required
                                    />
                                </Field>
                                <Field label={t('Kind')} htmlFor="cr-kind" error={rule.errors.kind}>
                                    <NativeSelect id="cr-kind" value={rule.data.kind} onChange={(ev) => rule.setData('kind', ev.target.value)}>
                                        <option value="forbidden">{t('Forbidden')}</option>
                                        <option value="allowed">{t('Allowed')}</option>
                                    </NativeSelect>
                                </Field>
                            </FormRow>
                            <Field
                                label={t('Phrases')}
                                htmlFor="cr-phrases"
                                hint={t('One per line. English and Bangla both.')}
                                error={rule.errors.phrases}
                            >
                                <Textarea
                                    id="cr-phrases"
                                    rows={4}
                                    value={rule.data.phrases}
                                    onChange={(ev) => rule.setData('phrases', ev.target.value)}
                                    required
                                />
                            </Field>
                            <Field label={t('Reason')} htmlFor="cr-reason" error={rule.errors.reason}>
                                <Input id="cr-reason" value={rule.data.reason} onChange={(ev) => rule.setData('reason', ev.target.value)} required />
                            </Field>
                            <FormRow>
                                <Field
                                    label={t('Legal reference')}
                                    htmlFor="cr-legal"
                                    size="md"
                                    hint={t('For example CRPA 2009 s.44. Optional.')}
                                    error={rule.errors.legal_ref}
                                >
                                    <Input id="cr-legal" value={rule.data.legal_ref} onChange={(ev) => rule.setData('legal_ref', ev.target.value)} />
                                </Field>
                                <Field
                                    label={t('Proof')}
                                    htmlFor="cr-proof"
                                    size="lg"
                                    hint={t('Required for an allowed claim: the lab report or certificate it rests on.')}
                                    error={rule.errors.proof_ref}
                                >
                                    <Input id="cr-proof" value={rule.data.proof_ref} onChange={(ev) => rule.setData('proof_ref', ev.target.value)} />
                                </Field>
                            </FormRow>
                            <div>
                                <Button type="submit" disabled={rule.processing}>
                                    {t('Write a claims rule')}
                                </Button>
                            </div>
                        </form>
                    </DialogContent>
                </Dialog>
            </div>
        </AppLayout>
    );
}
