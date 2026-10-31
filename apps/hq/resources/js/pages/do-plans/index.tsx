import { EmptyState } from '@/components/empty-state';
import { Field, FormRow, NativeSelect } from '@/components/form/field';
import { PageHeader } from '@/components/page-header';
import { type ListMeta, Pagination } from '@/components/pagination';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Notice } from '@/components/ui/notice';
import { StatusBadge, type Tone } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

/**
 * "Do" mode plans (docs/143 A). A plan is steps that name a tool; a named person starts it, and every step that has an effect
 * still waits for its own approval in Approvals. Nothing here runs a tool: the form only proposes.
 */

type Plan = {
    uuid: string;
    title: string;
    state: string;
    business: string | null;
    proposer_kind: string;
    agent_key: string | null;
    proposed_by: string | null;
    started_by: string | null;
    steps_count: number;
    created_at: string | null;
};
type Props = {
    enabled: boolean;
    plans: Plan[];
    pagination: ListMeta;
    businesses: { id: number; name: string }[];
    tools: { key: string; title: string; tier: string; effectful: boolean }[];
    max_steps: number;
    can: { propose: boolean; start: boolean };
};
type StepDraft = { tool_key: string; summary: string; args: string };

export const PLAN_TONES: Record<string, Tone> = { proposed: 'pending', running: 'info', completed: 'ok', halted: 'warn', cancelled: 'neutral' };

export function usePlanStateLabel() {
    const t = useT();

    return (s: string) =>
        ({ proposed: t('Proposed'), running: t('Running'), completed: t('Completed'), halted: t('Stopped'), cancelled: t('Cancelled') })[s] ?? s;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Do plans', href: '/do-plans' }];

export default function DoPlansIndex({ enabled, plans, pagination, businesses, tools, max_steps, can }: Props) {
    const t = useT();
    const stateLabel = usePlanStateLabel();
    const { flash, errors } = usePage<{ flash?: { status?: string }; errors: Record<string, string> }>().props;
    const [open, setOpen] = useState(false);
    const [businessId, setBusinessId] = useState(businesses[0]?.id ? String(businesses[0].id) : '');
    const [title, setTitle] = useState('');
    const [steps, setSteps] = useState<StepDraft[]>([{ tool_key: tools[0]?.key ?? '', summary: '', args: '{}' }]);
    const [argError, setArgError] = useState<string | null>(null);

    const update = (i: number, patch: Partial<StepDraft>) => setSteps((all) => all.map((s, j) => (j === i ? { ...s, ...patch } : s)));
    const submit = () => {
        try {
            const body = steps.map((s) => ({ tool_key: s.tool_key, summary: s.summary, args: JSON.parse(s.args || '{}') }));
            setArgError(null);
            router.post('/do-plans', { business_id: businessId, title, steps: body }, { onSuccess: () => setOpen(false) });
        } catch {
            setArgError(t('The arguments of a step must be valid JSON, for example {"note": "x"}.'));
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Do plans')} />
            <div className="flex max-w-5xl flex-col gap-5 p-4 md:p-6">
                <PageHeader
                    title={t('Do plans')}
                    description={t(
                        'A plan is a short list of steps. Nothing runs until a person starts it, and each step that changes something waits for its own approval.',
                    )}
                    actions={
                        enabled && can.propose ? (
                            <Button size="sm" onClick={() => setOpen(true)}>
                                {t('Propose a plan')}
                            </Button>
                        ) : undefined
                    }
                />

                {flash?.status === 'do-plan-proposed' && <Notice tone="ok">{t('Plan proposed. Nothing has run.')}</Notice>}

                {!enabled && (
                    <EmptyState
                        variant="inert"
                        title={t('Do mode is switched off')}
                        reason={t('It is switched off on purpose until its activation ruling.')}
                        gate={t('a person with group-wide access switches the console.do_mode flag on')}
                    />
                )}

                {enabled && plans.length === 0 && <EmptyState title={t('No plans yet')} description={t('A plan you propose appears here.')} />}

                <ul className="flex flex-col gap-2">
                    {plans.map((p) => (
                        <li key={p.uuid}>
                            <Link
                                href={`/do-plans/${p.uuid}`}
                                className="hq-glass hover:bg-accent flex flex-wrap items-center justify-between gap-3 rounded-2xl px-4 py-3"
                            >
                                <span className="flex min-w-0 flex-col gap-0.5">
                                    <span className="font-medium">{p.title}</span>
                                    <span className="text-muted-foreground text-xs">
                                        {[p.business, t('{count} steps', { count: p.steps_count }), p.proposed_by, formatDateTime(p.created_at)]
                                            .filter(Boolean)
                                            .join(', ')}
                                    </span>
                                </span>
                                <StatusBadge tone={PLAN_TONES[p.state] ?? 'neutral'}>{stateLabel(p.state)}</StatusBadge>
                            </Link>
                        </li>
                    ))}
                </ul>
                <Pagination meta={pagination} />
            </div>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>{t('Propose a plan')}</DialogTitle>
                        <DialogDescription>
                            {t('Steps run one at a time, in this order. A step that changes something is approved by a person.')}
                        </DialogDescription>
                    </DialogHeader>
                    {errors.plan && (
                        <Notice tone="danger" alert>
                            {errors.plan}
                        </Notice>
                    )}
                    {argError && (
                        <Notice tone="danger" alert>
                            {argError}
                        </Notice>
                    )}
                    <FormRow>
                        <Field label={t('Business')} htmlFor="plan-business" size="md">
                            <NativeSelect id="plan-business" value={businessId} onChange={(e) => setBusinessId(e.target.value)}>
                                {businesses.map((b) => (
                                    <option key={b.id} value={b.id}>
                                        {b.name}
                                    </option>
                                ))}
                            </NativeSelect>
                        </Field>
                        <Field label={t('Title')} htmlFor="plan-title" size="grow">
                            <Input id="plan-title" value={title} maxLength={200} onChange={(e) => setTitle(e.target.value)} />
                        </Field>
                    </FormRow>
                    {steps.map((s, i) => (
                        <div key={i} className="hq-glass flex flex-col gap-3 rounded-xl p-3">
                            <FormRow>
                                <Field label={t('Step {n}: tool', { n: i + 1 })} htmlFor={`step-tool-${i}`} size="lg">
                                    <NativeSelect id={`step-tool-${i}`} value={s.tool_key} onChange={(e) => update(i, { tool_key: e.target.value })}>
                                        {tools.map((tool) => (
                                            <option key={tool.key} value={tool.key}>
                                                {tool.title}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </Field>
                                <Field label={t('What it is for')} htmlFor={`step-summary-${i}`} size="grow">
                                    <Input
                                        id={`step-summary-${i}`}
                                        value={s.summary}
                                        maxLength={300}
                                        onChange={(e) => update(i, { summary: e.target.value })}
                                    />
                                </Field>
                            </FormRow>
                            <Field label={t('Arguments (JSON)')} htmlFor={`step-args-${i}`} size="grow">
                                <Input id={`step-args-${i}`} value={s.args} onChange={(e) => update(i, { args: e.target.value })} />
                            </Field>
                        </div>
                    ))}
                    <div className="flex flex-wrap gap-2">
                        {steps.length < max_steps && (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => setSteps((all) => [...all, { tool_key: tools[0]?.key ?? '', summary: '', args: '{}' }])}
                            >
                                {t('Add a step')}
                            </Button>
                        )}
                        <Button size="sm" onClick={submit} disabled={title.trim() === '' || steps.some((s) => s.summary.trim() === '')}>
                            {t('Propose')}
                        </Button>
                    </div>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
