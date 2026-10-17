import { type Translator } from '@/lib/i18n';

/**
 * Ages in the reader's language, English digits always (the i18n layer guarantees that). The console's
 * own words, so `lib/format`'s English-only `timeAgo` stays as every other screen uses it.
 */
export function ageFromMinutes(t: Translator, minutes: number | null | undefined): string {
    if (minutes === null || minutes === undefined) return t('never');
    if (minutes < 1) return t('just now');
    if (minutes < 60) return t('{n} min ago', { n: minutes });
    if (minutes < 48 * 60) return t('{n} h ago', { n: Math.round(minutes / 60) });
    return t('{n} days ago', { n: Math.round(minutes / 1440) });
}

export function ago(t: Translator, iso: string | null | undefined, now: number = Date.now()): string {
    if (!iso) return t('never');
    const at = new Date(iso).getTime();
    if (Number.isNaN(at)) return t('never');
    const minutes = Math.round((now - at) / 60000);
    if (minutes < 0) {
        const ahead = -minutes;
        if (ahead < 60) return t('in {n} min', { n: Math.max(1, ahead) });
        if (ahead < 48 * 60) return t('in {n} h', { n: Math.round(ahead / 60) });
        return t('in {n} days', { n: Math.round(ahead / 1440) });
    }

    return ageFromMinutes(t, minutes);
}

/** "17 Oct, 09:40" in the group's timezone, English digits. */
export function clock(iso: string, timeZone: string): string {
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return iso;
    return d.toLocaleString('en-GB', { timeZone, day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
}
