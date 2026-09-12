import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { Eye, EyeOff } from 'lucide-react';
import * as React from 'react';

/**
 * A password box with an eye that shows or hides what was typed.
 *
 * Every password field in HQ uses this, so the toggle is in the same place on every screen: the
 * login, password confirmation, password change, the two-factor and session forms, and the secret
 * on the credentials screen. The button is outside the tab order so Tab still goes from the box to
 * the next control; it is reachable by mouse and touch, which is where a typo is being checked.
 */
const PasswordInput = React.forwardRef<HTMLInputElement, Omit<React.ComponentProps<'input'>, 'type'>>(({ className, ...props }, ref) => {
    const [visible, setVisible] = React.useState(false);
    return (
        <div className="relative">
            <Input ref={ref} type={visible ? 'text' : 'password'} className={cn('pr-10', className)} {...props} />
            <button
                type="button"
                tabIndex={-1}
                onClick={() => setVisible((v) => !v)}
                aria-label={visible ? 'Hide password' : 'Show password'}
                aria-pressed={visible}
                title={visible ? 'Hide' : 'Show'}
                className="text-muted-foreground hover:text-foreground focus-visible:ring-ring absolute inset-y-0 right-0 flex w-10 items-center justify-center rounded-r-md focus-visible:ring-2 focus-visible:outline-hidden"
            >
                {visible ? <EyeOff className="size-4" aria-hidden /> : <Eye className="size-4" aria-hidden />}
            </button>
        </div>
    );
});
PasswordInput.displayName = 'PasswordInput';

export { PasswordInput };
