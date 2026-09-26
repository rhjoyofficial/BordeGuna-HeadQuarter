import { type ReactNode, useCallback, useSyncExternalStore } from 'react';
import bnDictionary from './i18n/bn.json';

/**
 * Bangla and English for the operator screens (docs/88 A point 7; docs/90 A.6).
 *
 * The English text IS the key: `t('Agents')` returns the Bangla entry for "Agents" when the viewer chose
 * Bangla, and the English text itself otherwise or when no entry exists yet. So a screen is never blank
 * in either language, and translating a screen means adding its strings to `i18n/bn.json`, not editing
 * the screen. The shared building blocks (page header, panels, tables, badges, navigation, breadcrumbs)
 * pass their string props through `t()`, which is how most screens become bilingual without a change.
 *
 * ⭐ Rules held by `tests/Feature/Foundation/BanglaDictionaryTest.php`:
 *   - English digits in BOTH languages (Joy's rule): a Bangla digit never reaches the screen, whether
 *     it came from the dictionary, a value, or the browser's locale;
 *   - every Bangla entry is Unicode NFC, so the same word is the same bytes wherever it is compared;
 *   - the brand is "Bor de Guna" in English and "বর দে গুনা" in Bangla.
 *
 * ⭐ Whose choice it is (docs/90 A.6, stream V2): the PERSON's, saved on their profile (`users.locale`)
 *   and shared to every page as `auth.user.locale`. The hierarchy, held by `resolveLocale()` and a test:
 *   the person's saved preference, then the browser's language as the initial fallback only, then
 *   English. Nothing durable lives in this browser: the switch applies at once for this page and saves
 *   the preference through the person's own profile settings (an audited profile mutation, never an AI
 *   capability). A model or provider cannot reach it.
 */

export type Locale = 'en' | 'bn';

export const LOCALES: Locale[] = ['en', 'bn'];

const CHANGE_EVENT = 'hq-locale-change';

const BN: Record<string, string> = Object.fromEntries(
    Object.entries(bnDictionary as Record<string, string>).map(([en, bn]) => [en, bn.normalize('NFC')]),
);

const BANGLA_DIGITS = '০১২৩৪৫৬৭৮৯';

/** Replace every Bangla digit with its English digit. English digits everywhere, whatever produced the text. */
export function englishDigits(text: string): string {
    return text.replace(/[০-৯]/g, (d) => String(BANGLA_DIGITS.indexOf(d)));
}

/** A number with English digits and grouping, whatever the browser's locale is. */
export function formatNumber(value: number | string | null | undefined): string {
    if (value === null || value === undefined || value === '') return '-';
    const n = typeof value === 'number' ? value : Number(value);
    return Number.isNaN(n) ? String(value) : n.toLocaleString('en-US');
}

function isLocale(value: unknown): value is Locale {
    return value === 'en' || value === 'bn';
}

/**
 * The language a page reads in, by the hierarchy of docs/90 A.6, in this order and no other:
 *   1. the person's saved preference (`users.locale`), when one is saved;
 *   2. the browser's language, as the initial fallback only (Bangla when it says so);
 *   3. the application default, English.
 */
export function resolveLocale(saved: unknown, browserLanguage: string | undefined): Locale {
    if (isLocale(saved)) return saved;
    if (typeof browserLanguage === 'string' && browserLanguage.toLowerCase().startsWith('bn')) return 'bn';
    return 'en';
}

/** The language of this page view, resolved once at boot and changed only by the switch. */
let current: Locale = 'en';

export function currentLocale(): Locale {
    return current;
}

/** Applies a language to this page view at once. Saving it as the person's preference is the switch's job. */
export function setLocale(locale: Locale): void {
    current = locale;
    if (typeof document !== 'undefined') document.documentElement.lang = locale;
    if (typeof window !== 'undefined') window.dispatchEvent(new Event(CHANGE_EVENT));
}

/**
 * Resolves the page's language at boot from the person's saved preference (`auth.user.locale` in the
 * first page's props) and the browser's language, and sets the page's `lang` so the browser and screen
 * readers read the right language.
 */
export function initializeLocale(saved: unknown): void {
    current = resolveLocale(saved, typeof navigator !== 'undefined' ? navigator.language : undefined);
    if (typeof document !== 'undefined') document.documentElement.lang = current;
}

function subscribe(onChange: () => void): () => void {
    window.addEventListener(CHANGE_EVENT, onChange);
    return () => {
        window.removeEventListener(CHANGE_EVENT, onChange);
    };
}

export function useLocale(): Locale {
    return useSyncExternalStore(subscribe, currentLocale, () => 'en');
}

export type TranslateParams = Record<string, string | number | null | undefined>;

/** The text in the chosen language, `{name}` placeholders filled, English digits guaranteed. */
export function translate(locale: Locale, text: string, params?: TranslateParams): string {
    let out = locale === 'bn' ? (BN[text] ?? text) : text;
    if (params) {
        out = out.replace(/\{(\w+)\}/g, (match, key: string) => {
            if (!(key in params)) return match;
            const value = params[key];
            if (value === null || value === undefined) return '-';
            return typeof value === 'number' ? formatNumber(value) : String(value);
        });
    }
    return englishDigits(out);
}

/** Whether the dictionary holds a Bangla entry for this English text. */
export function hasTranslation(text: string): boolean {
    return text in BN;
}

export type Translator = (text: string, params?: TranslateParams) => string;

export function useT(): Translator {
    const locale = useLocale();
    return useCallback((text: string, params?: TranslateParams) => translate(locale, text, params), [locale]);
}

/** Translates a node when it is plain text and leaves anything else (elements, numbers) as it is. */
export function useTn(): (node: ReactNode) => ReactNode {
    const t = useT();
    return useCallback((node: ReactNode) => (typeof node === 'string' ? t(node) : node), [t]);
}
