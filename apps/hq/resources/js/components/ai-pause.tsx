import { ConfirmDialog } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import { Notice } from '@/components/ui/notice';
import { StatusBadge } from '@/components/ui/status-badge';
import { useT } from '@/lib/i18n';
import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { ReasonText } from '@/components/reason-text';

/** What HandleInertiaRequests shares as `aiPause` (docs/141). The server decides can_pause and can_resume. */
export type AiPauseState = {
    paused: boolean;
    readable: boolean;
    paused_at: string | null;
    paused_by: string | null;
    reason: string | null;
    can_pause: boolean;
    can_resume: boolean;
    refusal: string | null;
    refusal_code?: string | null;
};

export function useAiPause(): AiPauseState | null {
    const { aiPause } = usePage<{ aiPause?: AiPauseState | null }>().props;
    return aiPause ?? null;
}

/** A banner on every page while AI is paused (docs/141): everyone sees the state, nobody has to guess. */
export function AiPauseBanner() {
    const t = useT();
    const state = useAiPause();
    if (!state || !state.paused) return null;

    return (
        <div className="px-4 pt-3">
            <Notice tone="danger" alert title={t('Pause All AI is on')}>
                {state.readable
                    ? t('No AI call is made for any business. People can still work, approve and read everything.')
                    : t('The pause state could not be read, so AI is treated as paused until a person turns it back on.')}
            </Notice>
        </div>
    );
}

/**
 * The switch on the console live panel (docs/141). Pause is one action with a reason; turning AI back on is a
 * separate action with its own reason, shown only to a person the server allows. A restricted person sees the
 * state and, when they hold the permission but are limited to some businesses, why there is no button.
 */
export function AiPauseControl() {
    const t = useT();
    const aiPause = useAiPause();
    const state = aiPause;
    const [open, setOpen] = useState(false);
    const [busy, setBusy] = useState(false);
    if (!state) return null;

    const act = (path: '/ai/pause' | '/ai/resume', reason: string) => {
        setBusy(true);
        router.post(path, { reason }, { preserveScroll: true, onFinish: () => { setBusy(false); setOpen(false); } });
    };

    return (
        <section className="flex flex-col gap-2 border-t pt-3 first:border-t-0 first:pt-0">
            <h3 className="text-muted-foreground text-[11px] font-medium tracking-[0.06em] uppercase">{t('Pause All AI')}</h3>
            <div className="flex flex-wrap items-center gap-2 text-sm">
                <StatusBadge tone={state.paused ? 'danger' : 'ok'}>{state.paused ? t('AI paused') : t('AI not paused')}</StatusBadge>
                {state.paused && state.paused_by && <span className="text-muted-foreground text-xs">{t('by {name}', { name: state.paused_by })}</span>}
            </div>
            {state.paused && state.reason && <p className="text-muted-foreground text-xs">{state.reason}</p>}
            {state.paused && aiPause.can_resume && (
                <Button type="button" size="sm" variant="outline" onClick={() => setOpen(true)}>
                    {t('Turn AI back on')}
                </Button>
            )}
            {!state.paused && aiPause.can_pause && (
                <Button type="button" size="sm" variant="destructive" onClick={() => setOpen(true)}>
                    {t('Pause All AI')}
                </Button>
            )}
            {state.refusal && (
                <div className="text-muted-foreground text-xs">
                    <ReasonText reason={{ code: state.refusal_code, text: state.refusal }} />
                </div>
            )}
            <ConfirmDialog
                open={open}
                onOpenChange={setOpen}
                busy={busy}
                destructive={!state.paused}
                title={state.paused ? t('Turn AI back on?') : t('Pause All AI?')}
                description={
                    state.paused
                        ? t('AI calls may run again for every business, under the same approvals and consent as before. It does not change any permission or switch.')
                        : t('Every AI call stops for every business at once. People can still work. Only a person with group-wide access can turn it back on.')
                }
                confirmLabel={state.paused ? t('Turn AI back on') : t('Pause All AI')}
                cancelLabel={t('Cancel')}
                reason={{ label: t('Why'), placeholder: t('What changed, and why now.'), required: true }}
                onConfirm={(reason) => act(state.paused ? '/ai/resume' : '/ai/pause', reason)}
            />
        </section>
    );
}
