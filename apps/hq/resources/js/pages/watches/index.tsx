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
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

/**
 * Event watches (docs/143 F). A watch looks for one kind of event in one business and, at most, PROPOSES an action to the
 * Approval Center. It is proposed here, enabled by a named person, and never acts by itself.
 */

type Watch = {
    uuid: string;
    name: string;
    business: string | null;
    event_type: string;
    conditions: { path: string; op: string; value: string | number | boolean }[];
    action: { tool_key: string } | null;
    state: string;
    max_per_hour: number;
    max_per_day: number;
    fire_count: number;
    last_fired_at: string | null;
    proposer_kind: string;
    note: string | null;
};
type Props = {
    enabled: boolean;
    watches: Watch[];
    pagination: ListMeta;
    businesses: { id: number; name: string }[];
    ops: string[];
    limits: { per_hour: number; per_day: number; conditions: number };
    can: { propose: boolean; enable: boolean };
};

const TONES: Record<string, Tone> = { proposed: 'pending', enabled: 'ok', disabled: 'neutral', rejected: 'warn' };
const breadcrumbs: BreadcrumbItem[] = [{ title: 'Event watches', href: '/watches' }];

export default function WatchesIndex({ enabled, watches, pagination, businesses, ops, limits, can }: Props) {
    const t = useT();
    const { flash, errors } = usePage<{ flash?: { status?: string }; errors: Record<string, string> }>().props;
    const [open, setOpen] = useState(false);
    const [form, setForm] = useState({
        business_id: businesses[0]?.id ? String(businesses[0].id) : '',
        name: '',
        event_type: '',
        path: '',
        op: 'gte',
        value: '',
        per_hour: '5',
        per_day: '20',
    });
    const set = (patch: Partial<typeof form>) => setForm((f) => ({ ...f, ...patch }));
    const stateLabel = (s: string) => ({ proposed: t('Proposed'), enabled: t('Watching'), disabled: t('Paused'), rejected: t('Rejected') })[s] ?? s;
    const opLabel = (o: string) =>
        ({
            eq: t('equals'),
            neq: t('differs from'),
            gt: t('is above'),
            gte: t('is at least'),
            lt: t('is below'),
            lte: t('is at most'),
            contains: t('contains'),
        })[o] ?? o;
    const flashText = {
        'watch-proposed': t('Watch proposed. It does nothing until a person enables it.'),
        'watch-enabled': t('Watch enabled. It can only propose, never act.'),
        'watch-disabled': t('Watch paused.'),
        'watch-rejected': t('Watch rejected.'),
    }[flash?.status ?? ''];
    const act = (uuid: string, action: string) => router.post(`/watches/${uuid}/${action}`, {}, { preserveScroll: true });
    const submit = () =>
        router.post(
            '/watches',
            {
                business_id: form.business_id,
                name: form.name,
                event_type: form.event_type,
                conditions: form.path ? [{ path: form.path, op: form.op, value: form.value }] : [],
                max_per_hour: form.per_hour,
                max_per_day: form.per_day,
            },
            { preserveScroll: true, onSuccess: () => setOpen(false) },
        );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Event watches')} />
            <div className="flex max-w-5xl flex-col gap-5 p-4 md:p-6">
                <PageHeader
                    title={t('Event watches')}
                    description={t(
                        'A watch looks for one kind of event in one business. It can notify you or propose an action for approval; it never acts on its own.',
                    )}
                    actions={
                        enabled && can.propose ? (
                            <Button size="sm" onClick={() => setOpen(true)}>
                                {t('Propose a watch')}
                            </Button>
                        ) : undefined
                    }
                />
                {flashText && <Notice tone="ok">{flashText}</Notice>}
                {errors.watch && (
                    <Notice tone="danger" alert>
                        {errors.watch}
                    </Notice>
                )}
                {!enabled && (
                    <EmptyState
                        variant="inert"
                        title={t('Event watches are switched off')}
                        reason={t('Nothing watches events until this is switched on.')}
                        gate={t('a person with group-wide access switches the console.event_watches flag on')}
                    />
                )}
                {enabled && watches.length === 0 && <EmptyState title={t('No watches yet')} description={t('A watch you propose appears here.')} />}

                <ul className="flex flex-col gap-2">
                    {watches.map((w) => (
                        <li key={w.uuid} className="hq-glass flex flex-col gap-2 rounded-2xl p-4">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <span className="font-medium">{w.name}</span>
                                <StatusBadge tone={TONES[w.state] ?? 'neutral'}>{stateLabel(w.state)}</StatusBadge>
                            </div>
                            <div className="text-muted-foreground text-sm">
                                {[w.business, w.event_type, ...w.conditions.map((c) => `${c.path} ${opLabel(c.op)} ${c.value}`)]
                                    .filter(Boolean)
                                    .join(', ')}
                            </div>
                            <div className="text-muted-foreground text-xs">
                                {w.action ? t('Proposes {tool} for approval', { tool: w.action.tool_key }) : t('Notifies only')}
                                {', '}
                                {t('at most {hour} an hour, {day} a day', { hour: w.max_per_hour, day: w.max_per_day })}
                                {', '}
                                {t('fired {count} times', { count: w.fire_count })}
                                {w.last_fired_at ? `, ${formatDateTime(w.last_fired_at)}` : ''}
                            </div>
                            {can.enable && enabled && (
                                <div className="flex flex-wrap gap-2">
                                    {['proposed', 'disabled'].includes(w.state) && (
                                        <Button size="sm" onClick={() => act(w.uuid, 'enable')}>
                                            {t('Enable')}
                                        </Button>
                                    )}
                                    {w.state === 'enabled' && (
                                        <Button size="sm" variant="outline" onClick={() => act(w.uuid, 'disable')}>
                                            {t('Pause')}
                                        </Button>
                                    )}
                                    {w.state === 'proposed' && (
                                        <Button size="sm" variant="ghost" onClick={() => act(w.uuid, 'reject')}>
                                            {t('Reject')}
                                        </Button>
                                    )}
                                </div>
                            )}
                        </li>
                    ))}
                </ul>
                <Pagination meta={pagination} />
            </div>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                    <DialogHeader>
                        <DialogTitle>{t('Propose a watch')}</DialogTitle>
                        <DialogDescription>
                            {t('Pick one business and one kind of event, and say when it counts. You enable it afterwards.')}
                        </DialogDescription>
                    </DialogHeader>
                    <FormRow>
                        <Field label={t('Business')} htmlFor="watch-business" size="md">
                            <NativeSelect id="watch-business" value={form.business_id} onChange={(e) => set({ business_id: e.target.value })}>
                                {businesses.map((b) => (
                                    <option key={b.id} value={b.id}>
                                        {b.name}
                                    </option>
                                ))}
                            </NativeSelect>
                        </Field>
                        <Field label={t('Name')} htmlFor="watch-name" size="grow">
                            <Input id="watch-name" value={form.name} maxLength={160} onChange={(e) => set({ name: e.target.value })} />
                        </Field>
                    </FormRow>
                    <FormRow>
                        <Field label={t('Event type')} htmlFor="watch-event" size="md" hint={t('For example order.created')}>
                            <Input id="watch-event" value={form.event_type} onChange={(e) => set({ event_type: e.target.value })} />
                        </Field>
                        <Field label={t('Field')} htmlFor="watch-path" size="sm">
                            <Input id="watch-path" value={form.path} onChange={(e) => set({ path: e.target.value })} />
                        </Field>
                        <Field label={t('Condition')} htmlFor="watch-op" size="sm">
                            <NativeSelect id="watch-op" value={form.op} onChange={(e) => set({ op: e.target.value })}>
                                {ops.map((o) => (
                                    <option key={o} value={o}>
                                        {opLabel(o)}
                                    </option>
                                ))}
                            </NativeSelect>
                        </Field>
                        <Field label={t('Value')} htmlFor="watch-value" size="sm">
                            <Input id="watch-value" value={form.value} onChange={(e) => set({ value: e.target.value })} />
                        </Field>
                    </FormRow>
                    <FormRow>
                        <Field label={t('At most per hour')} htmlFor="watch-hour" size="xs" hint={t('Up to {max}', { max: limits.per_hour })}>
                            <Input
                                id="watch-hour"
                                type="number"
                                min={1}
                                max={limits.per_hour}
                                value={form.per_hour}
                                onChange={(e) => set({ per_hour: e.target.value })}
                            />
                        </Field>
                        <Field label={t('At most per day')} htmlFor="watch-day" size="xs" hint={t('Up to {max}', { max: limits.per_day })}>
                            <Input
                                id="watch-day"
                                type="number"
                                min={1}
                                max={limits.per_day}
                                value={form.per_day}
                                onChange={(e) => set({ per_day: e.target.value })}
                            />
                        </Field>
                    </FormRow>
                    <Button size="sm" className="self-start" onClick={submit} disabled={form.name.trim() === '' || form.event_type.trim() === ''}>
                        {t('Propose')}
                    </Button>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
