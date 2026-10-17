import { useT } from '@/lib/i18n';
import { Head, Link } from '@inertiajs/react';
import { Activity, ArrowLeft, MicOff, X } from 'lucide-react';

type VoiceData = { available: false; phase: string; speech_provider_registered: boolean };

/**
 * Voice mode (docs/135 W-UI5, Joy's artboard 5), INERT by construction.
 *
 * The screen draws what voice mode will look like (the orb with its rings, the transcript, mute, show work and end)
 * and says, in place, that voice is not available: it is Phase 7, and it needs a speech provider and a ChatGPT
 * architecture ruling before anything is built (docs/135 (e), ruling item 2).
 *
 * ⛔ No microphone, no recording, no upload, no request of its own. The mute and show work buttons are drawn
 * disabled with the reason; end and back are plain links to the console. A structural test holds that no screen
 * uses a microphone or speech API. The orb's breathing stops when the person asks for reduced motion.
 *
 * ⛔ When voice mode is built, a spoken yes creates an approval card on screen and never acts (docs/89 section
 * 3.14): a spoken word gives no extra authority. The page says so, so nobody expects otherwise.
 */
export default function Voice({ voice }: { voice: VoiceData }) {
    const t = useT();
    const offReason = t('Voice is not available yet.');

    return (
        <div className="bg-background text-foreground relative isolate flex min-h-svh flex-col items-center gap-6 overflow-x-hidden px-4 pt-[calc(1.25rem+env(safe-area-inset-top))] pb-[calc(1.25rem+env(safe-area-inset-bottom))] md:gap-7 md:px-6 md:pt-6">
            <Head title="Voice mode" />
            {/* The orb breathes only when the person has not asked for reduced motion. */}
            <style>{`@media (prefers-reduced-motion: no-preference) { .hq-voice-breathe { animation: hq-voice-breathe 5s ease-in-out infinite; } } @keyframes hq-voice-breathe { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.04); } }`}</style>
            <div
                aria-hidden
                className="pointer-events-none absolute inset-0 -z-10 bg-[radial-gradient(700px_500px_at_50%_25%,rgb(124_108_246/0.22),transparent_70%),radial-gradient(900px_600px_at_15%_100%,rgb(32_178_170/0.14),transparent_60%),radial-gradient(900px_600px_at_90%_90%,rgb(255_140_90/0.10),transparent_60%)]"
            />

            <header className="flex w-full max-w-5xl flex-wrap items-center justify-between gap-2">
                <Link href="/dashboard" className="hq-glass inline-flex min-h-11 items-center gap-2 rounded-full px-4 text-sm font-medium">
                    <ArrowLeft className="size-4" aria-hidden />
                    {t('Back to the console')}
                </Link>
                <div className="flex flex-wrap gap-2 text-xs">
                    <span className="hq-glass rounded-full px-3 py-1.5">{t('Bangla and English')}</span>
                    <span className="border-hq-orange/40 text-hq-orange rounded-full border px-3 py-1.5 font-medium">{t('Not available yet')}</span>
                </div>
            </header>

            <h1 className="sr-only">{t('Voice mode')}</h1>

            <div aria-hidden className="relative flex size-56 items-center justify-center md:size-72">
                <div className="absolute inset-0 rounded-full bg-[radial-gradient(circle,rgb(124_108_246/0.28)_0%,rgb(32_178_170/0.10)_45%,transparent_70%)]" />
                <div className="border-foreground/10 absolute size-[84%] rounded-full border" />
                <div className="border-foreground/15 absolute size-[72%] rounded-full border" />
                <div className="hq-orb hq-voice-breathe size-[60%] rounded-full opacity-80 grayscale-[35%]" />
            </div>
            <p className="text-muted-foreground text-[13px] font-medium tracking-[0.08em] uppercase">{offReason}</p>

            <main className="flex w-full max-w-2xl flex-col gap-4">
                <section aria-labelledby="voice-why" className="hq-glass flex flex-col gap-2 rounded-3xl p-4 md:p-5">
                    <h2 id="voice-why" className="font-semibold">
                        {t('Why voice is off')}
                    </h2>
                    <p className="leading-relaxed">
                        {t(
                            'Voice mode belongs to Phase 7 of the HQ plan. It needs a speech provider and a ChatGPT architecture ruling before it can be built.',
                        )}
                    </p>
                    <p className="text-muted-foreground leading-relaxed">
                        {voice.speech_provider_registered
                            ? t('Speech provider: registered, but owner voice mode is still not built.')
                            : t('Speech provider: none registered.')}{' '}
                        {t('This page does not use your microphone. Nothing is listened to, recorded or uploaded, and it sends nothing anywhere.')}
                    </p>
                    <p className="text-muted-foreground leading-relaxed">
                        {t('When it is built, a spoken yes will put an approval card on the screen for you to read. It will never act on its own.')}
                    </p>
                </section>

                <section aria-labelledby="voice-transcript" className="flex flex-col gap-2">
                    <h2 id="voice-transcript" className="text-muted-foreground text-[11px] font-medium tracking-[0.06em] uppercase">
                        {t('Transcript')}
                    </h2>
                    <div className="border-glass-border text-muted-foreground flex min-h-28 flex-col justify-center gap-1 rounded-3xl border border-dashed p-4 text-center text-sm leading-relaxed">
                        <p>{t('Nothing is being listened to or recorded.')}</p>
                        <p>{t('When voice mode exists, what you say and what HQ answers will appear here, with the records it read.')}</p>
                        <p>
                            {t('To ask HQ now, type on the console.')}{' '}
                            <Link href="/dashboard" className="text-hq-violet font-medium underline underline-offset-4">
                                {t('Back to the console')}
                            </Link>
                        </p>
                    </div>
                </section>
            </main>

            <nav aria-label={t('Voice controls')} className="hq-glass mt-auto flex items-center gap-3.5 rounded-full p-2.5">
                <button
                    type="button"
                    disabled
                    aria-disabled="true"
                    aria-label={t('Microphone: not available')}
                    title={offReason}
                    className="border-glass-border text-muted-foreground inline-flex size-14 cursor-not-allowed items-center justify-center rounded-full border opacity-60"
                >
                    <MicOff className="size-5" aria-hidden />
                </button>
                <button
                    type="button"
                    disabled
                    aria-disabled="true"
                    aria-label={t('Show what HQ is doing: not available')}
                    title={offReason}
                    className="border-glass-border text-muted-foreground inline-flex size-14 cursor-not-allowed items-center justify-center rounded-full border opacity-60"
                >
                    <Activity className="size-5" aria-hidden />
                </button>
                <Link
                    href="/dashboard"
                    aria-label={t('End voice mode')}
                    title={t('End voice mode')}
                    className="inline-flex size-16 items-center justify-center rounded-full bg-[#F43F5E] text-white shadow-[0_10px_30px_rgb(244_63_94/0.4)]"
                >
                    <X className="size-6" aria-hidden />
                </Link>
            </nav>
        </div>
    );
}
