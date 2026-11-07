import * as React from 'react';

import { cn } from '@/lib/utils';

/** docs/135 (h), W-UI3: the one multi-line box. On the field token so it reads on glass; a textarea takes its whole row. */
const Textarea = React.forwardRef<HTMLTextAreaElement, React.ComponentProps<'textarea'>>(({ className, ...props }, ref) => (
    <textarea
        ref={ref}
        className={cn(
            'hq-field border-input placeholder:text-muted-foreground focus-visible:ring-ring flex min-h-20 w-full rounded-md border px-3 py-2 text-base transition-colors focus-visible:ring-2 focus-visible:outline-hidden disabled:cursor-not-allowed disabled:opacity-50 md:text-sm',
            className,
        )}
        {...props}
    />
));
Textarea.displayName = 'Textarea';

export { Textarea };
