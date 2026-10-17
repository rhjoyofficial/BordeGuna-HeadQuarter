import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';
import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';

export default function TwoFactorChallenge() {
    const [useRecovery, setUseRecovery] = useState(false);
    const form = useForm({ code: '', recovery_code: '' });

    return (
        <AuthLayout
            title="Two-factor authentication"
            description={useRecovery ? 'Enter one of your recovery codes.' : 'Enter the 6-digit code from your authenticator app.'}
        >
            <Head title="Two-factor challenge" />
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post('/two-factor/challenge');
                }}
                className="flex flex-col gap-4"
            >
                {!useRecovery ? (
                    <div className="grid gap-2">
                        <Label htmlFor="code">Authentication code</Label>
                        <Input
                            id="code"
                            inputMode="numeric"
                            pattern="[0-9]{6}"
                            maxLength={6}
                            autoFocus
                            autoComplete="one-time-code"
                            value={form.data.code}
                            onChange={(e) => form.setData({ code: e.target.value, recovery_code: '' })}
                        />
                    </div>
                ) : (
                    <div className="grid gap-2">
                        <Label htmlFor="recovery">Recovery code</Label>
                        <Input
                            id="recovery"
                            autoFocus
                            value={form.data.recovery_code}
                            onChange={(e) => form.setData({ code: '', recovery_code: e.target.value })}
                        />
                    </div>
                )}
                {(form.errors.code || form.errors.recovery_code) && (
                    <div role="alert" className="text-tone-danger text-sm">{form.errors.code ?? form.errors.recovery_code}</div>
                )}
                <Button type="submit" disabled={form.processing}>
                    Verify
                </Button>
                <button type="button" className="text-muted-foreground hover:text-foreground min-h-9 rounded-md text-sm underline touch:min-h-11" onClick={() => setUseRecovery(!useRecovery)}>
                    {useRecovery ? 'Use an authenticator code' : 'Use a recovery code'}
                </button>
            </form>
        </AuthLayout>
    );
}
