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
 */

type Checks = {
    passed: boolean;
    unsourced: string[];
    violations: { phrase: string; reason: string | null }[];
    uncovered: string[];
    banned: string[];
    why: string | null;
};
type Draft = { uuid: string; text: string; status: string; text_provenance: string; checks: Checks };
type Guidance = { persona: string | null; playbooks: { kind: string; title: string; body: string }[]; forbidden: string[] };
type Entry = {
    uuid: string;
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
    guidance: Guidance | null;
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
    floor: { id: string; phrases: string[]; reason: string; legal_ref: string }[];
    posture: { drafting: boolean; ai_drafting: boolean; drafter_connected: boolean; ai_paused: boolean; publishing: boolean };
    options: {
        platforms: string[];
        occasions: string[];
        brands: { id: number; business_id: number; name: string }[];
        businesses: { id: number; name: string }[];
    };
    can: { plan: boolean; review: boolean; claims: boolean };
};

export default function ContentIndex({ entries, claimsRules, floor, posture, options, can }: Props) {
    const t = useT();
    const { flash, errors } = usePage<{ flash?: { status?: string }; errors: Record<string, string> }>().props;
    const [planning, setPlanning] = useState(false);
    const [ruling, setRuling] = useState(false);
    const [writing, setWriting] = useState<Entry | null>(null);
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
    const caption = useForm({ text: '' });

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
                                                        </div>
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
                                                                    caption.setData('text', '');
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
                                                            <ReasonAction
                                                                url={`/content/calendar/${e.uuid}/review`}
                                                                field="note"
                                                                extra={{ verdict: 'approved' }}
                                                                label={t('Approve')}
                                                                title={t('Approve this draft')}
                                                                description={t(
                                                                    'Approving the words is not publishing them. Publishing needs its own approval of the exact post.',
                                                                )}
                                                                variant="default"
                                                                disabled={!e.draft.checks.passed}
                                                            />
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
                            <div>
                                <Button type="submit" disabled={caption.processing}>
                                    {t('Check and save the draft')}
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
