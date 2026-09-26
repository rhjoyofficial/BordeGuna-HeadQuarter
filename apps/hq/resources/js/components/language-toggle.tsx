import { type Locale, setLocale, useLocale } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';

const LABELS: Record<Locale, string> = { en: 'English', bn: 'বাংলা' };

/**
 * English or Bangla for every screen (docs/90 A.6). The choice applies to this page at once and is saved
 * as the person's own preference through their profile settings (`PATCH settings/profile` with `locale`
 * alone: a normal, audited profile mutation), so it follows them to every device and browser. It changes
 * how the page reads, never what HQ does.
 */
export function LanguageToggle({ className }: { className?: string }) {
    const locale = useLocale();

    const choose = (l: Locale) => {
        if (l === locale) return;
        setLocale(l);
        router.patch(route('profile.update'), { locale: l }, { preserveScroll: true, preserveState: true });
    };

    return (
        <div role="group" aria-label="Language / ভাষা" className={cn('bg-muted inline-flex items-center rounded-md p-0.5 text-xs', className)}>
            {(['en', 'bn'] as Locale[]).map((l) => (
                <button
                    key={l}
                    type="button"
                    lang={l}
                    aria-pressed={locale === l}
                    onClick={() => choose(l)}
                    className={cn(
                        'rounded px-2 py-1 font-medium transition-colors',
                        locale === l ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground',
                    )}
                >
                    {LABELS[l]}
                </button>
            ))}
        </div>
    );
}
