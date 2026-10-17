import { useT } from '@/lib/i18n';
import AppLogoIcon from './app-logo-icon';

/** The company's mark and HQ's name (docs/135 (f) decision 3: the logo marks the company; the orb is HQ's voice). */
export default function AppLogo() {
    const t = useT();
    return (
        <>
            <AppLogoIcon className="h-8 w-8 shrink-0" />
            <div className="ml-2 grid flex-1 text-left">
                <span className="truncate text-[15px] leading-tight font-semibold tracking-[-0.01em]">{t('Bor de Guna HQ')}</span>
                <span className="text-muted-foreground truncate text-xs leading-tight">{t('Head office')}</span>
            </div>
        </>
    );
}
