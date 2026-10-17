import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { ageFromMinutes } from './age';
import { type ConsoleSource, type FreshnessState } from './types';

/**
 * One source the brief rests on, with its real state and age (docs/135 (a), artboard 1). Never a tick
 * by default: fresh is said only when the source is inside its budget, and a source HQ holds nothing
 * for says "no data", not "0".
 */
const DOT: Record<FreshnessState, string> = {
    fresh: 'bg-hq-teal',
    live: 'bg-hq-teal',
    stale: 'bg-hq-orange',
    missing: 'bg-red-600',
    not_connected: 'bg-muted-foreground/60',
};

export function SourceChip({ source }: { source: ConsoleSource }) {
    const t = useT();
    const kind = source.kind === 'orders' ? t('orders') : source.kind === 'erp_stock' ? t('ERP stock') : t('integrity check');
    const name = source.label ? `${source.label}, ${kind}` : kind;

    const state =
        source.state === 'fresh'
            ? t('fresh, {age}', { age: ageFromMinutes(t, source.age_minutes) })
            : source.state === 'stale'
              ? source.in_future
                  ? t('stale: its clock is ahead of HQ')
                  : t('stale, {age}', { age: ageFromMinutes(t, source.age_minutes) })
              : source.state === 'missing'
                ? t('no data')
                : source.state === 'live'
                  ? t('live')
                  : t('not connected');

    return (
        <span className="hq-glass inline-flex max-w-full items-center gap-1.5 rounded-full px-2.5 py-1 text-xs" title={source.note ?? undefined}>
            <span className={cn('size-1.5 shrink-0 rounded-full', DOT[source.state])} aria-hidden />
            <span className="truncate font-medium">{name}</span>
            <span className="text-muted-foreground truncate">{state}</span>
        </span>
    );
}

/** A source the briefing contract names as not connected yet (marketing, finance), with its reason. */
export function NotConnectedChip({ name, reason }: { name: string; reason: string }) {
    const t = useT();
    return (
        <span className="hq-glass inline-flex max-w-full items-center gap-1.5 rounded-full px-2.5 py-1 text-xs" title={reason}>
            <span className={cn('size-1.5 shrink-0 rounded-full', DOT.not_connected)} aria-hidden />
            <span className="truncate font-medium">{name}</span>
            <span className="text-muted-foreground truncate">{t('not connected')}</span>
        </span>
    );
}
