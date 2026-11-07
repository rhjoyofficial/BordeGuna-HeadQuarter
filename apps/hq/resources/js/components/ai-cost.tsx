import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { aiCostText, formatDate, type AiCostCurrency } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { ArrowLeftRight } from 'lucide-react';
import { useSyncExternalStore } from 'react';

/** BDT per USD from the server (`money.usd_bdt_rate`, shared on every page); null when the rate is missing or stale. */
export function useUsdBdtRate(): number | null {
    const rate = usePage<SharedData>().props.money?.usd_bdt_rate;
    return typeof rate === 'number' && rate > 0 ? rate : null;
}

/**
 * The facts a cost surface states beside a BDT figure (ruling 1 Nov 2026): it is an estimate, at this rate, as of this date. When
 * there is no usable rate, why: none set, or expired on a date.
 */
export function useAiCostRateNote(): { estimate: string | null; unavailable: string } {
    const t = useT();
    const money = usePage<SharedData>().props.money;
    const rate = useUsdBdtRate();
    const asOf = formatDate(money?.rate_as_of);
    const estimate =
        rate === null ? null : t('Estimate at BDT {rate} per USD, rate as of {date}.', { rate: rate.toFixed(2), date: asOf });
    const unavailable =
        money?.rate_state === 'expired'
            ? t('The BDT rate expired on {date}, so AI costs show in USD until the owner sets a new one.', {
                  date: formatDate(money?.rate_valid_until),
              })
            : t('No BDT rate is set on the server, so AI costs show in USD.');
    return { estimate, unavailable };
}

/*
 | Which currency AI costs read in: one choice for every cost surface, so switching on one screen switches
 | them all. Kept in this browser (localStorage), BDT by default (Joy's rule). Not in `owner_preferences`:
 | that table is read into every agent's prompt as "how the operator likes his answers", and a display
 | switch has no business there. Storage can be missing or blocked; the choice then lives for this page view.
 */
export const AI_COST_CURRENCY_KEY = 'hq-ai-cost-currency';
const CHANGE_EVENT = 'hq-ai-cost-currency-change';

let chosen: AiCostCurrency | null = null;

function readStored(): AiCostCurrency {
    try {
        return window.localStorage.getItem(AI_COST_CURRENCY_KEY) === 'USD' ? 'USD' : 'BDT';
    } catch {
        return 'BDT';
    }
}

function snapshot(): AiCostCurrency {
    if (chosen === null) chosen = typeof window === 'undefined' ? 'BDT' : readStored();
    return chosen;
}

function subscribe(onChange: () => void): () => void {
    const onStorage = (e: StorageEvent) => {
        if (e.key !== AI_COST_CURRENCY_KEY) return;
        chosen = null;
        onChange();
    };
    window.addEventListener(CHANGE_EVENT, onChange);
    window.addEventListener('storage', onStorage);
    return () => {
        window.removeEventListener(CHANGE_EVENT, onChange);
        window.removeEventListener('storage', onStorage);
    };
}

export function setAiCostCurrency(currency: AiCostCurrency): void {
    chosen = currency;
    try {
        window.localStorage.setItem(AI_COST_CURRENCY_KEY, currency);
    } catch {
        // Storage blocked: the choice still applies to this page view.
    }
    window.dispatchEvent(new Event(CHANGE_EVENT));
}

/** The currency costs are shown in: the person's choice, or USD whenever the server has no rate. */
export function useAiCostCurrency(): { currency: AiCostCurrency; rate: number | null; canSwitch: boolean } {
    const rate = useUsdBdtRate();
    const preferred = useSyncExternalStore(subscribe, snapshot, () => 'BDT' as AiCostCurrency);
    return { currency: rate === null ? 'USD' : preferred, rate, canSwitch: rate !== null };
}

/** A model cost (stored in USD) in the chosen currency only: "BDT 159.90" or "USD 1.23". Display only. */
export function AiCost({ usd, free = true }: { usd: number | string | null | undefined; free?: boolean }) {
    const t = useT();
    const { currency, rate } = useAiCostCurrency();
    const { estimate } = useAiCostRateNote();
    const { text, zero } = aiCostText(usd, rate, currency);
    if (free && zero) return <>{t('free')}</>;

    // A BDT figure is an estimate at a dated rate; the hover says so. A USD figure is the stored one.
    return (
        <span className="whitespace-nowrap" title={currency === 'BDT' && estimate ? estimate : undefined}>
            {text}
        </span>
    );
}

/** The small switch between BDT and USD that sits in the header of every AI cost surface. */
export function AiCostToggle({ className }: { className?: string }) {
    const t = useT();
    const { currency, canSwitch } = useAiCostCurrency();
    const { estimate, unavailable } = useAiCostRateNote();
    const other: AiCostCurrency = currency === 'BDT' ? 'USD' : 'BDT';

    const button = (
        <button
            type="button"
            onClick={() => setAiCostCurrency(other)}
            disabled={!canSwitch}
            aria-label={canSwitch ? t('AI costs in {current}. Show them in {other}', { current: currency, other }) : t('AI costs in USD')}
            title={canSwitch ? `${t('Show AI costs in {other}', { other })}. ${estimate ?? ''}`.trim() : undefined}
            className={cn(
                'border-input bg-background text-foreground hover:bg-accent focus-visible:ring-ring inline-flex h-7 shrink-0 items-center gap-1 rounded-md border px-2 text-xs font-medium tabular-nums transition-colors focus-visible:ring-2 focus-visible:ring-offset-1 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-60',
                className,
            )}
        >
            <ArrowLeftRight className="size-3.5" aria-hidden="true" />
            <span>{currency}</span>
        </button>
    );

    if (canSwitch) return button;

    // A disabled button gets no hover or focus, so the reason sits on a focusable wrapper.
    return (
        <TooltipProvider delayDuration={150}>
            <Tooltip>
                <TooltipTrigger asChild>
                    <span tabIndex={0} className="focus-visible:ring-ring inline-flex rounded-md focus-visible:ring-2 focus-visible:outline-none">
                        {button}
                    </span>
                </TooltipTrigger>
                <TooltipContent>{unavailable}</TooltipContent>
            </Tooltip>
        </TooltipProvider>
    );
}
