import InputError from '@/components/input-error';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { type ReactNode } from 'react';

/**
 * One labelled control in a form row, as wide as what it holds. Ported from the ERP.
 *
 * Joy's standing rule (30 Aug and 3 Sep 2026): a control is as wide as its content, never as wide as
 * its column; only a textarea takes a whole row. A grid decides width by column; this decides it by
 * content and lets the row wrap. Pick the size by what the value looks like:
 *   xs   a quantity, a retry count                          w-24
 *   sm   a code, an amount, a cron expression               w-36
 *   md   a name, a timezone                                  w-48
 *   lg   a picker, an email address, a provider              w-64
 *   grow the one field that should take what is left         flex-1 min-w-56
 *   auto the control sizes itself (dates, fitted selects)    nothing added
 */
const SIZES = {
    xs: 'w-24',
    sm: 'w-36',
    md: 'w-48',
    lg: 'w-64',
    grow: 'min-w-56 flex-1',
    auto: '',
} as const;

export type FieldSize = keyof typeof SIZES;

export function Field({
    label,
    htmlFor,
    size = 'auto',
    error,
    hint,
    className,
    children,
}: {
    label: ReactNode;
    htmlFor?: string;
    size?: FieldSize;
    error?: string;
    hint?: ReactNode;
    className?: string;
    children: ReactNode;
}) {
    return (
        <div className={cn('grid content-start gap-1.5', SIZES[size], className)}>
            <Label htmlFor={htmlFor}>{label}</Label>
            {children}
            {hint ? <p className="text-muted-foreground text-xs">{hint}</p> : null}
            <InputError message={error} />
        </div>
    );
}

/** A row of Fields that wraps when the screen runs out. Tops aligned, so a hint hangs lower without lifting its input. */
export function FormRow({ children, className }: { children: ReactNode; className?: string }) {
    return <div className={cn('flex flex-wrap items-start gap-4', className)}>{children}</div>;
}

/** The plain `<select>` the starter kit uses, sized to its content rather than its column. */
export function NativeSelect({ className, children, ...props }: React.ComponentProps<'select'>) {
    return (
        <select
            className={cn(
                'hq-field border-input focus-visible:ring-ring touch:h-11 [&>option]:bg-popover [&>option]:text-popover-foreground h-10 w-auto max-w-full rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden',
                className,
            )}
            {...props}
        >
            {children}
        </select>
    );
}
