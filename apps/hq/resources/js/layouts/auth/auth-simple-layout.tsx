import AppLogoIcon from '@/components/app-logo-icon';
import { Link } from '@inertiajs/react';

interface AuthLayoutProps {
    children: React.ReactNode;
    name?: string;
    title?: string;
    description?: string;
}

/**
 * The frame around every sign-in screen: the mark, the product name, a card that holds the form,
 * and one quiet line about what this is. The same ground and glass as the app (docs/135 (d)), so the door
 * looks like the house behind it, in the light theme and the dark one.
 */
export default function AuthSimpleLayout({ children, title, description }: AuthLayoutProps) {
    return (
        <div className="relative flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10">

            <div className="relative w-full max-w-sm">
                <div className="flex flex-col gap-6">
                    <Link href={route('home')} className="flex items-center justify-center gap-3 font-medium">
                        <div className="hq-glass flex size-11 items-center justify-center rounded-2xl">
                            <AppLogoIcon className="size-6 fill-current" />
                        </div>
                        <div className="text-left leading-tight">
                            <div className="text-base font-semibold">Bor de Guna HQ</div>
                            <div className="text-muted-foreground text-xs">Group control room</div>
                        </div>
                    </Link>

                    <div className="hq-glass rounded-3xl p-6 sm:p-8">
                        <div className="mb-6 space-y-1">
                            <h1 className="text-xl font-semibold tracking-tight">{title}</h1>
                            {description && <p className="text-muted-foreground text-sm">{description}</p>}
                        </div>
                        {children}
                    </div>

                    <p className="text-muted-foreground text-center text-xs">
                        Private to Bor de Guna. Every sign-in needs a password and a one-time code, and every action is recorded.
                    </p>
                </div>
            </div>
        </div>
    );
}
