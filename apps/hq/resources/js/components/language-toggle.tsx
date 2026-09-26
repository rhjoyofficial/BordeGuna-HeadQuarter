import { type Locale, setLocale, useLocale } from '@/lib/i18n';
import { cn } from '@/lib/utils';

const LABELS: Record<Locale, string> = { en: 'English', bn: 'বাংলা' };

/**
 * English or Bangla for every screen, chosen in this browser. It changes how the page reads, never what
 * HQ does: no request is sent, nothing is saved on the server.
 */
export function LanguageToggle({ className }: { className?: string }) {
    const locale = useLocale();

    return (
        <div role="group" aria-label="Language / ভাষা" className={cn('bg-muted inline-flex items-center rounded-md p-0.5 text-xs', className)}>
            {(['en', 'bn'] as Locale[]).map((l) => (
                <button
                    key={l}
                    type="button"
                    lang={l}
                    aria-pressed={locale === l}
                    onClick={() => setLocale(l)}
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
