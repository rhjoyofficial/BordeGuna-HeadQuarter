import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { Activity, Building2, CalendarClock, Flag, KeyRound, LayoutGrid, ScrollText, ShieldCheck, Users, Wrench } from 'lucide-react';
import AppLogo from './app-logo';

type SharedAuth = { auth: { permissions: string[] } };

const allNavItems: (NavItem & { permission?: string })[] = [
    { title: 'Dashboard', url: '/dashboard', icon: LayoutGrid, permission: 'hq.dashboard.view' },
    { title: 'Scheduler', url: '/scheduler', icon: CalendarClock, permission: 'hq.jobs.view' },
    { title: 'Businesses', url: '/businesses', icon: Building2, permission: 'hq.businesses.view' },
    { title: 'Customers', url: '/customers', icon: Users, permission: 'hq.customers.view' },
    { title: 'Tools', url: '/tools', icon: Wrench, permission: 'hq.tools.view' },
    { title: 'Credentials', url: '/credentials', icon: KeyRound, permission: 'hq.credentials.view' },
    { title: 'Feature flags', url: '/flags', icon: Flag, permission: 'hq.flags.view' },
    { title: 'Audit log', url: '/audit', icon: ScrollText, permission: 'hq.audit.view' },
    { title: 'Horizon', url: '/horizon', icon: Activity, permission: 'hq.horizon.view' },
];

const footerNavItems: NavItem[] = [{ title: 'Security', url: '/security/two-factor', icon: ShieldCheck }];

export function AppSidebar() {
    const { auth } = usePage<SharedAuth>().props;
    const permissions = auth?.permissions ?? [];
    const mainNavItems = allNavItems.filter((item) => !item.permission || permissions.includes(item.permission));

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href="/dashboard" prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
