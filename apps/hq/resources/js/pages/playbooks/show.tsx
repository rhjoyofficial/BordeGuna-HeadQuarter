import { EmptyState } from '@/components/empty-state';
import { Field, FormRow } from '@/components/form/field';
import { PageHeader, SectionHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/format';
import { formatNumber, useT } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { type ReactNode } from 'react';
import { type Kind, STATUS_TONES, useKindLabel, useStatusLabel, type VersionSummary } from './index';

/**
 * One playbook version (docs/112 §7, S6): the text, who did what and when, the lint, the acts the person
 * may take, every event, and the other versions of the same key. Every act is a POST to a route with its
 * own permission; the page itself edits nothing.
 */

type Version = VersionSummary & {
    body: string;
    summary: string | null;
    warnings: { line: number; note: string; text: string }[];
    business: { id: number; name: string | null; slug: string | null };
    supersedes_version: number | null;
    review_note: string | null;
    activation_reason: string | null;
    retirement_reason: string | null;
    withdrawal_reason: string | null;
    submitted_by: string | null;
    submitted_at: string | null;
    reviewed_by: string | null;
    reviewed_at: string | null;
    retired_by: string | null;
    retired_at: string | null;
    withdrawn_by: string | null;
    withdrawn_at: string | null;
};
type Event = { id: number; event: string; actor: string | null; reason: string | null; content_digest: string; occurred_at: string | null };
type Props = {
    version: Version;
    events: Event[];
    history: VersionSummary[];
    kinds: Kind[];
    limits: { max_chars: number };
    can: { draft: boolean; review: boolean; activate: boolean };
};

const textareaClass = 'border-input bg-background focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-hidden';

function ReasonForm({ action, label, hint, field = 'reason', extra, variant = 'default' }: { action: string; label: string; hint?: string; field?: string; extra?: Record<string, string>; variant?: 'default' | 'outline' | 'destructive' }) {
    const t = useT();
    const form = useForm<Record<string, string>>({ [field]: '', ...(extra ?? {}) });
    const { errors } = usePage<{ errors: Record<string, string> }>().props;

    return (
        <form
            className="flex flex-col gap-2"
            onSubmit={(e) => {
                e.preventDefault();
                form.post(action, { preserveScroll: true, onSuccess: () => form.reset() });
            }}
        >
            <Field label={label} htmlFor={`${field}-${action}`} hint={hint} error={form.errors[field] ?? errors[field]}>
                <textarea id={`${field}-${action}`} value={form.data[field]} onChange={(e) => form.setData(field, e.target.value)} rows={2} maxLength={1000} required className={textareaClass} />
            </Field>
            <div>
                <Button type="submit" variant={variant} size="sm" disabled={form.processing}>
                    {label}
                </Button>
            </div>
        </form>
    );
}

export default function PlaybookShow({ version, events, history, kinds, limits, can }: Props) {
    const t = useT();
    const statusLabel = useStatusLabel();
    const kindLabel = useKindLabel(kinds);
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const url = `/playbooks/${version.uuid}`;
    const scope = version.brand ?? t('Business-wide');
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Playbooks', href: '/playbooks' },
        { title: `${kindLabel(version.kind)} v${version.version}`, href: url },
    ];
    const eventLabel = (e: string) =>
        ({
            drafted: t('Drafted'),
            edited: t('Edited'),
            submitted: t('Submitted for review'),
            approved: t('Approved'),
            sent_back: t('Sent back'),
            activated: t('Activated'),
            retired: t('Retired'),
            withdrawn: t('Withdrawn'),
        })[e] ?? e;

    const edit = useForm({ title: version.title, summary: version.summary ?? '', body: version.body, reason: '' });
    const editable = version.status === 'draft' && can.draft;
    const chars = edit.data.body.length;

    const facts: { label: string; value: ReactNode }[] = [
        { label: t('Business'), value: version.business.name },
        { label: t('Scope'), value: scope },
        { label: t('Kind'), value: kindLabel(version.kind) },
        { label: t('Version'), value: `v${version.version}` },
        { label: t('Digest'), value: <span className="font-mono text-xs break-all">{version.content_digest}</span> },
        { label: t('Size'), value: t('{count} characters', { count: formatNumber(version.chars) }) },
        { label: t('Drafted'), value: `${formatDateTime(version.drafted_at)} ${version.drafted_by ?? ''}` },
        ...(version.submitted_at ? [{ label: t('Submitted'), value: `${formatDateTime(version.submitted_at)} ${version.submitted_by ?? ''}` }] : []),
        ...(version.reviewed_at ? [{ label: t('Reviewed'), value: `${formatDateTime(version.reviewed_at)} ${version.reviewed_by ?? ''}${version.review_note ? `: ${version.review_note}` : ''}` }] : []),
        ...(version.activated_at
            ? [{ label: t('Activated'), value: `${formatDateTime(version.activated_at)} ${version.activated_by ?? ''}${version.activation_reason ? `: ${version.activation_reason}` : ''}` }]
            : []),
        ...(version.supersedes_version !== null ? [{ label: t('Superseded'), value: t('version {n}', { n: version.supersedes_version }) }] : []),
        ...(version.retired_at ? [{ label: t('Retired'), value: `${formatDateTime(version.retired_at)} ${version.retired_by ?? ''}${version.retirement_reason ? `: ${version.retirement_reason}` : ''}` }] : []),
        ...(version.withdrawn_at ? [{ label: t('Withdrawn'), value: `${formatDateTime(version.withdrawn_at)} ${version.withdrawn_by ?? ''}${version.withdrawal_reason ? `: ${version.withdrawal_reason}` : ''}` }] : []),
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${kindLabel(version.kind)} v${version.version}`} />
            <div className="flex max-w-6xl flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={version.title}
                    description={t('{business}, {scope}, {kind}. Guidance the agents read as business data. It grants no permission and changes no policy, consent or autonomy.', {
                        business: version.business.name ?? '',
                        scope,
                        kind: kindLabel(version.kind),
                    })}
                    actions={
                        <div className="flex items-center gap-2">
                            <StatusBadge tone={STATUS_TONES[version.status] ?? 'neutral'}>{statusLabel(version.status)}</StatusBadge>
                            <Button asChild variant="outline" size="sm">
                                <Link href="/playbooks">{t('All playbooks')}</Link>
                            </Button>
                        </div>
                    }
                />

                {errors.act && <div className="border-destructive/40 bg-destructive/5 text-destructive rounded-xl border px-4 py-3 text-sm">{errors.act}</div>}

                <section className="bg-card rounded-xl border p-4">
                    <dl className="grid grid-cols-1 gap-x-6 gap-y-2 text-sm sm:grid-cols-2 lg:grid-cols-3">
                        {facts.map((f) => (
                            <div key={f.label} className="flex flex-col">
                                <dt className="text-muted-foreground text-xs">{f.label}</dt>
                                <dd>{f.value}</dd>
                            </div>
                        ))}
                    </dl>
                </section>

                {version.warnings.length > 0 && (
                    <section className="flex flex-col gap-2">
                        <SectionHeader title={t('Lines that read as instructions to the agent')} aside={t('Advice for the reviewer. Nothing is blocked, and nothing here can change what an agent may do.')} />
                        <div className="bg-card rounded-xl border">
                            <ul className="divide-y text-sm">
                                {version.warnings.map((w) => (
                                    <li key={`${w.line}-${w.note}`} className="flex flex-col gap-0.5 px-4 py-2">
                                        <span className="text-muted-foreground text-xs">
                                            {t('Line {n}', { n: w.line })}: {t(w.note)}
                                        </span>
                                        <span className="font-mono text-xs">{w.text}</span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    </section>
                )}

                <section className="flex flex-col gap-2">
                    <SectionHeader title={t('Guidance')} aside={editable ? t('Editable while a draft') : t('Frozen; a change is a new draft')} />
                    {editable ? (
                        <form
                            className="bg-card flex flex-col gap-4 rounded-xl border p-4"
                            onSubmit={(e) => {
                                e.preventDefault();
                                edit.transform((d) => ({ ...d, summary: d.summary === '' ? null : d.summary, reason: d.reason === '' ? null : d.reason }));
                                edit.put(url, { preserveScroll: true, onSuccess: () => edit.reset('reason') });
                            }}
                        >
                            <FormRow>
                                <Field label={t('Title')} htmlFor="title" size="lg" error={edit.errors.title}>
                                    <Input id="title" value={edit.data.title} onChange={(e) => edit.setData('title', e.target.value)} maxLength={191} required />
                                </Field>
                                <Field label={t('Summary')} htmlFor="summary" size="lg" error={edit.errors.summary}>
                                    <Input id="summary" value={edit.data.summary} onChange={(e) => edit.setData('summary', e.target.value)} maxLength={1000} />
                                </Field>
                            </FormRow>
                            <Field
                                label={t('Guidance')}
                                htmlFor="body"
                                hint={t('{count} of {max} characters. Plain text or Markdown. Prices and stock never go here; the agents look them up live.', {
                                    count: formatNumber(chars),
                                    max: formatNumber(limits.max_chars),
                                })}
                                error={edit.errors.body}
                            >
                                <textarea id="body" value={edit.data.body} onChange={(e) => edit.setData('body', e.target.value)} rows={14} maxLength={limits.max_chars} required className={`${textareaClass} font-mono`} />
                            </Field>
                            <FormRow>
                                <Field label={t('What changed')} htmlFor="edit-reason" size="lg" hint={t('Optional. Kept in the history.')} error={edit.errors.reason}>
                                    <Input id="edit-reason" value={edit.data.reason} onChange={(e) => edit.setData('reason', e.target.value)} maxLength={1000} />
                                </Field>
                            </FormRow>
                            <div>
                                <Button type="submit" disabled={edit.processing}>
                                    {t('Save changes')}
                                </Button>
                            </div>
                        </form>
                    ) : (
                        <div className="bg-card rounded-xl border p-4">
                            {version.summary && <p className="text-muted-foreground mb-3 text-sm">{version.summary}</p>}
                            <pre className="font-sans text-sm whitespace-pre-wrap">{version.body}</pre>
                        </div>
                    )}
                </section>

                <section className="flex flex-col gap-2">
                    <SectionHeader title={t('Acts')} aside={t('Each act is recorded with the person, the time and the reason')} />
                    <div className="bg-card grid grid-cols-1 gap-6 rounded-xl border p-4 md:grid-cols-2">
                        {version.status === 'draft' && can.draft && (
                            <form
                                className="flex flex-col gap-2"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    router.post(`${url}/submit`, {}, { preserveScroll: true });
                                }}
                            >
                                <div className="text-sm font-medium">{t('Submit for review')}</div>
                                <p className="text-muted-foreground text-xs">{t('Freezes the text. A reviewer approves it or sends it back.')}</p>
                                <div>
                                    <Button type="submit" size="sm" variant="outline">
                                        {t('Submit for review')}
                                    </Button>
                                </div>
                            </form>
                        )}
                        {version.status === 'in_review' && can.review && (
                            <>
                                <ReasonForm action={`${url}/review`} label={t('Approve')} field="note" extra={{ decision: 'approve' }} hint={t('Your note is kept with the version. Approval does not activate it.')} />
                                <ReasonForm action={`${url}/review`} label={t('Send back')} field="note" extra={{ decision: 'send_back' }} hint={t('Returns it to draft with your note.')} variant="outline" />
                            </>
                        )}
                        {version.status === 'reviewed' && can.activate && (
                            <ReasonForm
                                action={`${url}/activate`}
                                label={t('Activate')}
                                hint={t('From the next agent run, this text is read for its key. The version active now is retired in the same step.')}
                            />
                        )}
                        {version.status === 'active' && can.activate && (
                            <ReasonForm action={`${url}/retire`} label={t('Retire')} hint={t('Nothing is active for this key afterwards; the agents read no guidance of this kind.')} variant="destructive" />
                        )}
                        {['draft', 'in_review', 'reviewed'].includes(version.status) && can.draft && (
                            <ReasonForm action={`${url}/withdraw`} label={t('Withdraw')} hint={t('The version is kept in the history and never becomes active.')} variant="outline" />
                        )}
                        {['retired', 'withdrawn'].includes(version.status) && <p className="text-muted-foreground text-sm">{t('This version is closed. Nothing more can happen to it.')}</p>}
                        {version.status === 'in_review' && !can.review && <p className="text-muted-foreground text-sm">{t('Waiting for a reviewer.')}</p>}
                        {version.status === 'reviewed' && !can.activate && <p className="text-muted-foreground text-sm">{t('Reviewed. Waiting for a person who may activate it.')}</p>}
                    </div>
                </section>

                <section className="flex flex-col gap-2">
                    <SectionHeader title={t('Events')} aside={t('{count} events', { count: events.length })} />
                    <div className="bg-card overflow-x-auto rounded-xl border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-muted-foreground text-left text-xs">
                                <tr>
                                    <th className="px-3 py-2 font-medium">{t('When')}</th>
                                    <th className="px-3 py-2 font-medium">{t('Event')}</th>
                                    <th className="px-3 py-2 font-medium">{t('Who')}</th>
                                    <th className="px-3 py-2 font-medium">{t('Reason')}</th>
                                    <th className="px-3 py-2 font-medium">{t('Digest')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {events.map((e) => (
                                    <tr key={e.id} className="border-t">
                                        <td className="text-muted-foreground px-3 py-2 text-xs whitespace-nowrap">{formatDateTime(e.occurred_at)}</td>
                                        <td className="px-3 py-2 text-xs">{eventLabel(e.event)}</td>
                                        <td className="px-3 py-2 text-xs">{e.actor}</td>
                                        <td className="px-3 py-2 text-xs">{e.reason}</td>
                                        <td className="px-3 py-2 font-mono text-[11px]">{e.content_digest.slice(0, 12)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>

                <section className="flex flex-col gap-2">
                    <SectionHeader title={t('Every version of this key')} aside={t('{count} versions', { count: history.length })} />
                    {history.length === 0 ? (
                        <EmptyState title={t('No other version')} />
                    ) : (
                        <div className="bg-card overflow-x-auto rounded-xl border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-muted-foreground text-left text-xs">
                                    <tr>
                                        <th className="px-3 py-2 font-medium">{t('Version')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Status')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Title')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Activated')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Digest')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {history.map((h) => (
                                        <tr key={h.uuid} className={`border-t ${h.uuid === version.uuid ? 'bg-muted/30' : ''}`}>
                                            <td className="px-3 py-2 text-xs">
                                                <Link href={`/playbooks/${h.uuid}`} className="hover:underline">
                                                    v{h.version}
                                                </Link>
                                            </td>
                                            <td className="px-3 py-2">
                                                <StatusBadge tone={STATUS_TONES[h.status] ?? 'neutral'} dot={false}>
                                                    {statusLabel(h.status)}
                                                </StatusBadge>
                                            </td>
                                            <td className="px-3 py-2 text-xs">{h.title}</td>
                                            <td className="text-muted-foreground px-3 py-2 text-xs whitespace-nowrap">{h.activated_at ? `${formatDateTime(h.activated_at)} ${h.activated_by ?? ''}` : '-'}</td>
                                            <td className="px-3 py-2 font-mono text-[11px]">{h.content_digest.slice(0, 12)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>
            </div>
        </AppLayout>
    );
}
