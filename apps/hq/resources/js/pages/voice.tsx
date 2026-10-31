import { useT } from '@/lib/i18n';
import { postForm } from '@/lib/post-form';
import { Head, Link, router } from '@inertiajs/react';
import { Activity, ArrowLeft, Mic, MicOff, Square, Volume2, X } from 'lucide-react';
import { useRef, useState } from 'react';

type VoiceData = {
    available: boolean;
    state: string;
    sandbox: boolean;
    needs: string[];
    switch: string;
    can_use: boolean;
    phase: string;
    speech_provider_registered: boolean;
};

type Phase = 'idle' | 'recording' | 'sending';
type Line = { who: 'you' | 'hq'; text: string };

/**
 * Voice mode (docs/135 W-UI5, docs/144-voice-provider): push to talk, Bangla and English.
 *
 * ⛔ The microphone is asked for ONLY inside the click handler of the talk button (`startRecording`), never when the
 * page loads and never from an effect; and only when voice is connected (the switch on and a speech key stored). A
 * recording is sent when the person clicks stop, transcribed, and deleted by the server at once. The transcript is
 * kept only if the person ticks the box for THIS recording.
 *
 * ⛔ A spoken request adds no authority: the transcript lands in the question box on Ask HQ and the person sends it
 * from there, through the same gateway, approvals and business scope as typed text. Nothing is acted on by voice alone.
 */
export default function Voice({ voice }: { voice: VoiceData }) {
    const t = useT();
    const [phase, setPhase] = useState<Phase>('idle');
    const [language, setLanguage] = useState<'bn' | 'en'>('bn');
    const [keep, setKeep] = useState(false);
    const [lines, setLines] = useState<Line[]>([]);
    const [notice, setNotice] = useState<string | null>(null);
    const recorder = useRef<MediaRecorder | null>(null);
    const chunks = useRef<Blob[]>([]);
    const stream = useRef<MediaStream | null>(null);

    const usable = voice.available && voice.can_use;
    const offReason = !voice.can_use
        ? t('You do not have permission to use voice.')
        : voice.state === 'needs_credentials'
          ? t('Not connected yet: a speech key is needed.')
          : t('Not connected yet: voice is switched off.');

    const release = () => {
        stream.current?.getTracks().forEach((track) => track.stop());
        stream.current = null;
    };

    // Called only from the click on the talk button. The microphone is not touched anywhere else.
    const startRecording = async () => {
        setNotice(null);
        try {
            stream.current = await navigator.mediaDevices.getUserMedia({ audio: true });
        } catch {
            setNotice(t('The microphone was not allowed, so nothing was recorded.'));
            return;
        }
        chunks.current = [];
        const r = new MediaRecorder(stream.current);
        r.ondataavailable = (e) => e.data.size > 0 && chunks.current.push(e.data);
        r.onstop = () => void send(new Blob(chunks.current, { type: r.mimeType || 'audio/webm' }));
        recorder.current = r;
        r.start();
        setPhase('recording');
    };

    const stopRecording = () => {
        recorder.current?.stop();
        release();
        setPhase('sending');
    };

    const send = async (blob: Blob) => {
        const body = new FormData();
        body.append('audio', blob, 'recording.webm');
        body.append('language', language);
        body.append('keep_transcript', keep ? '1' : '0');
        const r = await postForm<{ text: string }>('/voice/transcribe', body);
        setPhase('idle');
        if (!r.ok) {
            setNotice(r.error);
            return;
        }
        setLines((l) => [...l, { who: 'you', text: r.data.text }]);
    };

    const toAsk = (text: string) => {
        try {
            sessionStorage.setItem('hq-voice-draft', text);
        } catch {
            /* the question box simply starts empty */
        }
        router.visit('/ask');
    };

    const readAloud = async (text: string) => {
        const r = await postForm<{ url: string }>('/voice/speak', { text, language: language === 'bn' ? 'bn-BD' : 'en-US' });
        if (!r.ok) {
            setNotice(r.error);
            return;
        }
        void new Audio(r.data.url).play();
    };

    const last = lines[lines.length - 1];

    return (
        <div className="bg-background text-foreground relative isolate flex min-h-svh flex-col items-center gap-6 overflow-x-hidden px-4 pt-[calc(1.25rem+env(safe-area-inset-top))] pb-[calc(1.25rem+env(safe-area-inset-bottom))] md:gap-7 md:px-6 md:pt-6">
            <Head title="Voice mode" />
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
                    <span
                        className={
                            usable
                                ? 'text-tone-ok rounded-full border px-3 py-1.5 font-medium'
                                : 'border-hq-orange/40 text-hq-orange rounded-full border px-3 py-1.5 font-medium'
                        }
                    >
                        {usable ? (voice.sandbox ? t('Sandbox only') : t('Connected')) : t('Not connected yet')}
                    </span>
                </div>
            </header>

            <h1 className="sr-only">{t('Voice mode')}</h1>

            <div aria-hidden className="relative flex size-48 items-center justify-center md:size-64">
                <div className="absolute inset-0 rounded-full bg-[radial-gradient(circle,rgb(124_108_246/0.28)_0%,rgb(32_178_170/0.10)_45%,transparent_70%)]" />
                <div className="border-foreground/10 absolute size-[84%] rounded-full border" />
                <div className="border-foreground/15 absolute size-[72%] rounded-full border" />
                <div
                    className={`hq-orb size-[60%] rounded-full ${phase === 'recording' ? 'hq-voice-breathe opacity-100' : 'opacity-70 grayscale-[35%]'}`}
                />
            </div>
            <p className="text-muted-foreground text-[13px] font-medium tracking-[0.08em] uppercase" role="status">
                {phase === 'recording'
                    ? t('Listening: click stop when you are done')
                    : phase === 'sending'
                      ? t('Turning your voice into text')
                      : usable
                        ? t('Click the microphone to talk')
                        : offReason}
            </p>

            <main className="flex w-full max-w-2xl flex-col gap-4">
                {!usable && (
                    <section aria-labelledby="voice-why" className="hq-glass flex flex-col gap-2 rounded-3xl p-4 md:p-5">
                        <h2 id="voice-why" className="font-semibold">
                            {t('Why voice is off')}
                        </h2>
                        <p className="leading-relaxed">{offReason}</p>
                        {voice.needs.length > 0 && (
                            <p className="text-muted-foreground leading-relaxed">
                                {t('Needs one of these keys on the Credentials screen:')}{' '}
                                <span className="font-mono text-xs">{voice.needs.join(', ')}</span>
                            </p>
                        )}
                        <p className="text-muted-foreground leading-relaxed">
                            {t('The microphone is asked for only when you click to talk. This page does not listen or record on its own.')}{' '}
                            <Link href="/connectors" className="text-hq-violet font-medium underline underline-offset-4">
                                {t('See Connectors')}
                            </Link>
                        </p>
                    </section>
                )}

                {usable && (
                    <section className="hq-glass flex flex-col gap-3 rounded-3xl p-4 md:p-5">
                        <div className="flex flex-wrap items-center gap-4 text-sm">
                            <label className="flex items-center gap-2">
                                {t('Language')}
                                <select
                                    className="bg-background border-input min-h-10 rounded-md border px-2"
                                    value={language}
                                    onChange={(e) => setLanguage(e.target.value as 'bn' | 'en')}
                                >
                                    <option value="bn">{t('Bangla')}</option>
                                    <option value="en">{t('English')}</option>
                                </select>
                            </label>
                            <label className="flex items-center gap-2">
                                <input type="checkbox" checked={keep} onChange={(e) => setKeep(e.target.checked)} className="size-4" />
                                {t('Keep a copy of the transcript for 30 days (only for the next recording)')}
                            </label>
                        </div>
                        <p className="text-muted-foreground text-xs">
                            {t(
                                'Your recording is sent to the speech provider and deleted straight away. A spoken request adds no authority: it goes to Ask HQ as text and you send it from there.',
                            )}
                        </p>
                    </section>
                )}

                {notice && (
                    <p role="alert" className="border-glass-border rounded-2xl border p-3 text-sm">
                        {notice}
                    </p>
                )}

                <section aria-labelledby="voice-transcript" className="flex flex-col gap-2">
                    <h2 id="voice-transcript" className="text-muted-foreground text-[11px] font-medium tracking-[0.06em] uppercase">
                        {t('Transcript')}
                    </h2>
                    {lines.length === 0 ? (
                        <div className="border-glass-border text-muted-foreground flex min-h-24 flex-col justify-center gap-1 rounded-3xl border border-dashed p-4 text-center text-sm leading-relaxed">
                            <p>
                                {usable
                                    ? t('What you say will appear here as text, for you to check.')
                                    : t('Nothing is being listened to or recorded.')}
                            </p>
                            <p>
                                {t('To ask HQ now, type on the console.')}{' '}
                                <Link href="/dashboard" className="text-hq-violet font-medium underline underline-offset-4">
                                    {t('Back to the console')}
                                </Link>
                            </p>
                        </div>
                    ) : (
                        <ol className="flex flex-col gap-2">
                            {lines.map((l, i) => (
                                <li key={i} className="hq-glass rounded-2xl p-3 text-sm whitespace-pre-line">
                                    {l.text}
                                </li>
                            ))}
                        </ol>
                    )}
                    {last && (
                        <div className="flex flex-wrap gap-2">
                            <button
                                type="button"
                                className="hq-glass min-h-11 rounded-full px-4 text-sm font-medium"
                                onClick={() => toAsk(last.text)}
                            >
                                {t('Put this in Ask HQ')}
                            </button>
                            <button
                                type="button"
                                className="hq-glass inline-flex min-h-11 items-center gap-2 rounded-full px-4 text-sm font-medium"
                                onClick={() => void readAloud(last.text)}
                            >
                                <Volume2 className="size-4" aria-hidden />
                                {t('Read it back')}
                            </button>
                        </div>
                    )}
                </section>
            </main>

            <nav aria-label={t('Voice controls')} className="hq-glass mt-auto flex items-center gap-3.5 rounded-full p-2.5">
                {usable ? (
                    phase === 'recording' ? (
                        <button
                            type="button"
                            onClick={stopRecording}
                            aria-label={t('Stop and send')}
                            className="inline-flex size-14 items-center justify-center rounded-full bg-[#F43F5E] text-white"
                        >
                            <Square className="size-5" aria-hidden />
                        </button>
                    ) : (
                        <button
                            type="button"
                            onClick={() => void startRecording()}
                            disabled={phase === 'sending'}
                            aria-label={t('Talk')}
                            className="border-glass-border inline-flex size-14 items-center justify-center rounded-full border disabled:opacity-50"
                        >
                            <Mic className="size-5" aria-hidden />
                        </button>
                    )
                ) : (
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
                )}
                <Link
                    href="/observe/posture"
                    aria-label={t('Show what HQ is doing')}
                    title={t('Show what HQ is doing')}
                    className="border-glass-border inline-flex size-14 items-center justify-center rounded-full border"
                >
                    <Activity className="size-5" aria-hidden />
                </Link>
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
