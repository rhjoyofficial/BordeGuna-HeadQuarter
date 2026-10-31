import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useT } from '@/lib/i18n';
import { router } from '@inertiajs/react';
import { useState } from 'react';

/**
 * docs/145: one act that needs a reason (retire, revoke, approve, skip), in a popup. The server decides
 * whether the person may do it; this only collects the words and posts them. Errors come back as the
 * service's own sentence.
 */
export function ReasonAction({
    url,
    label,
    title,
    description,
    field = 'reason',
    extra = {},
    variant = 'outline',
    disabled = false,
}: {
    url: string;
    label: string;
    title: string;
    description?: string;
    field?: string;
    extra?: Record<string, string>;
    variant?: 'outline' | 'default' | 'ghost' | 'destructive';
    disabled?: boolean;
}) {
    const t = useT();
    const [open, setOpen] = useState(false);
    const [text, setText] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | undefined>();

    const submit = () => {
        setBusy(true);
        setError(undefined);
        router.post(
            url,
            { ...extra, [field]: text },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setOpen(false);
                    setText('');
                },
                onError: (errors) => setError(Object.values(errors)[0] as string | undefined),
                onFinish: () => setBusy(false),
            },
        );
    };

    return (
        <>
            <Button size="sm" variant={variant} disabled={disabled} onClick={() => setOpen(true)}>
                {label}
            </Button>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{title}</DialogTitle>
                        {description && <DialogDescription>{description}</DialogDescription>}
                    </DialogHeader>
                    <div className="grid gap-1.5">
                        <Label htmlFor="reason-text">{t('Reason')}</Label>
                        <Textarea id="reason-text" value={text} onChange={(e) => setText(e.target.value)} rows={3} maxLength={2000} />
                        <InputError message={error} />
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button onClick={submit} disabled={busy || text.trim() === ''}>
                            {label}
                        </Button>
                        <Button variant="ghost" onClick={() => setOpen(false)}>
                            {t('Back')}
                        </Button>
                    </div>
                </DialogContent>
            </Dialog>
        </>
    );
}

/** The form-level error a refused act comes back with, shown once at the top of the page. */
export function firstError(errors: Record<string, string>): string | null {
    const values = Object.values(errors ?? {});
    return values.length > 0 ? values[0] : null;
}
