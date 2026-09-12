import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { PasswordInput } from '@/components/ui/password-input';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';

type Session = { id: string; ip: string | null; user_agent: string | null; last_active_at: string; is_current: boolean };

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Settings', href: '/settings/profile' },
    { title: 'Sessions', href: '/settings/sessions' },
];

export default function Sessions({ sessions }: { sessions: Session[] }) {
    const form = useForm({ password: '' });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Sessions" />
            <div className="mx-auto flex max-w-3xl flex-col gap-6 p-4">
                <div className="rounded-xl border">
                    <div className="border-b px-4 py-3 text-sm font-medium">Devices and sessions</div>
                    <table className="w-full text-sm">
                        <tbody>
                            {sessions.map((s) => (
                                <tr key={s.id} className="border-b last:border-0">
                                    <td className="px-4 py-2">
                                        {s.is_current ? (
                                            <span className="text-green-700">this device</span>
                                        ) : (
                                            <span className="text-muted-foreground">other</span>
                                        )}
                                    </td>
                                    <td className="px-4 py-2 font-mono text-xs">{s.ip}</td>
                                    <td className="text-muted-foreground max-w-md truncate px-4 py-2 text-xs">{s.user_agent}</td>
                                    <td className="px-4 py-2 whitespace-nowrap">{new Date(s.last_active_at).toLocaleString()}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.delete('/settings/sessions/others', { onSuccess: () => form.reset() });
                    }}
                    className="rounded-xl border p-4"
                >
                    <div className="text-sm font-medium">Log out every other device</div>
                    <Label htmlFor="pw" className="mt-3 block">
                        Current password
                    </Label>
                    <PasswordInput id="pw" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} />
                    {form.errors.password && <div className="text-sm text-red-600">{form.errors.password}</div>}
                    <Button className="mt-3" variant="outline" type="submit" disabled={form.processing}>
                        Log out other sessions
                    </Button>
                </form>
            </div>
        </AppLayout>
    );
}
