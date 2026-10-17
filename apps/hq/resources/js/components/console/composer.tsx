import { NativeSelect } from '@/components/form/field';
import InputError from '@/components/input-error';
import { humanKey } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { Link, useForm } from '@inertiajs/react';
import { ArrowUp, Mic } from 'lucide-react';
import { Orb } from './brief';
import { type ComposerData } from './types';

/**
 * Ask HQ's composer on the console (docs/135 (c)). It posts to the existing `POST /ask` and nowhere
 * else, so it holds no power of its own: the question runs through the same runner, tools and
 * policies as the Ask HQ page.
 *
 * ⛔ Only Ask. Do and Plan are drawn, disabled, with the reason: agents are at A0, where HQ proposes
 * and a person acts (docs/135 (e), ruling item 1). When the permission, the `ask_hq` flag or the AI
 * runtime is missing, the box is disabled and says which one, in place.
 *
 * On a phone (W-UI5) the text box and the microphone move into a pill pinned above the bottom bar, as
 * artboard 6 draws it; the page leaves room under the conversation so no decision card is ever behind
 * it. The mode switch and every reason stay in the flow of the page, on every size. The microphone is a
 * link to the voice screen, which is inert: it uses no microphone and says why.
 */
export function Composer({ composer }: { composer: ComposerData }) {
    const t = useT();
    const firstAskable = composer.agents.find((a) => a.refusal === null)?.key ?? composer.agents[0]?.key ?? '';
    const form = useForm({ question: '', agent_key: firstAskable, business_id: '', conversation: '' });
    const chosen = composer.agents.find((a) => a.key === form.data.agent_key);

    const blocked = !composer.permitted
        ? t('Ask HQ needs the permission hq.ask.use.')
        : !composer.flag_on
          ? t('Ask HQ is switched off (flag ask_hq). A person with Feature flags access turns it on.')
          : !composer.runtime_on
            ? t('The AI runtime is off, so HQ cannot answer a question yet. The brief, the approvals and the live panel still work.')
            : null;

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        if (!composer.usable) return;
        form.post('/ask', { preserveScroll: true });
    };

    const modeReason = t('Do and Plan are off: agents are at A0, so HQ proposes and a person acts.');
    const askDisabled = form.processing || form.data.question.trim() === '' || (chosen?.refusal ?? null) !== null;
    const voiceLabel = t('Voice mode, not available yet');

    return (
        <>
            <form
                id="console-composer"
                onSubmit={submit}
                className="hq-glass flex flex-col gap-3 rounded-3xl p-3 shadow-[var(--hq-shadow-float)] md:p-4"
                aria-label={t('Instruction to HQ')}
            >
                {/* The text box sits here on a desktop; on a phone it is the pinned pill below. */}
                <div className="hidden items-start gap-3 md:flex">
                    <Orb size={24} />
                    <textarea
                        value={form.data.question}
                        onChange={(e) => form.setData('question', e.target.value)}
                        maxLength={composer.max_question}
                        rows={2}
                        disabled={!composer.usable}
                        placeholder={composer.usable ? t('Ask HQ a question, in Bangla or English') : t('Ask HQ is not available')}
                        aria-label={t('Your question')}
                        aria-describedby="console-composer-reason"
                        className="placeholder:text-muted-foreground min-h-12 w-full resize-none bg-transparent text-[15px] outline-none disabled:cursor-not-allowed"
                    />
                </div>
                <InputError message={form.errors.question ?? form.errors.agent_key ?? form.errors.business_id} />

                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div className="flex items-center gap-1 rounded-full border p-0.5" role="group" aria-label={t('Mode')}>
                        <button
                            type="button"
                            aria-pressed="true"
                            className="bg-primary text-primary-foreground touch:h-11 h-8 rounded-full px-3.5 text-xs font-semibold"
                        >
                            {t('Ask')}
                        </button>
                        <button
                            type="button"
                            disabled
                            aria-disabled="true"
                            title={modeReason}
                            className="text-muted-foreground touch:h-11 h-8 cursor-not-allowed rounded-full px-3.5 text-xs"
                        >
                            {t('Do')}
                        </button>
                        <button
                            type="button"
                            disabled
                            aria-disabled="true"
                            title={modeReason}
                            className="text-muted-foreground touch:h-11 h-8 cursor-not-allowed rounded-full px-3.5 text-xs"
                        >
                            {t('Plan')}
                        </button>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <Link
                            href="/voice"
                            aria-label={voiceLabel}
                            title={voiceLabel}
                            className="text-muted-foreground hover:text-foreground hidden size-8 items-center justify-center rounded-full border md:inline-flex"
                        >
                            <Mic className="size-4" aria-hidden />
                        </Link>
                        {composer.usable && (
                            <>
                                <NativeSelect
                                    aria-label={t('Agent')}
                                    value={form.data.agent_key}
                                    onChange={(e) => form.setData('agent_key', e.target.value)}
                                    className="touch:h-11 h-8 w-auto rounded-full text-xs"
                                >
                                    {composer.agents.map((a) => (
                                        <option key={a.key} value={a.key} disabled={a.refusal !== null}>
                                            {humanKey(a.key)}
                                        </option>
                                    ))}
                                </NativeSelect>
                                <NativeSelect
                                    aria-label={t('Business')}
                                    value={form.data.business_id}
                                    onChange={(e) => form.setData('business_id', e.target.value)}
                                    className="touch:h-11 h-8 w-auto rounded-full text-xs"
                                >
                                    <option value="">{t('Whole group')}</option>
                                    {composer.businesses.map((b) => (
                                        <option key={b.id} value={String(b.id)}>
                                            {b.name}
                                        </option>
                                    ))}
                                </NativeSelect>
                                <button
                                    type="submit"
                                    disabled={askDisabled}
                                    className="bg-primary text-primary-foreground hidden h-8 items-center rounded-full px-4 text-xs font-semibold disabled:opacity-50 md:inline-flex"
                                >
                                    {t('Ask')}
                                </button>
                            </>
                        )}
                    </div>
                </div>

                <div id="console-composer-reason" className="flex flex-col gap-1 text-xs">
                    {blocked !== null && <p className={cn('text-hq-orange')}>{blocked}</p>}
                    {chosen?.refusal && <p className="text-hq-orange">{chosen.refusal}</p>}
                    <p className="text-muted-foreground">
                        {t('Ask only reads and answers; nothing reaches a customer or another system from here.')} {modeReason}
                    </p>
                </div>
            </form>

            {/* Phone only: the pill pinned above the bottom bar (artboard 6). Its box and button belong to the form
                above through the `form` attribute, so it posts the same question to the same route and nowhere else. */}
            <div className="fixed inset-x-0 bottom-[calc(4.75rem+env(safe-area-inset-bottom))] z-30 px-3 md:hidden">
                <div className="hq-glass bg-background/85 flex items-center gap-2 rounded-full py-1 pr-1 pl-3 shadow-[var(--hq-shadow-float)]">
                    <Orb size={22} />
                    <input
                        type="text"
                        form="console-composer"
                        value={form.data.question}
                        onChange={(e) => form.setData('question', e.target.value)}
                        maxLength={composer.max_question}
                        disabled={!composer.usable}
                        placeholder={composer.usable ? t('Ask HQ a question, in Bangla or English') : t('Ask HQ is not available')}
                        aria-label={t('Your question')}
                        aria-describedby="console-composer-reason"
                        className="placeholder:text-muted-foreground h-11 min-w-0 flex-1 bg-transparent text-[15px] outline-none disabled:cursor-not-allowed"
                    />
                    {composer.usable && (
                        <button
                            type="submit"
                            form="console-composer"
                            disabled={askDisabled}
                            aria-label={t('Ask')}
                            className="inline-flex size-11 shrink-0 items-center justify-center rounded-full border disabled:opacity-50"
                        >
                            <ArrowUp className="size-5" aria-hidden />
                        </button>
                    )}
                    <Link
                        href="/voice"
                        aria-label={voiceLabel}
                        className="bg-primary text-primary-foreground inline-flex size-11 shrink-0 items-center justify-center rounded-full"
                    >
                        <Mic className="size-5" aria-hidden />
                    </Link>
                </div>
            </div>
        </>
    );
}
