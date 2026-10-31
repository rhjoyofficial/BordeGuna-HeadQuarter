import { ReasonAction } from '@/components/completion/reason-action';
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
 * Skills and delegation (docs/10, docs/145-c). A skill is a named procedure limited to the tools it lists;
 * it grants nothing. A delegation can only narrow: the requester, both enabling users, the agents' scopes,
 * the skill's tools and every tool permission all intersect. People move a skill; nothing activates itself.
 */

type Skill = {
    uuid: string;
    key: string;
    version: number;
    name: string;
    purpose: string;
    required_tools: string[];
    business: string | null;
    group_wide: boolean;
    proposer_type: string;
    status: string;
    status_reason: string | null;
    self_decided: boolean;
    own: boolean;
};
type Delegation = {
    uuid: string;
    from: string;
    to: string;
    status: string;
    reason: string | null;
    tools: string[];
    business_ids: number[] | null;
    narrowed: number;
    created_at: string | null;
};
type Props = {
    skills: Skill[];
    delegations: Delegation[];
    delegationOn: boolean;
    options: { tools: string[]; businesses: { id: number; name: string }[]; group_wide: boolean };
    can: { propose: boolean; activate: boolean };
};

export default function SkillsIndex({ skills, delegations, delegationOn, options, can }: Props) {
    const t = useT();
    const { flash, errors } = usePage<{ flash?: { status?: string }; errors: Record<string, string> }>().props;
    const [open, setOpen] = useState(false);
    const breadcrumbs: BreadcrumbItem[] = [{ title: 'Skills', href: '/skills' }];
    const form = useForm<{ business_id: string; key: string; name: string; purpose: string; required_tools: string[] }>({
        business_id: options.group_wide ? '' : options.businesses[0] ? String(options.businesses[0].id) : '',
        key: '',
        name: '',
        purpose: '',
        required_tools: [],
    });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Skills')} />
            <div className="flex max-w-7xl flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={t('Skills and delegation')}
                    description={t(
                        'A skill is a named procedure limited to the tools it lists, and grants nothing. A delegation can only narrow what the asking person and both agents may do. People approve and activate; nothing activates itself.',
                    )}
                    actions={
                        can.propose ? (
                            <Button size="sm" onClick={() => setOpen(true)}>
                                {t('Propose a skill')}
                            </Button>
                        ) : undefined
                    }
                />
                {flash?.status && <Notice tone="ok">{t('Recorded.')}</Notice>}
                {errors?.form && (
                    <Notice tone="danger" alert>
                        {errors.form}
                    </Notice>
                )}
                {!delegationOn && (
                    <Notice tone="info">
                        {t('Delegation is off (flag agents.delegation). Nothing is delegated, and no delegated work is started from HQ.')}
                    </Notice>
                )}

                <section className="flex flex-col gap-3">
                    <SectionHeader title={t('Skills')} />
                    {skills.length === 0 ? (
                        <EmptyState
                            title={t('No skills yet')}
                            description={t('A skill appears when a person proposes one. A proposal recorded from an agent never activates itself.')}
                        />
                    ) : (
                        <div className="hq-table-box">
                            <table className="w-full text-sm">
                                <thead className="text-left text-xs">
                                    <tr>
                                        <th className="px-3 py-2 font-medium">{t('Skill')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Tools it may use')}</th>
                                        <th className="px-3 py-2 font-medium">{t('State')}</th>
                                        <th className="px-3 py-2" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {skills.map((s) => (
                                        <tr key={s.uuid} className="border-t align-top">
                                            <td className="max-w-md px-3 py-2 text-xs">
                                                <div className="font-medium">
                                                    {s.name} <span className="text-muted-foreground font-mono">{`${s.key} v${s.version}`}</span>
                                                </div>
                                                <div className="text-muted-foreground">{s.purpose}</div>
                                                <div className="text-muted-foreground">{s.group_wide ? t('Group-wide') : s.business}</div>
                                            </td>
                                            <td className="px-3 py-2 font-mono text-xs">{s.required_tools.join(', ')}</td>
                                            <td className="px-3 py-2 text-xs">
                                                <StatusBadge status={s.status} />
                                                {s.self_decided && (
                                                    <div className="text-tone-warn mt-1">{t('Approved by the person who proposed it')}</div>
                                                )}
                                                {s.status_reason && <div className="text-muted-foreground mt-1">{s.status_reason}</div>}
                                            </td>
                                            <td className="px-3 py-2">
                                                <div className="flex flex-wrap justify-end gap-2">
                                                    {can.activate && s.own && ['proposed', 'approved', 'suspended'].includes(s.status) && (
                                                        <span className="text-muted-foreground text-xs">
                                                            {t('You wrote this skill: another reviewer approves and activates it.')}
                                                        </span>
                                                    )}
                                                    {can.activate && !s.own && s.status === 'proposed' && (
                                                        <ReasonAction
                                                            url={`/skills/${s.uuid}/approve`}
                                                            label={t('Approve')}
                                                            title={t('Approve this skill')}
                                                            variant="default"
                                                        />
                                                    )}
                                                    {can.activate && !s.own && ['approved', 'suspended'].includes(s.status) && (
                                                        <ReasonAction
                                                            url={`/skills/${s.uuid}/activate`}
                                                            label={t('Activate')}
                                                            title={t('Activate this skill')}
                                                            description={t(
                                                                'An active skill only narrows a delegation; it never lets anybody do more.',
                                                            )}
                                                            variant="default"
                                                        />
                                                    )}
                                                    {can.propose && ['active', 'approved'].includes(s.status) && (
                                                        <ReasonAction
                                                            url={`/skills/${s.uuid}/suspend`}
                                                            label={t('Suspend')}
                                                            title={t('Suspend this skill')}
                                                        />
                                                    )}
                                                    {can.activate && s.status !== 'retired' && (
                                                        <ReasonAction
                                                            url={`/skills/${s.uuid}/retire`}
                                                            label={t('Retire')}
                                                            title={t('Retire this skill')}
                                                            variant="ghost"
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
                </section>

                <section className="flex flex-col gap-3">
                    <SectionHeader
                        title={t('Delegations')}
                        aside={<span className="text-muted-foreground text-xs">{t('Each one recorded, refused or not')}</span>}
                    />
                    {delegations.length === 0 ? (
                        <EmptyState
                            title={t('No delegations')}
                            description={t('A delegation appears when an agent asks another to do a skill. None has.')}
                        />
                    ) : (
                        <div className="hq-table-box">
                            <table className="w-full text-sm">
                                <thead className="text-left text-xs">
                                    <tr>
                                        <th className="px-3 py-2 font-medium">{t('When')}</th>
                                        <th className="px-3 py-2 font-medium">{t('From and to')}</th>
                                        <th className="px-3 py-2 font-medium">{t('What it may do')}</th>
                                        <th className="px-3 py-2 font-medium">{t('State')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {delegations.map((d) => (
                                        <tr key={d.uuid} className="border-t align-top">
                                            <td className="px-3 py-2 text-xs whitespace-nowrap tabular-nums">{formatDateTime(d.created_at)}</td>
                                            <td className="px-3 py-2 font-mono text-xs">{`${d.from} > ${d.to}`}</td>
                                            <td className="px-3 py-2 text-xs">
                                                <div className="font-mono">{d.tools.join(', ') || '-'}</div>
                                                <div className="text-muted-foreground">{t('{count} narrowings', { count: String(d.narrowed) })}</div>
                                            </td>
                                            <td className="px-3 py-2 text-xs">
                                                <StatusBadge status={d.status} tone={d.status === 'resolved' ? 'info' : 'neutral'} />
                                                {d.reason && <div className="text-muted-foreground mt-1">{d.reason}</div>}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>

                <Dialog open={open} onOpenChange={setOpen}>
                    <DialogContent className="sm:max-w-2xl">
                        <DialogHeader>
                            <DialogTitle>{t('Propose a skill')}</DialogTitle>
                            <DialogDescription>{t('A proposal does nothing until a person approves and activates it.')}</DialogDescription>
                        </DialogHeader>
                        <form
                            className="flex flex-col gap-3"
                            onSubmit={(ev) => {
                                ev.preventDefault();
                                form.post('/skills', { preserveScroll: true, onSuccess: () => setOpen(false) });
                            }}
                        >
                            <FormRow>
                                <Field label={t('Business')} htmlFor="sk-business" error={form.errors.business_id}>
                                    <NativeSelect
                                        id="sk-business"
                                        value={form.data.business_id}
                                        onChange={(ev) => form.setData('business_id', ev.target.value)}
                                    >
                                        {options.group_wide && <option value="">{t('Group-wide')}</option>}
                                        {options.businesses.map((b) => (
                                            <option key={b.id} value={b.id}>
                                                {b.name}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </Field>
                                <Field label={t('Skill key')} htmlFor="sk-key" size="md" hint={t('Like weekly_content_plan')} error={form.errors.key}>
                                    <Input id="sk-key" value={form.data.key} onChange={(ev) => form.setData('key', ev.target.value)} required />
                                </Field>
                                <Field label={t('Name')} htmlFor="sk-name" size="md" error={form.errors.name}>
                                    <Input id="sk-name" value={form.data.name} onChange={(ev) => form.setData('name', ev.target.value)} required />
                                </Field>
                            </FormRow>
                            <Field label={t('Purpose')} htmlFor="sk-purpose" error={form.errors.purpose}>
                                <Textarea
                                    id="sk-purpose"
                                    rows={3}
                                    value={form.data.purpose}
                                    onChange={(ev) => form.setData('purpose', ev.target.value)}
                                    required
                                />
                            </Field>
                            <fieldset className="flex max-h-56 flex-col gap-1 overflow-y-auto text-xs">
                                <legend className="mb-1 text-sm font-medium">{t('Tools it may use')}</legend>
                                {options.tools.map((k) => (
                                    <label key={k} className="flex items-center gap-2 font-mono">
                                        <Checkbox
                                            checked={form.data.required_tools.includes(k)}
                                            onCheckedChange={(c) =>
                                                form.setData(
                                                    'required_tools',
                                                    c ? [...form.data.required_tools, k] : form.data.required_tools.filter((x) => x !== k),
                                                )
                                            }
                                        />
                                        {k}
                                    </label>
                                ))}
                            </fieldset>
                            {form.errors.required_tools && <div className="text-tone-danger text-xs">{form.errors.required_tools}</div>}
                            <div>
                                <Button type="submit" disabled={form.processing}>
                                    {t('Propose a skill')}
                                </Button>
                            </div>
                        </form>
                    </DialogContent>
                </Dialog>
            </div>
        </AppLayout>
    );
}
