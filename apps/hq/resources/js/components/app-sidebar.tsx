import { NavFooter } from '@/components/nav-footer';
import { NavMain, type NavSection } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { Activity, Bot, Building2, CalendarClock, Flag, KeyRound, LayoutGrid, MessageSquare, ScrollText, ShieldCheck, Stamp, Users, Wrench } from 'lucide-react';
import AppLogo from './app-logo';

type SharedProps = { auth: { permissions: string[] }; nav?: { badges?: Record<string, number> } };

type GatedItem = NavItem & { permission?: string; badge?: string; external?: boolean };

/**
 * The navigation, grouped by what the operator is doing rather than by table.
 *
 *   Work        what needs a person today
 *   Operations  the things HQ watches and runs
 *   Control     what HQ is allowed to do, and the switches
 *   Observe     the record
 *
 * A badge names the key in `nav.badges` the middleware shares; the count is computed inside the same
 * visibility scope the screen uses, so the sidebar never says more than the page would.
 */
const sections: { label: string; items: GatedItem[] }[] = [
    {
        label: 'Work',
        items: [
            { title: 'Today', url: '/dashboard', icon: LayoutGrid, permission: 'hq.dashboard.view' },
            { title: 'Inbox', url: '/inbox', icon: MessageSquare, permission: 'hq.inbox.view', badge: 'inbox' },
            { title: 'Approvals', url: '/approvals', icon: Stamp, permission: 'hq.approvals.view', badge: 'approvals' },
            { title: 'Agents', url: '/agents', icon: Bot, permission: 'hq.agents.view', badge: 'agents' },
        ],
    },
    {
        label: 'Operations',
        items: [
            { title: 'Scheduler', url: '/scheduler', icon: CalendarClock, permission: 'hq.jobs.view', badge: 'scheduler' },
            { title: 'Businesses', url: '/businesses', icon: Building2, permission: 'hq.businesses.view' },
            { title: 'Customers', url: '/customers', icon: Users, permission: 'hq.customers.view' },
        ],
    },
    {
        label: 'Control',
        items: [
            { title: 'Tools', url: '/tools', icon: Wrench, permission: 'hq.tools.view' },
            { title: 'Credentials', url: '/credentials', icon: KeyRound, permission: 'hq.credentials.view' },
            { title: 'Feature flags', url: '/flags', icon: Flag, permission: 'hq.flags.view' },
        ],
    },
    {
        label: 'Observe',
        items: [
            { title: 'Audit log', url: '/audit', icon: ScrollText, permission: 'hq.audit.view' },
            { title: 'Queues (Horizon)', url: '/horizon', icon: Activity, permission: 'hq.horizon.view', external: true },
        ],
    },
];

const footerNavItems: NavItem[] = [{ title: 'Security', url: '/security/two-factor', icon: ShieldCheck }];

export function AppSidebar() {
    const { auth, nav } = usePage<SharedProps>().props;
    const permissions = auth?.permissions ?? [];
    const badges = nav?.badges ?? {};

    const visible: NavSection[] = sections
        .map((s) => ({
            label: s.label,
            items: s.items
                .filter((item) => !item.permission || permissions.includes(item.permission))
                .map((item) => ({ ...item, count: item.badge ? (badges[item.badge] ?? 0) : 0 })),
        }))
        .filter((s) => s.items.length > 0);

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
                <NavMain sections={visible} />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
