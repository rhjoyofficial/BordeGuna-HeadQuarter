import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';

type Props = {
    enabled: boolean;
    pending: boolean;
    qrSvg: string | null;
    secret: string | null;
    recoveryCodes: string[] | null;
    confirmedAt: string | null;
};

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Security', href: '/security/two-factor' }];

export default function TwoFactor({ enabled, pending, qrSvg, secret, recoveryCodes, confirmedAt }: Props) {
    const { flash } = usePage<{ flash: { status: string | null } }>().props;
    const confirm = useForm({ code: '' });
    const disable = useForm({ password: '' });
    const regen = useForm({ password: '' });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Two-factor authentication" />
            <div className="mx-auto flex max-w-2xl flex-col gap-6 p-4">
                {flash?.status === 'two-factor-required' && (
                    <div className="rounded-md border border-amber-400 bg-amber-50 p-3 text-sm text-amber-900">
                        Two-factor authentication is mandatory in HQ. Set it up to continue.
                    </div>
                )}
                <div className="rounded-xl border p-4">
                    <h1 className="text-lg font-semibold">Two-factor authentication (TOTP)</h1>
                    <p className="text-muted-foreground mt-1 text-sm">Use Google Authenticator, Authy, 1Password or any TOTP app.</p>

                    {!enabled && !pending && (
                        <Button className="mt-4" onClick={() => router.post('/security/two-factor/enable')}>
                            Start setup
                        </Button>
                    )}

                    {pending && (
                        <div className="mt-4 grid gap-4 md:grid-cols-2">
                            <div className="flex flex-col items-center gap-2">
                                {qrSvg && <div className="rounded bg-white p-2" dangerouslySetInnerHTML={{ __html: qrSvg }} />}
                                <div className="text-muted-foreground text-center text-xs">
                                    Cannot scan? Enter this key: <span className="font-mono break-all">{secret}</span>
                                </div>
                            </div>
                            <form
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    confirm.post('/security/two-factor/confirm', { onSuccess: () => confirm.reset() });
                                }}
                                className="flex flex-col gap-3"
                            >
                                <Label htmlFor="code">Enter the 6-digit code from your app</Label>
                                <Input
                                    id="code"
                                    inputMode="numeric"
                                    pattern="[0-9]{6}"
                                    maxLength={6}
                                    autoFocus
                                    value={confirm.data.code}
                                    onChange={(e) => confirm.setData('code', e.target.value)}
                                />
                                {confirm.errors.code && <div className="text-sm text-red-600">{confirm.errors.code}</div>}
                                <Button type="submit" disabled={confirm.processing}>
                                    Confirm and enable
                                </Button>
                            </form>
                        </div>
                    )}

                    {enabled && (
                        <div className="mt-4 text-sm">
                            <div className="text-green-700">Enabled{confirmedAt ? ` since ${new Date(confirmedAt).toLocaleString()}` : ''}.</div>
                        </div>
                    )}

                    {recoveryCodes && (
                        <div className="mt-4 rounded-md border border-amber-400 bg-amber-50 p-3">
                            <div className="text-sm font-medium text-amber-900">Recovery codes. Save them now; they are shown once.</div>
                            <ul className="mt-2 grid grid-cols-2 gap-1 font-mono text-sm">
                                {recoveryCodes.map((c) => (
                                    <li key={c}>{c}</li>
                                ))}
                            </ul>
                        </div>
                    )}
                </div>

                {enabled && (
                    <div className="grid gap-4 md:grid-cols-2">
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                regen.post('/security/two-factor/recovery-codes', { onSuccess: () => regen.reset() });
                            }}
                            className="rounded-xl border p-4"
                        >
                            <div className="text-sm font-medium">Regenerate recovery codes</div>
                            <Label htmlFor="rp" className="mt-3 block">
                                Current password
                            </Label>
                            <Input id="rp" type="password" value={regen.data.password} onChange={(e) => regen.setData('password', e.target.value)} />
                            {regen.errors.password && <div className="text-sm text-red-600">{regen.errors.password}</div>}
                            <Button className="mt-3" variant="outline" type="submit" disabled={regen.processing}>
                                Regenerate
                            </Button>
                        </form>
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                disable.delete('/security/two-factor');
                            }}
                            className="rounded-xl border p-4"
                        >
                            <div className="text-sm font-medium">Disable and re-enrol</div>
                            <p className="text-muted-foreground mt-1 text-xs">
                                2FA stays mandatory: you will be asked to set it up again immediately.
                            </p>
                            <Label htmlFor="dp" className="mt-3 block">
                                Current password
                            </Label>
                            <Input
                                id="dp"
                                type="password"
                                value={disable.data.password}
                                onChange={(e) => disable.setData('password', e.target.value)}
                            />
                            {disable.errors.password && <div className="text-sm text-red-600">{disable.errors.password}</div>}
                            <Button className="mt-3" variant="destructive" type="submit" disabled={disable.processing}>
                                Disable
                            </Button>
                        </form>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
