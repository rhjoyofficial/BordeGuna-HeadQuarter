import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { type ReactNode, useEffect, useState } from 'react';

/**
 * The one dialog every decision goes through.
 *
 * It replaces `window.confirm` and `window.prompt`, which cannot show the consequence of an action in
 * a readable way, look like a browser error, and on a phone are close to unusable. A decision on an
 * R4 approval deserves a title that says what will happen, a sentence on why it matters, and, when a
 * reason is required, a box big enough to write one in.
 */
export function ConfirmDialog({
    open,
    onOpenChange,
    title,
    description,
    confirmLabel = 'Confirm',
    cancelLabel = 'Cancel',
    destructive = false,
    busy = false,
    reason,
    children,
    onConfirm,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: ReactNode;
    description?: ReactNode;
    confirmLabel?: string;
    cancelLabel?: string;
    destructive?: boolean;
    busy?: boolean;
    /** Ask for a reason. `required` refuses an empty one. The value is handed to `onConfirm`. */
    reason?: { label: ReactNode; placeholder?: string; required?: boolean };
    /** Anything else the dialog should show between the description and the buttons. */
    children?: ReactNode;
    onConfirm: (reason: string) => void;
}) {
    const [text, setText] = useState('');

    useEffect(() => {
        if (open) setText('');
    }, [open]);

    const blocked = busy || (reason?.required === true && text.trim() === '');

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    {description && <DialogDescription>{description}</DialogDescription>}
                </DialogHeader>
                {children}
                {reason && (
                    <div className="grid gap-1.5">
                        <Label htmlFor="confirm-reason">{reason.label}</Label>
                        <Textarea
                            id="confirm-reason"
                            autoFocus
                            rows={3}
                            maxLength={2000}
                            value={text}
                            onChange={(e) => setText(e.target.value)}
                            placeholder={reason.placeholder}
                        />
                    </div>
                )}
                <DialogFooter>
                    <Button type="button" variant="outline" disabled={busy} onClick={() => onOpenChange(false)}>
                        {cancelLabel}
                    </Button>
                    <Button type="button" variant={destructive ? 'destructive' : 'default'} disabled={blocked} onClick={() => onConfirm(text.trim())}>
                        {confirmLabel}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
