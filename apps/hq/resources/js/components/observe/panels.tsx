import { SectionHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { TableBox } from '@/components/ui/surface';
import { useT, useTn } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import { type ReactNode } from 'react';

/**
 * The small building blocks the four dashboards and the alert page share (docs/48 N.3). Read-only by
 * design: nothing here posts anything; the only interaction is choosing the window a page reads.
 */

export type DashboardWindow = { key: string; since: string; until: string; options: string[] };

const WINDOW_LABELS: Record<string, string> = { '24h': 'Last 24 hours', '7d': 'Last 7 days', '30d': 'Last 30 days' };

export function WindowPicker({ period, path }: { period: DashboardWindow; path: string }) {
    const t = useT();
    return (
        <div className="flex flex-wrap items-center gap-2" role="group" aria-label={t('Period')}>
            {period.options.map((key) => (
                <Button
                    key={key}
                    size="sm"
                    variant={period.key === key ? 'default' : 'outline'}
                    onClick={() => router.get(path, { window: key }, { preserveScroll: true, replace: true })}
                >
                    {t(WINDOW_LABELS[key] ?? key)}
                </Button>
            ))}
        </div>
    );
}

export function Stat({ label, value, detail, badge }: { label: string; value: ReactNode; detail?: ReactNode; badge?: ReactNode }) {
    const t = useT();
    const tn = useTn();
    return (
        <div className="hq-card flex min-w-0 flex-col gap-1.5 p-4">
            <div className="hq-eyebrow">{t(label)}</div>
            <div className="flex flex-wrap items-center gap-2 text-[1.625rem] leading-tight font-semibold tabular-nums">
                {value}
                {badge}
            </div>
            {detail && <div className="text-muted-foreground text-xs">{tn(detail)}</div>}
        </div>
    );
}

export function StatGrid({ children }: { children: ReactNode }) {
    return <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">{children}</div>;
}

export function Panel({ title, aside, children, className }: { title: ReactNode; aside?: ReactNode; children: ReactNode; className?: string }) {
    const tn = useTn();
    return (
        <section className={cn('flex min-w-0 flex-col gap-3', className)}>
            <SectionHeader title={tn(title)} aside={tn(aside)} />
            {children}
        </section>
    );
}

export type Column<T> = { key: string; label: string; align?: 'left' | 'right'; render: (row: T) => ReactNode };

export function DataTable<T>({
    columns,
    rows,
    empty,
    rowKey,
}: {
    columns: Column<T>[];
    rows: T[];
    empty: ReactNode;
    rowKey: (row: T, index: number) => string;
}) {
    const t = useT();
    const tn = useTn();
    if (rows.length === 0) {
        return (
            <div className="text-muted-foreground border-glass-border bg-card/40 rounded-xl border border-dashed px-4 py-6 text-center text-sm">
                {tn(empty)}
            </div>
        );
    }

    return (
        <TableBox>
            <table className="w-full text-sm">
                <thead>
                    <tr>
                        {columns.map((c) => (
                            <th key={c.key} className={cn('px-3 py-2.5 whitespace-nowrap', c.align === 'right' && 'text-right')}>
                                {t(c.label)}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row, i) => (
                        <tr key={rowKey(row, i)} className="align-top">
                            {columns.map((c) => (
                                <td key={c.key} className={cn('px-3 py-2.5', c.align === 'right' && 'text-right tabular-nums')}>
                                    {c.render(row)}
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </TableBox>
    );
}

/** A proportion as a thin bar, for a column of counts or amounts. */
export function Bar({ value, max }: { value: number; max: number }) {
    const width = max > 0 ? Math.max(2, Math.round((100 * value) / max)) : 0;
    return (
        <div className="bg-muted h-1.5 w-24 overflow-hidden rounded-full" aria-hidden>
            <div className="bg-hq-violet h-full rounded-full" style={{ width: `${width}%` }} />
        </div>
    );
}

export function Note({ children }: { children: ReactNode }) {
    const tn = useTn();
    return <p className="text-muted-foreground max-w-3xl text-xs">{tn(children)}</p>;
}

/** A value that may be missing, shown as a dash rather than a zero that means something else. */
export function orDash(value: ReactNode | null | undefined): ReactNode {
    return value === null || value === undefined || value === '' ? '-' : value;
}
