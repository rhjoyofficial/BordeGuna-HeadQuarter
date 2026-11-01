/**
 * Run by tests/Feature/Foundation/BanglaTimeAndReasonsTest.php: bundled with esbuild and executed with node, it
 * prints what the real helpers say for fixed inputs, so the PHP test can hold their behaviour (no JS test
 * runner exists in this app). Nothing here is shipped.
 */
import { formatDate, formatDateTime, humanCron, humanDuration, relativeTime } from '@/lib/format';
import { describeReason, reasonLabel } from '@/lib/reasons';

const now = Date.parse('2026-11-01T06:00:00Z');
const at = (secondsAgo: number) => new Date(now - secondsAgo * 1000).toISOString();

const out: Record<string, unknown> = {};
for (const locale of ['en', 'bn'] as const) {
    out[locale] = {
        justNow: relativeTime(at(10), { now, locale }),
        inAMoment: relativeTime(at(-10), { now, locale }),
        fiveMinAgo: relativeTime(at(300), { now, locale }),
        twoHoursAgo: relativeTime(at(7200), { now, locale }),
        inThreeHours: relativeTime(at(-3 * 3600), { now, locale }),
        yesterday: relativeTime(at(30 * 3600), { now, locale }),
        fiveDaysAgo: relativeTime(at(5 * 86400), { now, locale }),
        old: relativeTime(at(60 * 86400), { now, locale }),
        never: relativeTime(null, { now, locale }),
        dateTime: formatDateTime('2026-11-01T05:03:00Z', locale),
        date: formatDate('2026-11-01T20:00:00Z', locale),
        duration: humanDuration(5400, locale),
        cron: humanCron('30 9 * * *', locale),
        known: describeReason({ code: 'feature_disabled', text: 'Tool x is behind flag y.' }, locale),
        unknown: describeReason({ code: 'some_new_code' }, locale),
        freeText: describeReason({ text: 'A sentence the server wrote.' }, locale),
        codeOnlyString: describeReason('consent_withdrawn', locale),
        label: reasonLabel('timeout', locale),
        unknownLabel: reasonLabel('some_new_code', locale),
    };
}

process.stdout.write(JSON.stringify(out));
