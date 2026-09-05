import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';

type Credential = {
    id: number;
    provider: string;
    account_ref: string | null;
    credential_type: string;
    status: string;
    has_payload: boolean;
    expires_at: string | null;
    last_verified_at: string | null;
    business: string | null;
    brand: string | null;
    scopes: string[] | null;
};
type Option = { id: number; name: string };

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Credentials', href: '/credentials' }];

export default function CredentialsIndex({ credentials, businesses, brands }: { credentials: Credential[]; businesses: Option[]; brands: Option[] }) {
    const { auth } = usePage<{ auth: { permissions: string[] } }>().props;
    const canManage = auth.permissions.includes('hq.credentials.manage');
    const form = useForm({ provider: '', account_ref: '', business_id: '', brand_id: '', credential_type: 'api_key', secret: '', expires_at: '' });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Integration credentials" />
            <div className="flex flex-col gap-6 p-4">
                <div className="text-muted-foreground text-sm">
                    Secrets are encrypted at rest and never shown again. This screen only tells you whether a value is present, its status and expiry.
                </div>
                {canManage && (
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post('/credentials', { onSuccess: () => form.reset() });
                        }}
                        className="grid gap-3 rounded-xl border p-4 md:grid-cols-4"
                    >
                        <div>
                            <Label htmlFor="provider">Provider</Label>
                            <Input
                                id="provider"
                                placeholder="meta, telegram, mimsms..."
                                value={form.data.provider}
                                onChange={(e) => form.setData('provider', e.target.value)}
                                required
                            />
                        </div>
                        <div>
                            <Label htmlFor="acct">Account ref</Label>
                            <Input id="acct" value={form.data.account_ref} onChange={(e) => form.setData('account_ref', e.target.value)} />
                        </div>
                        <div>
                            <Label htmlFor="biz">Business</Label>
                            <select
                                id="biz"
                                className="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                                value={form.data.business_id}
                                onChange={(e) => form.setData('business_id', e.target.value)}
                            >
                                <option value="">-</option>
                                {businesses.map((b) => (
                                    <option key={b.id} value={b.id}>
                                        {b.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <Label htmlFor="brand">Brand</Label>
                            <select
                                id="brand"
                                className="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                                value={form.data.brand_id}
                                onChange={(e) => form.setData('brand_id', e.target.value)}
                            >
                                <option value="">-</option>
                                {brands.map((b) => (
                                    <option key={b.id} value={b.id}>
                                        {b.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <Label htmlFor="type">Type</Label>
                            <select
                                id="type"
                                className="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                                value={form.data.credential_type}
                                onChange={(e) => form.setData('credential_type', e.target.value)}
                            >
                                {['api_key', 'token', 'basic', 'custom'].map((k) => (
                                    <option key={k} value={k}>
                                        {k}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="md:col-span-2">
                            <Label htmlFor="secret">Secret value</Label>
                            <Input
                                id="secret"
                                type="password"
                                autoComplete="off"
                                value={form.data.secret}
                                onChange={(e) => form.setData('secret', e.target.value)}
                                required
                            />
                        </div>
                        <div>
                            <Label htmlFor="exp">Expires at</Label>
                            <Input id="exp" type="date" value={form.data.expires_at} onChange={(e) => form.setData('expires_at', e.target.value)} />
                        </div>
                        <div className="md:col-span-4">
                            <Button type="submit" disabled={form.processing}>
                                Store encrypted
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
                                <th className="px-4 py-2">Provider</th>
                                <th className="px-4 py-2">Account</th>
                                <th className="px-4 py-2">Scope</th>
                                <th className="px-4 py-2">Type</th>
                                <th className="px-4 py-2">Status</th>
                                <th className="px-4 py-2">Secret</th>
                                <th className="px-4 py-2">Expires</th>
                                <th className="px-4 py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            {credentials.map((c) => (
                                <tr key={c.id} className="border-t">
                                    <td className="px-4 py-2 font-mono text-xs">{c.provider}</td>
                                    <td className="px-4 py-2 font-mono text-xs">{c.account_ref ?? '-'}</td>
                                    <td className="px-4 py-2 text-xs">
                                        {c.business ?? ''}
                                        {c.brand ? ` · ${c.brand}` : ''}
                                    </td>
                                    <td className="px-4 py-2">{c.credential_type}</td>
                                    <td className="px-4 py-2">{c.status}</td>
                                    <td className="px-4 py-2">{c.has_payload ? 'present' : 'absent'}</td>
                                    <td className="px-4 py-2 whitespace-nowrap">
                                        {c.expires_at ? new Date(c.expires_at).toLocaleDateString() : '-'}
                                    </td>
                                    <td className="px-4 py-2 text-right">
                                        {canManage && c.status !== 'revoked' && (
                                            <Button
                                                size="sm"
                                                variant="destructive"
                                                onClick={() => router.post(`/credentials/${c.id}/revoke`, {}, { preserveScroll: true })}
                                            >
                                                Revoke
                                            </Button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                            {credentials.length === 0 && (
                                <tr>
                                    <td colSpan={8} className="text-muted-foreground px-4 py-6 text-center">
                                        No credentials stored.
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
