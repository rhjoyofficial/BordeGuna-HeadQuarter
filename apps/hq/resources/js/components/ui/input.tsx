import * as React from 'react';

import { cn } from '@/lib/utils';

/**
 * A date or time box is as wide as a date; a number box is as wide as a number (Joy, 30 Aug and
 * 3 Sep 2026, on the ERP). The defaults live here so no screen has to remember the rule, and a
 * `Field` wrapper can still narrow or widen either one.
 */
const WIDTH_BY_TYPE: Record<string, string> = {
    date: 'w-auto min-w-0',
    'datetime-local': 'w-auto min-w-0',
    time: 'w-auto min-w-0',
    month: 'w-auto min-w-0',
    number: 'w-36 max-w-full',
};

const Input = React.forwardRef<HTMLInputElement, React.ComponentProps<'input'>>(({ className, type, ...props }, ref) => {
    return (
        <input
            type={type}
            className={cn(
                'flex h-10 rounded-md border border-input bg-background px-3 py-2 text-base ring-offset-background file:border-0 file:bg-transparent file:text-sm file:font-medium file:text-foreground placeholder:text-muted-foreground focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 md:text-sm',
                WIDTH_BY_TYPE[type ?? ''] ?? 'w-full',
                className,
            )}
            ref={ref}
            {...props}
        />
    );
});

Input.displayName = 'Input';

export { Input };
