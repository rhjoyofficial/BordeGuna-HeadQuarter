import { useTn } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { type ReactNode } from 'react';

/**
 * One badge for every status on every screen, so "pending" on an approval looks like "pending" on a
 * job. Tones say what the state MEANS to the operator, not what the database calls it:
 *   ok       finished well, enabled, active
 *   pending  waiting for someone or something
 *   warn     needs a look: stopped, refused, stale, shadow
 *   danger   failed, dead, expired, disabled by hand
 *   info     in progress, approved-not-yet-run, delegated
 *   neutral  cancelled, paused, off, unknown
 */
export type Tone = 'ok' | 'pending' | 'warn' | 'danger' | 'info' | 'neutral';

// docs/135 (h), W-UI3: the tones are theme tokens (app.css), tinted chip plus hairline plus readable text, the same in
// both themes by meaning and checked at 4.5:1 by ScreenRestyleTest.
const TONES: Record<Tone, string> = {
    ok: 'bg-tone-ok-bg text-tone-ok ring-tone-ok-line',
    pending: 'bg-tone-pending-bg text-tone-pending ring-tone-pending-line',
    warn: 'bg-tone-warn-bg text-tone-warn ring-tone-warn-line',
    danger: 'bg-tone-danger-bg text-tone-danger ring-tone-danger-line',
    info: 'bg-tone-info-bg text-tone-info ring-tone-info-line',
    neutral: 'bg-tone-neutral-bg text-tone-neutral ring-tone-neutral-line',
};

const DOTS: Record<Tone, string> = {
    ok: 'bg-tone-ok-dot',
    pending: 'bg-tone-pending-dot',
    warn: 'bg-tone-warn-dot',
    danger: 'bg-tone-danger-dot',
    info: 'bg-tone-info-dot',
    neutral: 'bg-tone-neutral-dot',
};

/** The tone a status word carries, for the words HQ uses. Unknown words are neutral. */
export function toneFor(status: string | null | undefined): Tone {
    switch ((status ?? '').toLowerCase()) {
        case 'succeeded':
        case 'replayed':
        case 'answered':
        case 'consumed':
        case 'active':
        case 'enabled':
        case 'on':
        case 'processed':
        case 'ok':
        case 'done':
        case 'yes':
            return 'ok';
        case 'pending':
        case 'queued':
        case 'scheduled':
        case 'awaiting':
        case 'proposed':
        case 'awaiting_review':
            return 'pending';
        case 'stopped':
        case 'refused':
        case 'shadow':
        case 'stale':
        case 'uncertain':
        case 'escalated':
        case 'overdue':
        case 'denied':
        case 'invalid':
        case 'unavailable':
        case 'untrusted':
            return 'warn';
        case 'failed':
        case 'dead':
        case 'expired':
        case 'rejected':
        case 'disabled':
        case 'revoked':
        case 'error':
        case 'model_error':
            return 'danger';
        case 'approved':
        case 'running':
        case 'started':
        case 'delegated':
        case 'in_progress':
        case 'edited':
            return 'info';
        default:
            return 'neutral';
    }
}

export function StatusBadge({
    status,
    tone,
    children,
    className,
    dot = true,
}: {
    /** The raw status word; used for the tone when `tone` is not given, and as the label when `children` is not given. */
    status?: string | null;
    tone?: Tone;
    children?: ReactNode;
    className?: string;
    dot?: boolean;
}) {
    const t = tone ?? toneFor(status);
    const tn = useTn();
    // A status word is translated like any other text: the English word is the dictionary key.
    const label = tn(children ?? (status ? status.replace(/_/g, ' ') : 'unknown'));
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium whitespace-nowrap ring-1 ring-inset',
                TONES[t],
                className,
            )}
        >
            {dot && <span className={cn('size-1.5 shrink-0 rounded-full', DOTS[t])} aria-hidden />}
            {label}
        </span>
    );
}

/** A small count pill for navigation and tabs. Hidden at zero. */
export function CountPill({ count, tone = 'pending', className }: { count: number; tone?: Tone; className?: string }) {
    if (!count) return null;
    return (
        <span className={cn('ml-auto inline-flex min-w-5 items-center justify-center rounded-full px-1.5 text-[11px] font-semibold tabular-nums ring-1 ring-inset', TONES[tone], className)}>
            {count > 99 ? '99+' : count}
        </span>
    );
}
