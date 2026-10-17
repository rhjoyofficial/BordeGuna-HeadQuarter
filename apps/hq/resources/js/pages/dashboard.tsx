import { clock } from '@/components/console/age';
import { BriefMessage } from '@/components/console/brief';
import { Composer } from '@/components/console/composer';
import { Decisions, Receipts } from '@/components/console/decisions';
import { LivePanel } from '@/components/console/live-panel';
import { type ConsoleData } from '@/components/console/types';
import { type Tone } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, usePage } from '@inertiajs/react';

type Attention = { key: string; label: string; count: number; href: string; permission: string; tone: Tone };

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Today', href: '/dashboard' }];

/**
 * Today, as the chat-first console (docs/135 (c), wave W-UI2): the brief as the first message, the
 * decisions that need a person, what was decided, and Ask HQ's composer; the live panel beside it.
 *
 * ⛔ Nothing on this page decides, sends or writes. A decision card opens the approval; the composer
 * posts a question to the existing Ask HQ route and is disabled, with its reason, while Ask HQ or the
 * AI runtime is off. Every figure comes from the `console` prop; where HQ has no figure, the page says
 * so instead of showing a zero.
 */
export default function Dashboard({ attention, console: data }: { attention: Attention[]; console: ConsoleData | null }) {
    const t = useT();
    const { auth } = usePage<{ auth: { permissions: string[]; user: { name?: string } | null } }>().props;
    const permissions = auth?.permissions ?? [];

    if (data === null) {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title="Today" />
                <div className="text-muted-foreground p-6 text-sm">{t('Nothing to show.')}</div>
            </AppLayout>
        );
    }

    const hour = Number(new Date(data.generated_at).toLocaleString('en-GB', { timeZone: data.timezone, hour: '2-digit', hour12: false }));
    const firstName = auth?.user?.name?.split(' ')[0];
    const salutation = hour < 12 ? t('Good morning') : hour < 17 ? t('Good afternoon') : t('Good evening');
    const greeting = firstName ? `${salutation}, ${firstName}` : salutation;

    // What else needs a person, besides approvals: the dashboard's own attention list, each behind its screen's permission.
    const others = attention.filter((a) => !a.key.startsWith('approvals_') && a.count > 0 && permissions.includes(a.permission));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Today" />
            {/* W-UI5: on a phone the pinned composer pill sits above the bottom bar; this room under the page lets every card scroll above it. */}
            <div className="grid gap-5 p-3 pb-20 md:p-5 xl:grid-cols-[minmax(0,1fr)_20rem]">
                <div className="flex min-w-0 flex-col gap-5">
                    <header className="flex flex-wrap items-center gap-2">
                        <h1 className="text-lg font-semibold">{t('Today')}</h1>
                        <span className="hq-glass rounded-full px-2.5 py-1 text-xs">
                            {data.restricted ? t('Your businesses') : t('All businesses')}
                        </span>
                        <span className="text-muted-foreground text-xs">
                            {t('{time}, {zone}', { time: clock(data.generated_at, data.timezone), zone: data.timezone })}
                        </span>
                    </header>

                    <BriefMessage
                        brief={data.brief}
                        greeting={greeting}
                        pending={data.decisions?.total ?? null}
                        scheduler={data.live.scheduler}
                        posture={data.live.posture}
                        canOpenBriefings={permissions.includes('hq.tools.facts.read')}
                    />

                    <Decisions decisions={data.decisions} others={others} />
                    <Receipts receipts={data.receipts} />
                    <Composer composer={data.composer} />
                </div>

                <LivePanel live={data.live} generatedAt={data.generated_at} permissions={permissions} />
            </div>
        </AppLayout>
    );
}
