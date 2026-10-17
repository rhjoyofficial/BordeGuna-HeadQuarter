import { type Tone } from '@/components/ui/status-badge';
import { useTn } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { type ReactNode } from 'react';

/**
 * docs/135 (h), W-UI3: a tinted callout in one of the six status tones (ok, pending, warn, danger, info, neutral),
 * the same tones a StatusBadge carries, readable at 4.5:1 in both themes. It replaces each screen's own amber, red
 * and green boxes. `role="status"` by default; pass `alert` for a failure the person must read now.
 */
const TONE_CLASS: Record<Tone, string> = {
    ok: 'bg-tone-ok-bg border-tone-ok-line text-tone-ok',
    pending: 'bg-tone-pending-bg border-tone-pending-line text-tone-pending',
    warn: 'bg-tone-warn-bg border-tone-warn-line text-tone-warn',
    danger: 'bg-tone-danger-bg border-tone-danger-line text-tone-danger',
    info: 'bg-tone-info-bg border-tone-info-line text-tone-info',
    neutral: 'bg-tone-neutral-bg border-tone-neutral-line text-tone-neutral',
};

export function toneClass(tone: Tone): string {
    return TONE_CLASS[tone];
}

export function Notice({
    tone = 'info',
    title,
    children,
    className,
    alert = false,
}: {
    tone?: Tone;
    title?: ReactNode;
    children?: ReactNode;
    className?: string;
    alert?: boolean;
}) {
    const tn = useTn();
    return (
        <div role={alert ? 'alert' : 'status'} className={cn('rounded-xl border px-4 py-3 text-sm', TONE_CLASS[tone], className)}>
            {title && <div className="font-semibold">{tn(title)}</div>}
            {children && <div className={cn(title ? 'mt-1' : '', 'min-w-0 break-words')}>{tn(children)}</div>}
        </div>
    );
}

/**
 * docs/135 (a) honesty rule, inline form: a capability that is switched off on purpose, shown where it would be,
 * with WHY it is off and WHAT turns it on. For a whole empty block use `EmptyState variant="inert"` instead.
 */
export function InertNotice({ title, reason, gate, children, className }: { title: ReactNode; reason: ReactNode; gate?: ReactNode; children?: ReactNode; className?: string }) {
    const tn = useTn();
    return (
        <div role="status" data-state="inert" className={cn('rounded-xl border border-dashed px-4 py-3 text-sm', TONE_CLASS.neutral, className)}>
            <div className="flex flex-wrap items-center gap-2">
                <span className="hq-eyebrow">{tn('Switched off')}</span>
                <span className="font-semibold">{tn(title)}</span>
            </div>
            <dl className="mt-1.5 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-xs">
                <dt className="font-medium opacity-80">{tn('Why')}</dt>
                <dd>{tn(reason)}</dd>
                {gate && (
                    <>
                        <dt className="font-medium opacity-80">{tn('Turns on when')}</dt>
                        <dd>{tn(gate)}</dd>
                    </>
                )}
            </dl>
            {children && <div className="mt-2">{tn(children)}</div>}
        </div>
    );
}
