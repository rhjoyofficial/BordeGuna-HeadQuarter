import { Field, FormRow, NativeSelect } from '@/components/form/field';
import InputError from '@/components/input-error';
import { JsonBlock, PageHeader, SectionHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

type Preview = {
    name: string;
    tool: { key: string; title: string; risk_tier: string };
    schedule: string;
    timezone: string;
    business: string | null;
    store: string | null;
    payload: Record<string, unknown>;
    first_run_local: string | null;
    first_run_utc: string | null;
    needs_approval: boolean;
    approval_note: string;
    degraded?: boolean;
};

type Result = { token: string | null; preview: Preview | Record<string, never>; questions: string[]; ai_run_id: number | null };

type Props = {
    runtime_on: boolean;
    result: Result | null;
    text: string;
    businesses: { id: number; name: string }[];
    max_text: number;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Scheduler', href: '/scheduler' },
    { title: 'Describe a job', href: '/scheduler/nl' },
];

function Row({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div className="flex flex-wrap gap-x-3 text-sm">
            <dt className="text-muted-foreground w-32 shrink-0">{label}</dt>
            <dd className="min-w-0 break-words">{children}</dd>
        </div>
    );
}

export default function SchedulerNl({ runtime_on, result, text, businesses, max_text }: Props) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const form = useForm({ text, business_id: '' });
    const [confirming, setConfirming] = useState(false);
    const preview = result?.token ? (result.preview as Preview) : null;

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/scheduler/nl/preview', { preserveScroll: true });
    };

    const confirm = () => {
        if (!result?.token) {
            return;
        }
        setConfirming(true);
        router.post('/scheduler/nl/confirm', { token: result.token }, { onFinish: () => setConfirming(false) });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Describe a job" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Describe a job"
                    description="Say what HQ should do and when, in Bangla or English. HQ reads it, checks every part against what it holds, and shows you the job before anything is created. It asks rather than guesses."
                    actions={<StatusBadge tone={runtime_on ? 'warn' : 'neutral'}>{runtime_on ? 'AI runtime on' : 'AI runtime off'}</StatusBadge>}
                />

                <form onSubmit={submit} className="hq-card flex flex-col gap-3 p-4">
                    <textarea
                        value={form.data.text}
                        onChange={(e) => form.setData('text', e.target.value)}
                        rows={3}
                        maxLength={max_text}
                        placeholder="For example: every morning at 8 check the order facts for LaLaDia"
                        className="border-input hq-field w-full rounded-md border p-2 text-sm"
                        aria-label="The job, in words"
                    />
                    <InputError message={form.errors.text ?? errors.text} />
                    <FormRow>
                        <Field label="Business, if the sentence does not say" htmlFor="nl-business" error={form.errors.business_id}>
                            <NativeSelect
                                id="nl-business"
                                value={form.data.business_id}
                                onChange={(e) => form.setData('business_id', e.target.value)}
                            >
                                <option value="">Not set</option>
                                {businesses.map((b) => (
                                    <option key={b.id} value={String(b.id)}>
                                        {b.name}
                                    </option>
                                ))}
                            </NativeSelect>
                        </Field>
                    </FormRow>
                    <div>
                        <Button type="submit" disabled={form.processing || form.data.text.trim() === ''}>
                            Read it
                        </Button>
                    </div>
                </form>

                {result && result.questions.length > 0 && (
                    <section className="flex flex-col gap-2 rounded-xl border p-4 text-sm bg-tone-pending-bg border-tone-pending-line text-tone-pending">
                        <strong>HQ needs to know more before it can create anything:</strong>
                        <ul className="list-disc pl-5">
                            {result.questions.map((q) => (
                                <li key={q}>{q}</li>
                            ))}
                        </ul>
                        <span className="text-xs">Nothing was created. Change the sentence and read it again.</span>
                    </section>
                )}

                {preview && (
                    <section className="hq-card flex flex-col gap-3 p-4">
                        <SectionHeader
                            title={preview.name}
                            aside={
                                <StatusBadge tone={preview.needs_approval ? 'pending' : 'ok'}>
                                    {preview.needs_approval ? 'will wait for approval' : 'starts when confirmed'}
                                </StatusBadge>
                            }
                        />
                        <dl className="flex flex-col gap-1.5">
                            <Row label="Runs">
                                {preview.tool.title}{' '}
                                <span className="text-muted-foreground font-mono text-xs">
                                    ({preview.tool.key}, {preview.tool.risk_tier.toUpperCase()})
                                </span>
                            </Row>
                            <Row label="When">{preview.schedule}</Row>
                            <Row label="First run">{preview.first_run_local ?? 'not scheduled'}</Row>
                            <Row label="Business">{preview.business ?? 'not set'}</Row>
                            {preview.store && <Row label="Store">{preview.store}</Row>}
                            <Row label="Arguments">
                                <JsonBlock value={preview.payload} />
                            </Row>
                        </dl>
                        <p className="text-muted-foreground text-xs">{preview.approval_note}</p>
                        {preview.degraded && (
                            <p className="text-xs text-tone-pending">Read on a lower model tier than asked for; check it closely.</p>
                        )}
                        <InputError message={errors.token} />
                        <div className="flex flex-wrap gap-2">
                            <Button onClick={confirm} disabled={confirming}>
                                Create this job
                            </Button>
                            <span className="text-muted-foreground self-center text-xs">
                                The job is created exactly as shown; the preview expires in 15 minutes.
                            </span>
                        </div>
                    </section>
                )}
            </div>
        </AppLayout>
    );
}
