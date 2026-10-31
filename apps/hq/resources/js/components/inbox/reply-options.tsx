import { Button } from '@/components/ui/button';
import { Notice } from '@/components/ui/notice';
import { Textarea } from '@/components/ui/textarea';
import { useT } from '@/lib/i18n';
import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';

/**
 * Support's reply options A, B and C for one thread (docs/143 E). A person reads them, picks one and may edit it; only that
 * final text is sent, once, as that person. The other options can never be sent. Rendered only when the flag is on.
 * Ruling 31 Oct item 4: the complete final text and the recipient are on screen beside the send button.
 */

export type ReplyOptionSet = { uuid: string; options: { label: string; text: string }[] };

export function ReplyOptions({
    sets,
    conversation,
    canManage,
    recipient,
}: {
    sets: ReplyOptionSet[];
    conversation: string;
    canManage: boolean;
    /** Ruling 31 Oct item 4: the person sees who receives the message beside the exact text, before sending. */
    recipient: string;
}) {
    const t = useT();
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const [texts, setTexts] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);

    if (sets.length === 0) return null;

    const post = (path: string, body: Record<string, string> = {}) => {
        setBusy(true);
        router.post(`/inbox/${conversation}/reply-options/${path}`, body, { preserveScroll: true, onFinish: () => setBusy(false) });
    };

    return (
        <section className="flex flex-col gap-3" aria-labelledby="reply-options-title">
            <h2 id="reply-options-title" className="text-muted-foreground text-[11px] font-medium tracking-[0.06em] uppercase">
                {t('Reply options')}
            </h2>
            {errors.options && (
                <Notice tone="danger" alert>
                    {errors.options}
                </Notice>
            )}
            {sets.map((set) => (
                <article key={set.uuid} className="hq-card flex flex-col gap-3 p-4">
                    <p className="text-muted-foreground text-xs">{t('Pick one, edit it if you like. Only the one you send goes to the customer.')}</p>
                    <p className="text-sm">
                        <span className="text-muted-foreground">{t('To')}: </span>
                        <span className="font-medium">{recipient}</span>
                    </p>
                    {set.options.map((o) => {
                        const key = `${set.uuid}:${o.label}`;
                        return (
                            <div key={key} className="flex flex-col gap-2">
                                <div className="text-sm font-medium">{t('Option {label}', { label: o.label })}</div>
                                {canManage ? (
                                    <Textarea
                                        aria-label={t('Option {label}', { label: o.label })}
                                        rows={3}
                                        value={texts[key] ?? o.text}
                                        onChange={(e) => setTexts({ ...texts, [key]: e.target.value })}
                                    />
                                ) : (
                                    <p className="text-sm whitespace-pre-wrap">{o.text}</p>
                                )}
                                {canManage && (
                                    <p className="text-muted-foreground text-xs">
                                        {t('The exact text in the box above is what is sent, to {recipient}, as you.', { recipient })}
                                    </p>
                                )}
                                {canManage && (
                                    <Button
                                        size="sm"
                                        className="self-start"
                                        disabled={busy}
                                        onClick={() => post(`${set.uuid}/choose`, { label: o.label, text: texts[key] ?? o.text })}
                                    >
                                        {t('Send option {label}', { label: o.label })}
                                    </Button>
                                )}
                            </div>
                        );
                    })}
                    {canManage && (
                        <Button size="sm" variant="ghost" className="self-start" disabled={busy} onClick={() => post(`${set.uuid}/discard`)}>
                            {t('Discard these options')}
                        </Button>
                    )}
                </article>
            ))}
        </section>
    );
}
