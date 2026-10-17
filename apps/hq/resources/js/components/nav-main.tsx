import { SidebarGroup, SidebarGroupLabel, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { CountPill } from '@/components/ui/status-badge';
import { type NavItem } from '@/types';
import { useT } from '@/lib/i18n';
import { Link, usePage } from '@inertiajs/react';

export type NavSectionItem = NavItem & { count?: number; external?: boolean };
export type NavSection = { label: string; items: NavSectionItem[] };

/** Active when the current URL is the item or sits under it, so a detail page still lights its section. */
function isActive(url: string, current: string): boolean {
    if (url === '/dashboard') return current === '/dashboard' || current === '/';
    return current === url || current.startsWith(`${url}/`) || current.startsWith(`${url}?`);
}

/**
 * The one entry to light for this URL: the longest matching URL wins, so `/customers/quarantine` lights the
 * cross-business review and not Customers as well (docs/135 (c)).
 */
function activeUrl(sections: NavSection[], current: string): string | null {
    let best: string | null = null;
    for (const section of sections) {
        for (const item of section.items) {
            if (!item.external && isActive(item.url, current) && (best === null || item.url.length > best.length)) {
                best = item.url;
            }
        }
    }
    return best;
}

export function NavMain({ sections = [] }: { sections: NavSection[] }) {
    const page = usePage();
    const t = useT();
    const active = activeUrl(sections, page.url);
    return (
        <>
            {sections.map((section) => (
                <SidebarGroup key={section.label} className="px-2 py-0">
                    <SidebarGroupLabel>{t(section.label)}</SidebarGroupLabel>
                    <SidebarMenu>
                        {section.items.map((item) => (
                            <SidebarMenuItem key={item.title}>
                                <SidebarMenuButton asChild isActive={item.url === active} tooltip={t(item.title)}>
                                    {item.external ? (
                                        <a href={item.url} target="_blank" rel="noopener noreferrer">
                                            {item.icon && <item.icon />}
                                            <span>{t(item.title)}</span>
                                        </a>
                                    ) : (
                                        <Link href={item.url} prefetch>
                                            {item.icon && <item.icon />}
                                            <span>{t(item.title)}</span>
                                            <CountPill count={item.count ?? 0} className="group-data-[collapsible=icon]:hidden" />
                                        </Link>
                                    )}
                                </SidebarMenuButton>
                            </SidebarMenuItem>
                        ))}
                    </SidebarMenu>
                </SidebarGroup>
            ))}
        </>
    );
}
