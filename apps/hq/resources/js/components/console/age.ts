import { ageFromMinutes as ageInWords, formatClock, relativeTime } from '@/lib/format';
import { type Translator } from '@/lib/i18n';

/**
 * Ages in the reader's language, English digits always. Ruling 1 Nov 2026: one helper for every relative time,
 * so these are thin names over `lib/format`'s `relativeTime` (bn-BD on a Bangla screen, Asia/Dhaka). The
 * translator argument is kept so the console's calls read as before; the language is the page's own.
 */
export function ageFromMinutes(_t: Translator, minutes: number | null | undefined): string {
    return ageInWords(minutes);
}

export function ago(_t: Translator, iso: string | null | undefined, now: number = Date.now()): string {
    return relativeTime(iso, { now });
}

/** "17 Oct, 09:40" in the group's timezone, English digits, month in the reader's language. */
export function clock(iso: string, timeZone: string): string {
    return formatClock(iso, timeZone);
}
