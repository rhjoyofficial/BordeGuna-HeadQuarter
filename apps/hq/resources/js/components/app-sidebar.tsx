import { NavFooter } from '@/components/nav-footer';
import { NavMain, type NavSection } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import {
    Activity,
    Bell,
    Bot,
    Building2,
    CalendarClock,
    Coins,
    Flag,
    HeartPulse,
    KeyRound,
    LayoutGrid,
    MessageSquare,
    Newspaper,
    Scale,
    ScrollText,
    Server,
    ShieldCheck,
    SlidersHorizontal,
    Sparkles,
    Stamp,
    Users,
    Wrench,
} from 'lucide-react';
import AppLogo from './app-logo';

type SharedProps = { auth: { permissions: string[] }; nav?: { badges?: Record<string, number>; features?: Record<string, boolean> } };

type GatedItem = NavItem & { permission?: string; badge?: string; external?: boolean; feature?: string };

/**
 * The navigation, grouped by what the operator is doing rather than by table.
 *
 *   Work        what needs a person today
 *   Operations  the things HQ watches and runs
 *   Control     what HQ is allowed to do, and the switches
 *   Observe     the record: alerts, the four dashboards (docs/48 N.3), the audit log, the queues
 *
 * A badge names the key in `nav.badges` the middleware shares; the count is computed inside the same
 * visibility scope the screen uses, so the sidebar never says more than the page would.
 */
const sections: { label: string; items: GatedItem[] }[] = [
    {
        label: 'Work',
        items: [
            { title: 'Today', url: '/dashboard', icon: LayoutGrid, permission: 'hq.dashboard.view' },
            { title: 'Ask HQ', url: '/ask', icon: Sparkles, permission: 'hq.ask.use', feature: 'ask_hq' },
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
            { title: 'Alerts', url: '/observe/alerts', icon: Bell, permission: 'hq.audit.view', badge: 'alerts' },
            { title: 'AI health', url: '/observe/ai-health', icon: HeartPulse, permission: 'hq.agents.view' },
            { title: 'Cost', url: '/observe/cost', icon: Coins, permission: 'hq.ai.cost.view' },
            { title: 'Control plane', url: '/observe/control-plane', icon: SlidersHorizontal, permission: 'hq.tools.view' },
            { title: 'Autonomy evidence', url: '/observe/autonomy-evidence', icon: Scale, permission: 'hq.agents.view' },
            { title: 'Platform', url: '/observe/platform', icon: Server, permission: 'hq.horizon.view' },
            { title: 'Briefings', url: '/observe/briefings', icon: Newspaper, permission: 'hq.tools.facts.read' },
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
    const features = nav?.features ?? {};

    const visible: NavSection[] = sections
        .map((s) => ({
            label: s.label,
            items: s.items
                .filter((item) => !item.permission || permissions.includes(item.permission))
                .filter((item) => !item.feature || features[item.feature] === true)
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
