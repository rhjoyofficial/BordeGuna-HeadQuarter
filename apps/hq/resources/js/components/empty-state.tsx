import { useTn } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { type LucideIcon, PowerOff } from 'lucide-react';
import { type ReactNode } from 'react';

/**
 * What an empty list says. "No rows" is a database answer; the operator wants to know whether empty
 * is good news ("nothing is waiting for you") and, if not, what would fill it.
 *
 * docs/135 (h), W-UI3: the `inert` variant is for a capability that is switched off on purpose. The honesty rule
 * of docs/135 (a) says it renders, never hides and never pretends: it names WHY it is off (`reason`, for example
 * "AI runtime off") and WHAT turns it on (`gate`, for example "a person switches the ask_hq flag on").
 */
export function EmptyState({
    icon: Icon,
    title,
    description,
    action,
    className,
    variant = 'empty',
    reason,
    gate,
}: {
    icon?: LucideIcon;
    title: ReactNode;
    description?: ReactNode;
    action?: ReactNode;
    className?: string;
    variant?: 'empty' | 'inert';
    reason?: ReactNode;
    gate?: ReactNode;
}) {
    const tn = useTn();
    const inert = variant === 'inert';
    const Shown = Icon ?? (inert ? PowerOff : undefined);
    return (
        <div
            className={cn(
                'flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed px-6 py-10 text-center',
                inert ? 'border-tone-neutral-line bg-tone-neutral-bg' : 'border-glass-border bg-card/40',
                className,
            )}
            data-state={inert ? 'inert' : 'empty'}
        >
            {Shown && <Shown className="text-muted-foreground size-8" aria-hidden />}
            {inert && <span className="hq-eyebrow">{tn('Switched off')}</span>}
            <div className="text-sm font-medium">{tn(title)}</div>
            {description && <div className="text-muted-foreground max-w-md text-sm">{tn(description)}</div>}
            {(reason || gate) && (
                <dl className="text-muted-foreground mt-1 grid max-w-md grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-left text-xs">
                    {reason && (
                        <>
                            <dt className="font-medium">{tn('Why')}</dt>
                            <dd className="text-foreground">{tn(reason)}</dd>
                        </>
                    )}
                    {gate && (
                        <>
                            <dt className="font-medium">{tn('Turns on when')}</dt>
                            <dd className="text-foreground">{tn(gate)}</dd>
                        </>
                    )}
                </dl>
            )}
            {action && <div className="mt-2">{action}</div>}
        </div>
    );
}
