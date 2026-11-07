import { useLocale, useT } from '@/lib/i18n';
import { describeReason, type ReasonInput } from '@/lib/reasons';
import { cn } from '@/lib/utils';

/**
 * A server reason in the reader's language (ruling 1 Nov 2026): the approved message for a known code, the safe
 * generic one otherwise. The canonical code stays on the screen in small muted type, and the server's own English
 * sentence, when there is one, folds open under "Details for operators". Free text is never translated.
 */
export function ReasonText({ reason, className, showCode = true }: { reason: ReasonInput; className?: string; showCode?: boolean }) {
    const locale = useLocale();
    const t = useT();
    const view = describeReason(reason, locale);
    if (view === null) return null;

    return (
        <span className={cn('inline', className)}>
            {view.message}
            {showCode && view.known && view.code && (
                <code className="text-muted-foreground ml-1.5 align-baseline font-mono text-[10px] opacity-80" title={t('Reason code')}>
                    {view.code}
                </code>
            )}
            {showCode && view.detail && (
                <details className="text-muted-foreground mt-0.5 text-[11px]">
                    <summary className="cursor-pointer select-none">{t('Details for operators')}</summary>
                    <span className="block font-mono break-words">{view.detail}</span>
                </details>
            )}
        </span>
    );
}
