import HeadingSmall from '@/components/heading-small';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { PasswordInput } from '@/components/ui/password-input';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { formatDateTime, timeAgo } from '@/lib/format';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';

type Session = { id: string; ip: string | null; user_agent: string | null; last_active_at: string; is_current: boolean };

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Settings', href: '/settings/profile' },
    { title: 'Sessions', href: '/settings/sessions' },
];

/** "Chrome on Windows" from a user-agent string; the raw string stays in the title for the curious. */
function describeAgent(ua: string | null): string {
    if (!ua) return 'unknown device';
    const browser = /Edg\//.test(ua)
        ? 'Edge'
        : /OPR\//.test(ua)
          ? 'Opera'
          : /Chrome\//.test(ua)
            ? 'Chrome'
            : /Firefox\//.test(ua)
              ? 'Firefox'
              : /Safari\//.test(ua)
                ? 'Safari'
                : 'a browser';
    const os = /Windows/.test(ua)
        ? 'Windows'
        : /Android/.test(ua)
          ? 'Android'
          : /iPhone|iPad/.test(ua)
            ? 'iOS'
            : /Mac OS/.test(ua)
              ? 'macOS'
              : /Linux/.test(ua)
                ? 'Linux'
                : 'an unknown system';
    return `${browser} on ${os}`;
}

export default function Sessions({ sessions }: { sessions: Session[] }) {
    const form = useForm({ password: '' });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Sessions" />
            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title="Devices and sessions"
                        description="Every browser that is signed in as you. This one is marked; the others can be signed out below."
                    />
                    <div className="hq-table-box">
                        <table className="w-full text-sm">
                            <thead className="text-left text-xs">
                                <tr className="border-b">
                                    <th className="px-4 py-2 font-medium">Device</th>
                                    <th className="px-4 py-2 font-medium">Address</th>
                                    <th className="px-4 py-2 font-medium">Last active</th>
                                </tr>
                            </thead>
                            <tbody>
                                {sessions.map((s) => (
                                    <tr key={s.id} className="border-b last:border-0">
                                        <td className="px-4 py-2" title={s.user_agent ?? undefined}>
                                            <div>{describeAgent(s.user_agent)}</div>
                                            {s.is_current ? (
                                                <div className="text-xs text-tone-ok">this device</div>
                                            ) : (
                                                <div className="text-muted-foreground text-xs">another device</div>
                                            )}
                                        </td>
                                        <td className="px-4 py-2 font-mono text-xs">{s.ip ?? '-'}</td>
                                        <td className="px-4 py-2 whitespace-nowrap">
                                            <div>{timeAgo(s.last_active_at)}</div>
                                            <div className="text-muted-foreground text-xs">{formatDateTime(s.last_active_at)}</div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.delete('/settings/sessions/others', { onSuccess: () => form.reset() });
                    }}
                    className="space-y-3"
                >
                    <HeadingSmall
                        title="Sign out every other device"
                        description="Keeps this browser signed in and ends every other session. Confirm with your password."
                    />
                    <div className="grid gap-2">
                        <Label htmlFor="pw">Current password</Label>
                        <PasswordInput
                            id="pw"
                            className="max-w-sm"
                            value={form.data.password}
                            onChange={(e) => form.setData('password', e.target.value)}
                        />
                        {form.errors.password && <div className="text-sm text-tone-danger">{form.errors.password}</div>}
                    </div>
                    <Button variant="outline" type="submit" disabled={form.processing}>
                        Sign out other devices
                    </Button>
                </form>
            </SettingsLayout>
        </AppLayout>
    );
}
