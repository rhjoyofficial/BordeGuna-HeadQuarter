import { type ReactNode } from 'react';

/**
 * The first thing on every screen: what this is, one sentence on what it is for, and the one or two
 * actions that belong to the whole page (New job, a filter). Actions on a single row live on the row.
 */
export function PageHeader({
    title,
    description,
    actions,
    children,
}: {
    title: ReactNode;
    description?: ReactNode;
    actions?: ReactNode;
    children?: ReactNode;
}) {
    return (
        <div className="flex flex-col gap-3">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <h1 className="text-xl font-semibold tracking-tight">{title}</h1>
                    {description && <p className="text-muted-foreground mt-1 max-w-3xl text-sm">{description}</p>}
                </div>
                {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
            </div>
            {children}
        </div>
    );
}

/** A titled block on a page: heading on the left, a small action or count on the right. */
export function SectionHeader({ title, aside }: { title: ReactNode; aside?: ReactNode }) {
    return (
        <div className="flex flex-wrap items-center justify-between gap-2">
            <h2 className="text-base font-semibold">{title}</h2>
            {aside && <div className="text-muted-foreground flex flex-wrap items-center gap-2 text-sm">{aside}</div>}
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
    return (
        <details className="group text-sm" open={open}>
            <summary className="text-muted-foreground hover:text-foreground cursor-pointer text-xs select-none">{summary}</summary>
            <div className="mt-2">{children}</div>
        </details>
    );
}

/** JSON the way an operator can stand to look at it: small, scrollable, never wider than its box. */
export function JsonBlock({ value, className = '' }: { value: unknown; className?: string }) {
    return (
        <pre className={`bg-muted/40 max-h-64 overflow-auto rounded-md p-2 font-mono text-[11px] leading-relaxed ${className}`}>
            {JSON.stringify(value ?? null, null, 2)}
        </pre>
    );
}
