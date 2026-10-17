import { PageHeader } from '@/components/page-header';
import { Surface } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { type NavItem } from '@/types';
import { Link } from '@inertiajs/react';

const sidebarNavItems: NavItem[] = [
    {
        title: 'Profile',
        url: '/settings/profile',
        icon: null,
    },
    {
        title: 'Password',
        url: '/settings/password',
        icon: null,
    },
    {
        title: 'Appearance',
        url: '/settings/appearance',
        icon: null,
    },
    {
        title: 'Sessions',
        url: '/settings/sessions',
        icon: null,
    },
];

export default function SettingsLayout({ children }: { children: React.ReactNode }) {
    const currentPath = window.location.pathname;
    const t = useT();

    return (
        <div className="flex flex-col gap-6 p-4 md:p-6">
            <PageHeader title="Settings" description="Manage your profile and account settings" />

            <div className="flex flex-col gap-6 lg:flex-row lg:items-start lg:gap-8">
                <aside className="lg:w-48 lg:shrink-0">
                    <nav aria-label={t('Settings')} className="flex flex-wrap gap-2 lg:flex-col lg:gap-1">
                        {sidebarNavItems.map((item) => {
                            const active = currentPath === item.url;
                            return (
                                <Link
                                    key={item.url}
                                    href={item.url}
                                    prefetch
                                    aria-current={active ? 'page' : undefined}
                                    className={cn(
                                        'inline-flex h-9 items-center rounded-full px-3.5 text-sm font-medium transition-colors touch:h-11 lg:justify-start',
                                        active
                                            ? 'bg-primary text-primary-foreground'
                                            : 'border-glass-border bg-card text-muted-foreground hover:bg-accent hover:text-foreground border lg:border-transparent lg:bg-transparent',
                                    )}
                                >
                                    {t(item.title)}
                                </Link>
                            );
                        })}
                    </nav>
                </aside>

                <Surface as="section" className="min-w-0 flex-1 space-y-10 p-5 sm:p-6 md:max-w-2xl">
                    {children}
                </Surface>
            </div>
        </div>
    );
}
