import { useTn } from '@/lib/i18n';
import { type ReactNode } from 'react';

/**
 * The first thing on every screen: what this is, one sentence on what it is for, and the one or two
 * actions that belong to the whole page (New job, a filter). Actions on a single row live on the row.
 */
export function PageHeader({
    title,
    description,
    actions,
    eyebrow,
    children,
}: {
    title: ReactNode;
    description?: ReactNode;
    actions?: ReactNode;
    /** docs/135 (h): a small-caps line above the title, for the group or the parent a page belongs to. */
    eyebrow?: ReactNode;
    children?: ReactNode;
}) {
    const tn = useTn();
    return (
        <header className="flex flex-col gap-3">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0 flex-1 basis-72">
                    {eyebrow && <div className="hq-eyebrow mb-1">{tn(eyebrow)}</div>}
                    <h1 className="text-[1.375rem] leading-tight font-semibold tracking-tight break-words">{tn(title)}</h1>
                    {description && <p className="text-muted-foreground mt-1.5 max-w-3xl text-sm leading-relaxed">{tn(description)}</p>}
                </div>
                {actions && <div className="flex max-w-full flex-wrap items-center gap-2">{actions}</div>}
            </div>
            {children && <div className="flex max-w-full flex-col gap-3">{children}</div>}
        </header>
    );
}

/** A titled block on a page: heading on the left, a small action or count on the right. */
export function SectionHeader({ title, aside }: { title: ReactNode; aside?: ReactNode }) {
    const tn = useTn();
    return (
        <div className="flex flex-wrap items-center justify-between gap-2">
            <h2 className="text-[0.9375rem] font-semibold tracking-tight">{tn(title)}</h2>
            {aside && <div className="text-muted-foreground flex flex-wrap items-center gap-2 text-sm">{tn(aside)}</div>}
        </div>
    );
}

/** A folded block for the raw material: JSON, schemas, digests. Closed by default so it is never the first thing read. */
export function TechnicalDetail({
    summary = 'Technical detail',
    children,
    open = false,
}: {
    summary?: ReactNode;
    children: ReactNode;
    open?: boolean;
}) {
    const tn = useTn();
    return (
        <details className="group text-sm" open={open}>
            <summary className="text-muted-foreground hover:text-foreground touch:min-h-11 inline-flex min-h-8 cursor-pointer items-center rounded-md text-xs select-none">
                {tn(summary)}
            </summary>
            <div className="mt-2">{children}</div>
        </details>
    );
}

/** JSON the way an operator can stand to look at it: small, scrollable, never wider than its box. */
export function JsonBlock({ value, className = '' }: { value: unknown; className?: string }) {
    return (
        <pre
            className={`bg-muted border-glass-border max-h-64 max-w-full overflow-auto rounded-lg border p-3 font-mono text-[11px] leading-relaxed ${className}`}
        >
            {JSON.stringify(value ?? null, null, 2)}
        </pre>
    );
}
