import { useTn } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import * as React from 'react';

/**
 * docs/135 (h), W-UI3: the glass card every screen puts its blocks on (18 px radius, the theme's glass tint, a
 * hairline and a soft raised shadow). One component so no screen keeps its own copy of a radius or a border.
 */
export function Surface({
    as: Tag = 'div',
    className,
    ...props
}: React.HTMLAttributes<HTMLElement> & { as?: 'div' | 'section' | 'article' | 'aside' | 'li' }) {
    return <Tag className={cn('hq-card text-card-foreground', className)} {...props} />;
}

/**
 * Every table lives in one of these: a glass box that scrolls sideways on a phone and downwards on a long list,
 * with the header row staying in view (styles in app.css, `.hq-table-box`). The table itself keeps its markup.
 */
export function TableBox({ className, children, label }: { className?: string; children: React.ReactNode; label?: string }) {
    return (
        <div className={cn('hq-table-box', className)} role={label ? 'region' : undefined} aria-label={label} tabIndex={label ? 0 : undefined}>
            {children}
        </div>
    );
}

/**
 * Label and value pairs, the way the artboards set them: a quiet label column and a value column that wraps.
 * Labels are translated; values are shown as given. On a phone the two columns stay, the value column shrinks.
 */
export function DefinitionList({
    items,
    className,
}: {
    items: { label: string; value: React.ReactNode; mono?: boolean }[];
    className?: string;
}) {
    const tn = useTn();
    return (
        <dl className={cn('grid grid-cols-[minmax(5.5rem,max-content)_minmax(0,1fr)] gap-x-4 gap-y-2 text-sm', className)}>
            {items.map((item) => (
                <div key={item.label} className="contents">
                    <dt className="text-muted-foreground">{tn(item.label)}</dt>
                    <dd className={cn('min-w-0 break-words', item.mono && 'font-mono text-[0.8125rem]')}>{item.value ?? '-'}</dd>
                </div>
            ))}
        </dl>
    );
}
