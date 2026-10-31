import { EmptyState } from '@/components/empty-state';
import { PageHeader, SectionHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Notice } from '@/components/ui/notice';
import { StatusBadge, type Tone } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { PLAN_TONES, usePlanStateLabel } from './index';

/**
 * One Do plan (docs/143 A): its steps, where each stands, and the approval each step is waiting on. Starting a plan
 * approves nothing; the approval itself is decided on its own page in Approvals.
 */

type Step = {
    seq: number;
    tool_key: string;
    summary: string;
    state: string;
    effectful: boolean;
    args: Record<string, unknown>;
    approval_id: number | null;
    approval_status: string | null;
    reason: string | null;
};
type Plan = {
    uuid: string;
    title: string;
    state: string;
    business: string | null;
    proposed_by: string | null;
    started_by: string | null;
    proposer_kind: string;
    agent_key: string | null;
    created_at: string | null;
    finished_at: string | null;
    halt_reason: string | null;
    steps: Step[];
};

const STEP_TONES: Record<string, Tone> = {
    pending: 'neutral',
    awaiting_approval: 'pending',
    succeeded: 'ok',
    failed: 'danger',
    rejected: 'warn',
    skipped: 'neutral',
};

export default function DoPlanShow({ enabled, plan, can }: { enabled: boolean; plan: Plan; can: { start: boolean } }) {
    const t = useT();
    const stateLabel = usePlanStateLabel();
    const { flash, errors } = usePage<{ flash?: { status?: string }; errors: Record<string, string> }>().props;
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Do plans', href: '/do-plans' },
        { title: plan.title, href: `/do-plans/${plan.uuid}` },
    ];
    const stepLabel = (s: string) =>
        ({
            pending: t('Waiting its turn'),
            awaiting_approval: t('Waiting for a person to approve'),
            succeeded: t('Done'),
            failed: t('Failed'),
            rejected: t('Not approved'),
            skipped: t('Skipped'),
        })[s] ?? s;
    const post = (action: string) => router.post(`/do-plans/${plan.uuid}/${action}`, {}, { preserveScroll: true });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={plan.title} />
            <div className="flex max-w-4xl flex-col gap-5 p-4 md:p-6">
                <PageHeader
                    title={plan.title}
                    description={[
                        plan.business,
                        plan.agent_key ? t('Proposed by the {agent} agent', { agent: plan.agent_key }) : plan.proposed_by,
                        plan.started_by ? t('Started by {person}', { person: plan.started_by }) : null,
                        formatDateTime(plan.created_at),
                    ]
                        .filter(Boolean)
                        .join(', ')}
                    actions={
                        <>
                            <StatusBadge tone={PLAN_TONES[plan.state] ?? 'neutral'}>{stateLabel(plan.state)}</StatusBadge>
                            {can.start && plan.state === 'proposed' && enabled && (
                                <Button size="sm" onClick={() => post('start')}>
                                    {t('Start the plan')}
                                </Button>
                            )}
                            {can.start && plan.state === 'running' && (
                                <Button size="sm" variant="outline" onClick={() => post('advance')}>
                                    {t('Continue to the next step')}
                                </Button>
                            )}
                            {can.start && ['proposed', 'running'].includes(plan.state) && (
                                <Button size="sm" variant="ghost" onClick={() => post('cancel')}>
                                    {t('Cancel the plan')}
                                </Button>
                            )}
                        </>
                    }
                />

                {errors.plan && (
                    <Notice tone="danger" alert>
                        {errors.plan}
                    </Notice>
                )}
                {flash?.status === 'do-plan-started' && (
                    <Notice tone="ok">{t('Plan started. Each step that changes something now waits for its approval.')}</Notice>
                )}
                {!enabled && (
                    <EmptyState
                        variant="inert"
                        title={t('Do mode is switched off')}
                        reason={t('It is switched off on purpose until its activation ruling.')}
                        gate={t('a person with group-wide access switches the console.do_mode flag on')}
                    />
                )}
                {plan.halt_reason && <Notice tone="warn">{plan.halt_reason}</Notice>}

                <SectionHeader title={t('Steps')} />
                <ol className="flex flex-col gap-2">
                    {plan.steps.map((s) => (
                        <li key={s.seq} className="hq-glass flex flex-col gap-2 rounded-2xl p-4">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <span className="font-medium">
                                    {s.seq}. {s.summary}
                                </span>
                                <StatusBadge tone={STEP_TONES[s.state] ?? 'neutral'}>{stepLabel(s.state)}</StatusBadge>
                            </div>
                            <div className="text-muted-foreground text-xs">
                                {s.tool_key}
                                {s.effectful ? `, ${t('changes something, needs approval')}` : `, ${t('only reads')}`}
                            </div>
                            <code className="bg-muted/60 rounded-md px-2 py-1 text-xs break-all">{JSON.stringify(s.args)}</code>
                            {s.reason && <div className="text-sm">{s.reason}</div>}
                            {s.approval_id !== null && (
                                <Link href={`/approvals/${s.approval_id}`} className="text-hq-violet text-sm hover:underline">
                                    {t('Open the approval')}
                                </Link>
                            )}
                        </li>
                    ))}
                </ol>
            </div>
        </AppLayout>
    );
}
