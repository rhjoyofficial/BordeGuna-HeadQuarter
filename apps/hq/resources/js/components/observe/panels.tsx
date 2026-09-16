import { SectionHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
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
    return (
        <div className="flex flex-wrap items-center gap-2" role="group" aria-label="Period">
            {period.options.map((key) => (
                <Button
                    key={key}
                    size="sm"
                    variant={period.key === key ? 'default' : 'outline'}
                    onClick={() => router.get(path, { window: key }, { preserveScroll: true, replace: true })}
                >
                    {WINDOW_LABELS[key] ?? key}
                </Button>
            ))}
        </div>
    );
}

export function Stat({ label, value, detail, badge }: { label: string; value: ReactNode; detail?: ReactNode; badge?: ReactNode }) {
    return (
        <div className="bg-card flex min-w-0 flex-col gap-1 rounded-xl border p-4">
            <div className="text-muted-foreground text-xs tracking-wide uppercase">{label}</div>
            <div className="flex flex-wrap items-center gap-2 text-2xl font-semibold tabular-nums">
                {value}
                {badge}
            </div>
            {detail && <div className="text-muted-foreground text-xs">{detail}</div>}
        </div>
    );
}

export function StatGrid({ children }: { children: ReactNode }) {
    return <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">{children}</div>;
}

export function Panel({ title, aside, children, className }: { title: ReactNode; aside?: ReactNode; children: ReactNode; className?: string }) {
    return (
        <section className={cn('flex min-w-0 flex-col gap-3', className)}>
            <SectionHeader title={title} aside={aside} />
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
    if (rows.length === 0) {
        return <div className="text-muted-foreground rounded-xl border border-dashed px-4 py-6 text-center text-sm">{empty}</div>;
    }

    return (
        <div className="bg-card overflow-x-auto rounded-xl border">
            <table className="w-full text-sm">
                <thead className="bg-muted/50 text-muted-foreground text-left text-xs">
                    <tr>
                        {columns.map((c) => (
                            <th key={c.key} className={cn('px-3 py-2 font-medium whitespace-nowrap', c.align === 'right' && 'text-right')}>
                                {c.label}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row, i) => (
                        <tr key={rowKey(row, i)} className="border-t align-top">
                            {columns.map((c) => (
                                <td key={c.key} className={cn('px-3 py-2', c.align === 'right' && 'text-right tabular-nums')}>
                                    {c.render(row)}
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

/** A proportion as a thin bar, for a column of counts or amounts. */
export function Bar({ value, max }: { value: number; max: number }) {
    const width = max > 0 ? Math.max(2, Math.round((100 * value) / max)) : 0;
    return (
        <div className="bg-muted h-1.5 w-24 overflow-hidden rounded-full" aria-hidden>
            <div className="bg-primary h-full rounded-full" style={{ width: `${width}%` }} />
        </div>
    );
}

export function Note({ children }: { children: ReactNode }) {
    return <p className="text-muted-foreground max-w-3xl text-xs">{children}</p>;
}

/** A value that may be missing, shown as a dash rather than a zero that means something else. */
export function orDash(value: ReactNode | null | undefined): ReactNode {
    return value === null || value === undefined || value === '' ? '-' : value;
}
