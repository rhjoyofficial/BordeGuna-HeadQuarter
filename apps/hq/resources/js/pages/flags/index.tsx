import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';

type Flag = { id: number; key: string; description: string | null; environment: string; enabled: boolean; rollout: unknown };

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Feature flags', href: '/flags' }];

export default function FlagsIndex({ flags, environment }: { flags: Flag[]; environment: string }) {
    const { auth } = usePage<{ auth: { permissions: string[] } }>().props;
    const canManage = auth.permissions.includes('hq.flags.manage');
    const form = useForm<{ key: string; description: string; environment: string; enabled: boolean }>({
        key: '',
        description: '',
        environment: '*',
        enabled: false,
    });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Feature flags" />
            <div className="flex flex-col gap-6 p-4">
                <div className="text-muted-foreground text-sm">
                    Current environment: <span className="font-mono">{environment}</span>. A flag for this environment wins over the{' '}
                    <span className="font-mono">*</span> row; absent means off.
                </div>
                {canManage && (
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post('/flags', { onSuccess: () => form.reset() });
                        }}
                        className="grid gap-3 rounded-xl border p-4 md:grid-cols-4"
                    >
                        <div>
                            <Label htmlFor="key">Key</Label>
                            <Input
                                id="key"
                                placeholder="phase1.commerce_adapters"
                                value={form.data.key}
                                onChange={(e) => form.setData('key', e.target.value)}
                                required
                            />
                        </div>
                        <div>
                            <Label htmlFor="desc">Description</Label>
                            <Input id="desc" value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
                        </div>
                        <div>
                            <Label htmlFor="env">Environment</Label>
                            <select
                                id="env"
                                className="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                                value={form.data.environment}
                                onChange={(e) => form.setData('environment', e.target.value)}
                            >
                                {['*', 'production', 'staging', 'local'].map((k) => (
                                    <option key={k} value={k}>
                                        {k}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="flex items-end gap-3">
                            <label className="flex items-center gap-2 text-sm">
                                <input type="checkbox" checked={form.data.enabled} onChange={(e) => form.setData('enabled', e.target.checked)} />{' '}
                                enabled
                            </label>
                            <Button type="submit" disabled={form.processing}>
                                Save
                            </Button>
                        </div>
                        {Object.values(form.errors).length > 0 && (
                            <div className="text-sm text-red-600 md:col-span-4">{Object.values(form.errors).join(' ')}</div>
                        )}
                    </form>
                )}
                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead className="text-muted-foreground text-left text-xs uppercase">
                            <tr>
                                <th className="px-4 py-2">Key</th>
                                <th className="px-4 py-2">Environment</th>
                                <th className="px-4 py-2">Enabled</th>
                                <th className="px-4 py-2">Description</th>
                                <th className="px-4 py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            {flags.map((f) => (
                                <tr key={f.id} className="border-t">
                                    <td className="px-4 py-2 font-mono text-xs">{f.key}</td>
                                    <td className="px-4 py-2 font-mono text-xs">{f.environment}</td>
                                    <td className="px-4 py-2">
                                        {f.enabled ? <span className="text-green-700">on</span> : <span className="text-muted-foreground">off</span>}
                                    </td>
                                    <td className="px-4 py-2">{f.description}</td>
                                    <td className="px-4 py-2 text-right">
                                        {canManage && (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() => router.post(`/flags/${f.id}/toggle`, {}, { preserveScroll: true })}
                                            >
                                                {f.enabled ? 'Disable' : 'Enable'}
                                            </Button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                            {flags.length === 0 && (
                                <tr>
                                    <td colSpan={5} className="text-muted-foreground px-4 py-6 text-center">
                                        No flags yet. Later phases ship dark and are enabled here.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
        </AppLayout>
    );
}
