/**
 * Formatting helpers for the operator screens.
 *
 * Every HQ screen used to render its database row as-is: ISO timestamps, cron expressions, tool
 * keys in monospace. These helpers turn those into what a person reads first; the raw value stays
 * available underneath for anyone who needs it.
 */

import { currentLocale, englishDigits, type Locale, translate } from '@/lib/i18n';

const MINUTE = 60;
const HOUR = 3600;
const DAY = 86400;

/**
 * ⭐ Ruling 1 Nov 2026 (ChatGPT, binding): relative times and dates read in the reader's language. Bangla screens
 * use bn-BD conventions, every clock is Asia/Dhaka, and digits stay English in both languages (Joy's rule, held
 * by `englishDigits`). This file is the ONE place that turns a timestamp into words; a screen never writes
 * "ago", "just now" or "yesterday" itself (held by tests/Feature/Foundation/BanglaTimeAndReasonsTest.php).
 */
export const HQ_TIME_ZONE = 'Asia/Dhaka';

const INTL_LOCALE: Record<Locale, string> = { en: 'en-GB', bn: 'bn-BD' };

const relativeFormatters = new Map<Locale, Intl.RelativeTimeFormat | null>();

/** The browser's relative-time formatter for this language, or null when the browser has no data for it. */
function relativeFormatter(locale: Locale): Intl.RelativeTimeFormat | null {
    if (!relativeFormatters.has(locale)) {
        let formatter: Intl.RelativeTimeFormat | null = null;
        try {
            const tag = INTL_LOCALE[locale];
            if (typeof Intl !== 'undefined' && 'RelativeTimeFormat' in Intl && Intl.RelativeTimeFormat.supportedLocalesOf([tag]).length > 0) {
                formatter = new Intl.RelativeTimeFormat(tag, { numeric: 'auto', style: locale === 'en' ? 'short' : 'long' });
            }
        } catch {
            formatter = null;
        }
        relativeFormatters.set(locale, formatter);
    }
    return relativeFormatters.get(locale) ?? null;
}

/** The dictionary's own words, for a browser without relative-time data. Same meaning, English digits. */
function relativeFromDictionary(locale: Locale, n: number, unit: 'minute' | 'hour' | 'day', future: boolean): string {
    if (unit === 'day' && n === 1) return translate(locale, future ? 'tomorrow' : 'yesterday');
    if (unit === 'minute') return translate(locale, future ? 'in {n} min' : '{n} min ago', { n });
    if (unit === 'hour') return translate(locale, future ? 'in {n} h' : '{n} h ago', { n });
    return translate(locale, future ? 'in {n} days' : '{n} days ago', { n });
}

export type TimeAgoOptions = { now?: number; locale?: Locale };

/**
 * "3 min ago", "2 hr ago", "yesterday", "in 4 hr" in English; "3 মিনিট আগে", "গতকাল", "4 ঘণ্টায়" in Bangla
 * (English digits always). Under 45 seconds it is "just now" / "in a moment"; from 30 days on it is the date
 * (Asia/Dhaka). Null or an unparsable value gives "never". The language is the page's own unless one is named.
 */
export function relativeTime(value: string | null | undefined, options: TimeAgoOptions = {}): string {
    const locale = options.locale ?? currentLocale();
    const now = options.now ?? Date.now();
    if (!value) return translate(locale, 'never');
    const at = new Date(value).getTime();
    if (Number.isNaN(at)) return translate(locale, 'never');

    const diff = Math.round((now - at) / 1000);
    const future = diff < 0;
    const s = Math.abs(diff);

    if (s < 45) return translate(locale, future ? 'in a moment' : 'just now');
    if (s >= 30 * DAY) return formatDate(value, locale);

    let n: number;
    let unit: 'minute' | 'hour' | 'day';
    if (s < HOUR) [n, unit] = [Math.max(1, Math.round(s / MINUTE)), 'minute'];
    else if (s < DAY) [n, unit] = [Math.max(1, Math.round(s / HOUR)), 'hour'];
    else if (s < 2 * DAY) [n, unit] = [1, 'day'];
    else [n, unit] = [Math.round(s / DAY), 'day'];

    const formatter = relativeFormatter(locale);
    if (formatter === null) return relativeFromDictionary(locale, n, unit, future);
    return englishDigits(formatter.format(future ? n : -n, unit));
}

/** `relativeTime` with the positional shape every screen already calls. */
export function timeAgo(value: string | null | undefined, now: number = Date.now(), locale?: Locale): string {
    return relativeTime(value, { now, locale });
}

/** An age the server computed in minutes, in the same words as `relativeTime`. */
export function ageFromMinutes(minutes: number | null | undefined, options: TimeAgoOptions = {}): string {
    if (minutes === null || minutes === undefined) return translate(options.locale ?? currentLocale(), 'never');
    const now = options.now ?? Date.now();
    return relativeTime(new Date(now - minutes * 60000).toISOString(), { ...options, now });
}

/** An age the server computed in seconds, in the same words as `relativeTime`. */
export function ageFromSeconds(seconds: number | null | undefined, options: TimeAgoOptions = {}): string {
    if (seconds === null || seconds === undefined) return translate(options.locale ?? currentLocale(), 'never');
    const now = options.now ?? Date.now();
    return relativeTime(new Date(now - seconds * 1000).toISOString(), { ...options, now });
}

function dateTimeFormat(locale: Locale, opts: Intl.DateTimeFormatOptions, timeZone: string): Intl.DateTimeFormat {
    try {
        return new Intl.DateTimeFormat(INTL_LOCALE[locale], { timeZone, ...opts });
    } catch {
        return new Intl.DateTimeFormat('en-GB', { timeZone, ...opts });
    }
}

/** "12 Sep 2026, 14:03" (Bangla month names on a Bangla screen), Asia/Dhaka, English digits, or "-" for nothing. */
export function formatDateTime(value: string | null | undefined, locale: Locale = currentLocale(), timeZone: string = HQ_TIME_ZONE): string {
    if (!value) return '-';
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return value;
    return englishDigits(
        dateTimeFormat(
            locale,
            { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' },
            timeZone,
        ).format(d),
    );
}

/** "12 Sep 2026" (Bangla month names on a Bangla screen), Asia/Dhaka, English digits, or "-" for nothing. */
export function formatDate(value: string | null | undefined, locale: Locale = currentLocale(), timeZone: string = HQ_TIME_ZONE): string {
    if (!value) return '-';
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return value;
    return englishDigits(dateTimeFormat(locale, { day: 'numeric', month: 'short', year: 'numeric' }, timeZone).format(d));
}

/** "17 Oct, 09:40" without the year, in the named timezone (Asia/Dhaka by default), English digits. */
export function formatClock(value: string | null | undefined, timeZone: string = HQ_TIME_ZONE, locale: Locale = currentLocale()): string {
    if (!value) return '-';
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return value;
    return englishDigits(
        dateTimeFormat(locale, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }, timeZone).format(d),
    );
}

/** Seconds as "5 min", "1 h 30 min", "2 days" (or their Bangla), English digits. */
export function humanDuration(seconds: number | null | undefined, locale: Locale = currentLocale()): string {
    if (seconds === null || seconds === undefined) return '-';
    if (seconds < MINUTE) return translate(locale, '{n} s', { n: seconds });
    if (seconds < HOUR) return translate(locale, '{n} min', { n: Math.round(seconds / MINUTE) });
    if (seconds < DAY) {
        const h = Math.floor(seconds / HOUR);
        const m = Math.round((seconds % HOUR) / MINUTE);
        return m ? translate(locale, '{h} h {m} min', { h, m }) : translate(locale, '{n} h', { n: h });
    }
    const d = Math.round(seconds / DAY);
    return d === 1 ? translate(locale, '1 day') : translate(locale, '{n} days', { n: d });
}

/** Milliseconds as "820 ms" or "2.4 s". */
export function humanMillis(ms: number | null | undefined): string {
    if (ms === null || ms === undefined) return '-';
    return ms < 1000 ? `${ms} ms` : `${(ms / 1000).toFixed(1)} s`;
}

const DAY_NAMES = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

function pad(n: string): string {
    return n.length === 1 ? `0${n}` : n;
}

/**
 * A five-field cron expression in words, for the common shapes, in the page's language. Anything it does not
 * recognise comes back as the expression itself, so nothing is ever hidden behind a wrong translation.
 */
export function humanCron(expr: string | null | undefined, locale: Locale = currentLocale()): string {
    if (!expr) return '-';
    const parts = expr.trim().split(/\s+/);
    if (parts.length !== 5) return expr;
    const [min, hour, dom, mon, dow] = parts;
    const restIsAny = dom === '*' && mon === '*' && dow === '*';
    const tr = (text: string, params?: Record<string, string>) => translate(locale, text, params);

    if (min.startsWith('*/') && hour === '*' && restIsAny) return tr('Every {n} minutes', { n: min.slice(2) });
    if (min === '*' && hour === '*' && restIsAny) return tr('Every minute');
    if (/^\d+$/.test(min) && hour === '*' && restIsAny) return min === '0' ? tr('Every hour') : tr('Every hour at :{mm}', { mm: pad(min) });
    if (/^\d+$/.test(min) && hour.startsWith('*/') && restIsAny) return tr('Every {n} hours at :{mm}', { n: hour.slice(2), mm: pad(min) });
    if (/^\d+$/.test(min) && /^\d+$/.test(hour)) {
        const at = `${pad(hour)}:${pad(min)}`;
        if (restIsAny) return tr('Daily at {at}', { at });
        if (dom === '*' && mon === '*' && /^\d$/.test(dow)) {
            const day = DAY_NAMES[Number(dow)];
            return tr('Every {day} at {at}', { day: day ? tr(day) : dow, at });
        }
        if (dom === '*' && mon === '*' && dow === '1-5') return tr('Weekdays at {at}', { at });
        if (dom === '*' && mon === '*' && (dow === '0,6' || dow === '6,0')) return tr('Weekends at {at}', { at });
        if (/^\d+$/.test(dom) && mon === '*' && dow === '*') return tr('Monthly on day {day} at {at}', { day: dom, at });
    }
    return expr;
}

/** A scheduled job's cadence in words, from whichever of its fields is set, in the page's language. */
export function humanSchedule(
    job: {
        kind: string;
        cron_expression?: string | null;
        interval_seconds?: number | null;
        event_type?: string | null;
        run_at?: string | null;
    },
    locale: Locale = currentLocale(),
): string {
    switch (job.kind) {
        case 'cron':
            return humanCron(job.cron_expression, locale);
        case 'interval':
            return translate(locale, 'Every {duration}', { duration: humanDuration(job.interval_seconds, locale) });
        case 'event':
            return translate(locale, 'When {event} happens', { event: humanKey(job.event_type ?? '').toLowerCase() });
        case 'once':
            return job.run_at ? translate(locale, 'Once, {at}', { at: formatDateTime(job.run_at, locale) }) : translate(locale, 'Once');
        default:
            return job.kind;
    }
}

const ACRONYMS: Record<string, string> = { erp: 'ERP', api: 'API', sms: 'SMS', ai: 'AI', nl: 'NL', id: 'ID', sla: 'SLA', hq: 'HQ' };

/**
 * A dotted or snake-cased key in words: `erp.poll_overview` becomes "ERP poll overview",
 * `order.status_changed` becomes "Order status changed". Used wherever a key is the only label.
 */
export function humanKey(key: string | null | undefined): string {
    if (!key) return '-';
    const words = key
        .split(/[._:-]+/)
        .filter(Boolean)
        .map((w) => ACRONYMS[w.toLowerCase()] ?? w.replace(/([a-z])([A-Z])/g, '$1 $2').toLowerCase());
    if (words.length === 0) return key;
    const first = words[0];
    words[0] = first === first.toUpperCase() ? first : first.charAt(0).toUpperCase() + first.slice(1);
    return words.join(' ');
}

/** "1,245.50", English digits; an amount under one paisa or one cent keeps four places so it does not read 0.00. */
function moneyFigure(n: number): string {
    if (n !== 0 && Math.abs(n) < 0.01) return n.toFixed(4);
    return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

export type AiCostCurrency = 'BDT' | 'USD';

/**
 * A model cost (stored in USD) as text in ONE currency: "BDT 159.90" or "USD 1.23". BDT is the default
 * (Joy's rule: amounts always in BDT); the person may switch to USD. The rate comes from the server
 * (`money.usd_bdt_rate`); without one the text is USD whatever was chosen, and nothing is guessed.
 */
export function aiCostText(
    amount: number | string | null | undefined,
    rate: number | null | undefined,
    currency: AiCostCurrency = 'BDT',
): { text: string; zero: boolean } {
    const parsed = Number(amount ?? 0);
    const n = Number.isFinite(parsed) ? parsed : 0;
    const text = currency === 'BDT' && typeof rate === 'number' && rate > 0 ? `BDT ${moneyFigure(n * rate)}` : `USD ${moneyFigure(n)}`;
    return { text, zero: n === 0 };
}

/** Shorten a long sentence for a table cell. */
export function truncate(text: string, max = 90): string {
    return text.length > max ? `${text.slice(0, max).trimEnd()}...` : text;
}

/** The last segment of a PHP class name: `App\Models\Approval` becomes `Approval`. */
export function shortClass(name: string | null | undefined): string {
    if (!name) return '';
    return name.split('\\').pop() ?? name;
}
