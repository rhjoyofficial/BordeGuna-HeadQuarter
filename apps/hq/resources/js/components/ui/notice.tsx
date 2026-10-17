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
