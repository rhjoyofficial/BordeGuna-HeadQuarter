import { Head, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler } from 'react';

import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { PasswordInput } from '@/components/ui/password-input';
import AuthLayout from '@/layouts/auth-layout';
import { useT } from '@/lib/i18n';

type LoginForm = {
    email: string;
    password: string;
    remember: boolean;
};

interface LoginProps {
    status?: string;
    canResetPassword: boolean;
}

export default function Login({ status, canResetPassword }: LoginProps) {
    const t = useT();
    const { data, setData, post, processing, errors, reset } = useForm<LoginForm>({
        email: '',
        password: '',
        remember: false,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('login'), {
            onFinish: () => reset('password'),
        });
    };

    return (
        <AuthLayout title="Sign in" description="Your email and password first, then the code from your authenticator app.">
            <Head title="Sign in" />

            {status && (
                <div role="status" className="bg-tone-ok-bg border-tone-ok-line text-tone-ok mb-4 rounded-xl border p-3 text-sm">
                    {status}
                </div>
            )}

            <form className="flex flex-col gap-5" onSubmit={submit}>
                <div className="grid gap-2">
                    <Label htmlFor="email">{t('Email')}</Label>
                    <Input
                        id="email"
                        type="email"
                        required
                        autoFocus
                        tabIndex={1}
                        autoComplete="email"
                        inputMode="email"
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                        placeholder="you@example.com"
                    />
                    <InputError message={errors.email} />
                </div>

                <div className="grid gap-2">
                    <div className="flex items-center">
                        <Label htmlFor="password">{t('Password')}</Label>
                        {canResetPassword && (
                            <TextLink href={route('password.request')} className="ml-auto text-sm" tabIndex={4}>
                                {t('Forgot it?')}
                            </TextLink>
                        )}
                    </div>
                    <PasswordInput
                        id="password"
                        required
                        tabIndex={2}
                        autoComplete="current-password"
                        value={data.password}
                        onChange={(e) => setData('password', e.target.value)}
                        placeholder={t('Your password')}
                    />
                    <InputError message={errors.password} />
                </div>

                <div className="flex items-center gap-3">
                    <Checkbox
                        id="remember"
                        name="remember"
                        tabIndex={3}
                        checked={data.remember}
                        onCheckedChange={(v) => setData('remember', v === true)}
                    />
                    <Label htmlFor="remember" className="font-normal">
                        {t('Keep me signed in on this device')}
                    </Label>
                </div>

                <Button type="submit" className="mt-2 w-full" tabIndex={3} disabled={processing}>
                    {processing && <LoaderCircle className="size-4 animate-spin" aria-hidden />}
                    {t('Continue')}
                </Button>
            </form>
        </AuthLayout>
    );
}
