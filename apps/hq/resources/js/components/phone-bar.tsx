import { useSidebar } from '@/components/ui/sidebar';
import { CountPill } from '@/components/ui/status-badge';
import { useIsMobile } from '@/hooks/use-mobile';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { Link, usePage } from '@inertiajs/react';
import { LayoutGrid, Menu, MessageSquare, Stamp, type LucideIcon } from 'lucide-react';

type SharedProps = { auth: { permissions: string[] }; nav?: { badges?: Record<string, number> } };

type PhoneEntry = { title: string; url: string; icon: LucideIcon; permission: string; badge?: string };

/**
 * The phone's bottom bar (docs/135 (c) and wave W-UI5): Today, Approvals, Inbox, and Menu for everything else.
 * Each entry is the sidebar's own entry, with the same title, URL, permission and badge, so the bar never shows a
 * screen the sidebar would hide, and a test holds the two together. Menu opens the same navigation sheet, with all
 * five groups. It renders only below 768 px; on a desktop it is nothing.
 *
 * It changes where a link sits, never what HQ does: four links and one button that opens a menu.
 */
export const PHONE_ENTRIES: PhoneEntry[] = [
    { title: 'Today', url: '/dashboard', icon: LayoutGrid, permission: 'hq.dashboard.view' },
    { title: 'Approvals', url: '/approvals', icon: Stamp, permission: 'hq.approvals.view', badge: 'approvals' },
    { title: 'Inbox', url: '/inbox', icon: MessageSquare, permission: 'hq.inbox.view', badge: 'inbox' },
];

function isActive(url: string, current: string): boolean {
    if (url === '/dashboard') return current === '/dashboard' || current === '/' || current.startsWith('/dashboard?');
    return current === url || current.startsWith(`${url}/`) || current.startsWith(`${url}?`);
}

const ITEM =
    'relative flex min-h-14 min-w-11 flex-1 flex-col items-center justify-center gap-0.5 rounded-2xl px-1 text-[11px] font-medium transition-colors duration-(--hq-duration-fast)';

export function PhoneBar() {
    const isMobile = useIsMobile();
    const { setOpenMobile } = useSidebar();
    const page = usePage<SharedProps>();
    const t = useT();

    if (!isMobile) return null;

    const permissions = page.props.auth?.permissions ?? [];
    const badges = page.props.nav?.badges ?? {};
    const entries = PHONE_ENTRIES.filter((e) => permissions.includes(e.permission));

    return (
        <>
            {/* The same space at the end of every page, so the last row is never hidden behind the bar. */}
            <div aria-hidden className="h-[calc(4.5rem+env(safe-area-inset-bottom))] shrink-0" />
            <nav
                aria-label={t('Main')}
                className="hq-glass bg-background/85 fixed inset-x-0 bottom-0 z-40 rounded-t-3xl border-x-0 border-b-0 px-2 pt-1.5 pb-[calc(0.375rem+env(safe-area-inset-bottom))]"
            >
                <ul className="flex items-stretch gap-1">
                    {entries.map((e) => {
                        const active = isActive(e.url, page.url);
                        return (
                            <li key={e.url} className="flex flex-1">
                                <Link
                                    href={e.url}
                                    aria-current={active ? 'page' : undefined}
                                    className={cn(ITEM, active ? 'bg-glass-strong text-foreground' : 'text-muted-foreground hover:text-foreground')}
                                >
                                    <e.icon className={cn('size-5', active && 'text-hq-violet')} aria-hidden />
                                    <span className="max-w-full truncate">{t(e.title)}</span>
                                    {e.badge && <CountPill count={badges[e.badge] ?? 0} className="absolute top-1 right-[calc(50%-1.6rem)] ml-0" />}
                                </Link>
                            </li>
                        );
                    })}
                    <li className="flex flex-1">
                        <button type="button" onClick={() => setOpenMobile(true)} className={cn(ITEM, 'text-muted-foreground hover:text-foreground')}>
                            <Menu className="size-5" aria-hidden />
                            <span>{t('Menu')}</span>
                        </button>
                    </li>
                </ul>
            </nav>
        </>
    );
}
