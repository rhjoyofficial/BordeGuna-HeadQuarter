import { cn } from '@/lib/utils';
import { type LucideIcon } from 'lucide-react';
import { type ReactNode } from 'react';

/**
 * What an empty list says. "No rows" is a database answer; the operator wants to know whether empty
 * is good news ("nothing is waiting for you") and, if not, what would fill it.
 */
export function EmptyState({
    icon: Icon,
    title,
    description,
    action,
    className,
}: {
    icon?: LucideIcon;
    title: ReactNode;
    description?: ReactNode;
    action?: ReactNode;
    className?: string;
}) {
    return (
        <div className={cn('flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed px-6 py-10 text-center', className)}>
            {Icon && <Icon className="text-muted-foreground/70 size-8" aria-hidden />}
            <div className="text-sm font-medium">{title}</div>
            {description && <div className="text-muted-foreground max-w-md text-sm">{description}</div>}
            {action && <div className="mt-2">{action}</div>}
        </div>
    );
}
