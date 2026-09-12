/**
 * Formatting helpers for the operator screens.
 *
 * Every HQ screen used to render its database row as-is: ISO timestamps, cron expressions, tool
 * keys in monospace. These helpers turn those into what a person reads first; the raw value stays
 * available underneath for anyone who needs it.
 */

const MINUTE = 60;
const HOUR = 3600;
const DAY = 86400;

/** "3 min ago", "2 h ago", "yesterday", "in 4 h". Null or an unparsable value gives "never". */
export function timeAgo(value: string | null | undefined, now: number = Date.now()): string {
    if (!value) return 'never';
    const t = new Date(value).getTime();
    if (Number.isNaN(t)) return 'never';

    const diff = Math.round((now - t) / 1000);
    const future = diff < 0;
    const s = Math.abs(diff);

    if (s < 45) return future ? 'in a moment' : 'just now';
    if (s >= DAY && s < 2 * DAY) return future ? 'tomorrow' : 'yesterday';
    if (s >= 30 * DAY) return new Date(value).toLocaleDateString();

    let words: string;
    if (s < 90) words = '1 min';
    else if (s < HOUR) words = `${Math.round(s / MINUTE)} min`;
    else if (s < 2 * HOUR) words = '1 h';
    else if (s < DAY) words = `${Math.round(s / HOUR)} h`;
    else words = `${Math.round(s / DAY)} days`;

    return future ? `in ${words}` : `${words} ago`;
}

/** "12 Sep 2026, 14:03" in the browser's locale, or "-" for nothing. */
export function formatDateTime(value: string | null | undefined): string {
    if (!value) return '-';
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return value;
    return d.toLocaleString(undefined, { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

/** Seconds as "5 min", "1 h 30 min", "2 days". */
export function humanDuration(seconds: number | null | undefined): string {
    if (seconds === null || seconds === undefined) return '-';
    if (seconds < MINUTE) return `${seconds} s`;
    if (seconds < HOUR) return `${Math.round(seconds / MINUTE)} min`;
    if (seconds < DAY) {
        const h = Math.floor(seconds / HOUR);
        const m = Math.round((seconds % HOUR) / MINUTE);
        return m ? `${h} h ${m} min` : `${h} h`;
    }
    const d = Math.round(seconds / DAY);
    return d === 1 ? '1 day' : `${d} days`;
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
 * A five-field cron expression in words, for the common shapes. Anything it does not recognise
 * comes back as the expression itself, so nothing is ever hidden behind a wrong translation.
 */
export function humanCron(expr: string | null | undefined): string {
    if (!expr) return '-';
    const parts = expr.trim().split(/\s+/);
    if (parts.length !== 5) return expr;
    const [min, hour, dom, mon, dow] = parts;
    const restIsAny = dom === '*' && mon === '*' && dow === '*';

    if (min.startsWith('*/') && hour === '*' && restIsAny) return `Every ${min.slice(2)} minutes`;
    if (min === '*' && hour === '*' && restIsAny) return 'Every minute';
    if (/^\d+$/.test(min) && hour === '*' && restIsAny) return min === '0' ? 'Every hour' : `Every hour at :${pad(min)}`;
    if (/^\d+$/.test(min) && hour.startsWith('*/') && restIsAny) return `Every ${hour.slice(2)} hours at :${pad(min)}`;
    if (/^\d+$/.test(min) && /^\d+$/.test(hour)) {
        const at = `${pad(hour)}:${pad(min)}`;
        if (restIsAny) return `Daily at ${at}`;
        if (dom === '*' && mon === '*' && /^\d$/.test(dow)) return `Every ${DAY_NAMES[Number(dow)] ?? dow} at ${at}`;
        if (dom === '*' && mon === '*' && dow === '1-5') return `Weekdays at ${at}`;
        if (dom === '*' && mon === '*' && (dow === '0,6' || dow === '6,0')) return `Weekends at ${at}`;
        if (/^\d+$/.test(dom) && mon === '*' && dow === '*') return `Monthly on day ${dom} at ${at}`;
    }
    return expr;
}

/** A scheduled job's cadence in words, from whichever of its fields is set. */
export function humanSchedule(job: {
    kind: string;
    cron_expression?: string | null;
    interval_seconds?: number | null;
    event_type?: string | null;
    run_at?: string | null;
}): string {
    switch (job.kind) {
        case 'cron':
            return humanCron(job.cron_expression);
        case 'interval':
            return `Every ${humanDuration(job.interval_seconds)}`;
        case 'event':
            return `When ${humanKey(job.event_type ?? '').toLowerCase()} happens`;
        case 'once':
            return job.run_at ? `Once, ${formatDateTime(job.run_at)}` : 'Once';
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

/** "$0.0042" for a cost in US dollars, "free" for zero. */
export function humanUsd(amount: number | string | null | undefined): string {
    const n = Number(amount ?? 0);
    if (!n) return 'free';
    return n < 0.01 ? `$${n.toFixed(4)}` : `$${n.toFixed(2)}`;
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
