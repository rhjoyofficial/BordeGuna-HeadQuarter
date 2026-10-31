import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Notice } from '@/components/ui/notice';
import { StatusBadge } from '@/components/ui/status-badge';
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { PasswordInput } from '@/components/ui/password-input';
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
            <div className="flex max-w-3xl flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Two-factor authentication (TOTP)"
                    description="Use Google Authenticator, Authy, 1Password or any TOTP app."
                    actions={<StatusBadge tone={enabled ? 'ok' : pending ? 'pending' : 'warn'}>{enabled ? 'on' : pending ? 'setting up' : 'not set up'}</StatusBadge>}
                />
                {flash?.status === 'two-factor-required' && (
                    <Notice tone="pending">Two-factor authentication is mandatory in HQ. Set it up to continue.</Notice>
                )}
                <div className="hq-card p-5">
                    <h2 className="text-[0.9375rem] font-semibold tracking-tight">{enabled ? 'Your authenticator app' : 'Set up your authenticator app'}</h2>

                    {!enabled && !pending && (
                        <Button className="mt-4" onClick={() => router.post('/security/two-factor/enable')}>
                            Start setup
                        </Button>
                    )}

                    {pending && (
                        <div className="mt-4 grid gap-4 md:grid-cols-2">
                            <div className="flex flex-col items-center gap-2">
                                {qrSvg && <div className="rounded-xl bg-white p-3" dangerouslySetInnerHTML={{ __html: qrSvg }} />}
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
                                <InputError message={confirm.errors.code} />
                                <Button type="submit" disabled={confirm.processing}>
                                    Confirm and enable
                                </Button>
                            </form>
                        </div>
                    )}

                    {enabled && (
                        <div className="mt-4 text-sm">
                            <div className="text-tone-ok">Enabled{confirmedAt ? ` since ${new Date(confirmedAt).toLocaleString('en-GB')}` : ''}.</div>
                        </div>
                    )}

                    {recoveryCodes && (
                        <div className="bg-tone-pending-bg border-tone-pending-line text-tone-pending mt-4 rounded-xl border p-4">
                            <div className="text-sm font-medium">Recovery codes. Save them now; they are shown once.</div>
                            <ul className="mt-2 grid grid-cols-1 gap-1 font-mono text-sm min-[420px]:grid-cols-2">
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
                            className="hq-card p-4"
                        >
                            <div className="text-sm font-medium">Regenerate recovery codes</div>
                            <Label htmlFor="rp" className="mt-3 block">
                                Current password
                            </Label>
                            <PasswordInput id="rp" value={regen.data.password} onChange={(e) => regen.setData('password', e.target.value)} />
                            <InputError message={regen.errors.password} />
                            <Button className="mt-3" variant="outline" type="submit" disabled={regen.processing}>
                                Regenerate
                            </Button>
                        </form>
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                disable.delete('/security/two-factor');
                            }}
                            className="hq-card p-4"
                        >
                            <div className="text-sm font-medium">Disable and re-enrol</div>
                            <p className="text-muted-foreground mt-1 text-xs">
                                2FA stays mandatory: you will be asked to set it up again immediately.
                            </p>
                            <Label htmlFor="dp" className="mt-3 block">
                                Current password
                            </Label>
                            <PasswordInput id="dp" value={disable.data.password} onChange={(e) => disable.setData('password', e.target.value)} />
                            <InputError message={disable.errors.password} />
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
