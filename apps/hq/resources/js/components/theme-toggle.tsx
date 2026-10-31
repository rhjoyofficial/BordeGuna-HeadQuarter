import { type Appearance, useAppearance } from '@/hooks/use-appearance';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { type LucideIcon, Monitor, Moon, Sun } from 'lucide-react';

const OPTIONS: { value: Appearance; icon: LucideIcon; label: string }[] = [
    { value: 'light', icon: Sun, label: 'Light' },
    { value: 'dark', icon: Moon, label: 'Dark' },
    { value: 'system', icon: Monitor, label: 'System' },
];

/**
 * Light (the daytime office theme) or dark (Joy's glass), or follow the device (docs/135 (d)). The same choice the
 * appearance settings page makes, kept in this browser like before; it changes how HQ looks, never what it does.
 */
export function ThemeToggle({ className }: { className?: string }) {
    const { appearance, updateAppearance } = useAppearance();
    const t = useT();

    return (
        <div role="group" aria-label={t('Theme')} className={cn('bg-muted inline-flex items-center rounded-full p-0.5', className)}>
            {OPTIONS.map(({ value, icon: Icon, label }) => (
                <button
                    key={value}
                    type="button"
                    aria-pressed={appearance === value}
                    aria-label={t(label)}
                    title={t(label)}
                    onClick={() => updateAppearance(value)}
                    className={cn(
                        'inline-flex size-7 items-center justify-center rounded-full transition-colors duration-(--hq-duration-fast) touch:size-11',
                        appearance === value ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground',
                    )}
                >
                    <Icon className="size-3.5" aria-hidden />
                </button>
            ))}
        </div>
    );
}
