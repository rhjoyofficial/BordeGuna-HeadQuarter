import { StatusBadge, type Tone } from '@/components/ui/status-badge';
import { formatDateTime, humanKey } from '@/lib/format';
import { useT } from '@/lib/i18n';

export type ParcelEvent = { at: string; status: string; raw_status: string; message: string | null; source: string };
export type Parcel = {
    uuid: string;
    provider: string;
    consignment_ref: string;
    status: string;
    raw_status: string | null;
    terminal: boolean;
    last_event_at: string | null;
    last_polled_at: string | null;
    poll_failures: number;
    linked: boolean;
    merchant_order_ref: string | null;
    events: ParcelEvent[];
};

const TONES: Record<string, Tone> = {
    delivered: 'ok',
    out_for_delivery: 'info',
    in_transit: 'info',
    picked_up: 'info',
    created: 'pending',
    on_hold: 'warn',
    returned: 'danger',
    cancelled: 'neutral',
    unknown: 'neutral',
};

/** A parcel's status in words a person reads, with the tone its meaning carries (never the courier's own wording alone). */
export function ParcelStatusBadge({ status }: { status: string }) {
    const t = useT();
    return <StatusBadge tone={TONES[status] ?? 'neutral'}>{t(humanKey(status))}</StatusBadge>;
}

/**
 * One parcel and its timeline, from HQ's own record of what the courier said. The courier's wording is kept
 * beside HQ's, and a parcel that cannot be polled says so rather than looking current.
 */
export function ParcelTimeline({ parcel, showRef = true }: { parcel: Parcel; showRef?: boolean }) {
    const t = useT();
    return (
        <div className="flex flex-col gap-2">
            <div className="flex flex-wrap items-center gap-2 text-sm">
                <span className="font-medium capitalize">{parcel.provider}</span>
                {showRef && <span className="font-mono text-xs">{parcel.consignment_ref}</span>}
                <ParcelStatusBadge status={parcel.status} />
                {parcel.poll_failures > 0 && !parcel.terminal && (
                    <span className="text-tone-warn text-xs">{t('The courier could not be reached {count} times; this may be out of date.', { count: parcel.poll_failures })}</span>
                )}
            </div>
            {parcel.events.length === 0 ? (
                <p className="text-muted-foreground text-xs">{t('No events yet.')}</p>
            ) : (
                <ol className="border-glass-border ml-1 flex flex-col gap-2 border-l pl-4">
                    {parcel.events.map((e, i) => (
                        <li key={`${e.at}-${i}`} className="relative text-sm">
                            <span aria-hidden className="bg-foreground/40 absolute top-1.5 -left-[1.3rem] size-2 rounded-full" />
                            <div className="flex flex-wrap items-center gap-2">
                                <ParcelStatusBadge status={e.status} />
                                <span className="text-muted-foreground text-xs">{formatDateTime(e.at)}</span>
                                <span className="text-muted-foreground text-xs">{e.source === 'webhook' ? t('pushed by the courier') : t('asked of the courier')}</span>
                            </div>
                            <div className="text-muted-foreground mt-0.5 text-xs">
                                {e.raw_status}
                                {e.message ? `: ${e.message}` : ''}
                            </div>
                        </li>
                    ))}
                </ol>
            )}
        </div>
    );
}
