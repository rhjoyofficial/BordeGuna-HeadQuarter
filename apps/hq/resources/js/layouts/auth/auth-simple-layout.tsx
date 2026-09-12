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
 * and one quiet line about what this is. Deep green on tinted neutrals, the same palette as the app,
 * so the door looks like the house behind it.
 */
export default function AuthSimpleLayout({ children, title, description }: AuthLayoutProps) {
    return (
        <div className="bg-background relative flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10">
            <div className="from-primary/10 pointer-events-none absolute inset-x-0 top-0 h-64 bg-gradient-to-b to-transparent" aria-hidden />

            <div className="relative w-full max-w-sm">
                <div className="flex flex-col gap-6">
                    <Link href={route('home')} className="flex items-center justify-center gap-3 font-medium">
                        <div className="bg-primary text-primary-foreground flex size-10 items-center justify-center rounded-xl shadow-sm">
                            <AppLogoIcon className="size-6 fill-current" />
                        </div>
                        <div className="text-left leading-tight">
                            <div className="text-base font-semibold">Bor de Guna HQ</div>
                            <div className="text-muted-foreground text-xs">Group control room</div>
                        </div>
                    </Link>

                    <div className="bg-card rounded-2xl border p-6 shadow-sm sm:p-8">
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
