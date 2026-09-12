import { SidebarGroup, SidebarGroupLabel, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { CountPill } from '@/components/ui/status-badge';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/react';

export type NavSectionItem = NavItem & { count?: number; external?: boolean };
export type NavSection = { label: string; items: NavSectionItem[] };

/** Active when the current URL is the item or sits under it, so a detail page still lights its section. */
function isActive(url: string, current: string): boolean {
    if (url === '/dashboard') return current === '/dashboard' || current === '/';
    return current === url || current.startsWith(`${url}/`) || current.startsWith(`${url}?`);
}

export function NavMain({ sections = [] }: { sections: NavSection[] }) {
    const page = usePage();
    return (
        <>
            {sections.map((section) => (
                <SidebarGroup key={section.label} className="px-2 py-0">
                    <SidebarGroupLabel>{section.label}</SidebarGroupLabel>
                    <SidebarMenu>
                        {section.items.map((item) => (
                            <SidebarMenuItem key={item.title}>
                                <SidebarMenuButton asChild isActive={isActive(item.url, page.url)} tooltip={item.title}>
                                    {item.external ? (
                                        <a href={item.url} target="_blank" rel="noopener noreferrer">
                                            {item.icon && <item.icon />}
                                            <span>{item.title}</span>
                                        </a>
                                    ) : (
                                        <Link href={item.url} prefetch>
                                            {item.icon && <item.icon />}
                                            <span>{item.title}</span>
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
