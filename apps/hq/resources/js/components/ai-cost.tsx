import { aiCostParts } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';

/** BDT per USD from the server (`money.usd_bdt_rate`, shared on every page); null when none is configured. */
export function useUsdBdtRate(): number | null {
    const rate = usePage<SharedData>().props.money?.usd_bdt_rate;
    return typeof rate === 'number' && rate > 0 ? rate : null;
}

/**
 * A model cost (stored in USD) shown BDT first, with USD as a small secondary figure:
 * "BDT 1,245.50 (USD 9.58)". Display only. `free` shows "free" for zero, where a screen always did.
 */
export function AiCost({ usd, free = true }: { usd: number | string | null | undefined; free?: boolean }) {
    const t = useT();
    const { main, secondary, zero } = aiCostParts(usd, useUsdBdtRate());
    if (free && zero) return <>{t('free')}</>;

    return (
        <span>
            <span className="whitespace-nowrap">{main}</span>
            {secondary && <span className="text-muted-foreground ml-1 text-[0.75em] font-normal whitespace-nowrap">({secondary})</span>}
        </span>
    );
}
