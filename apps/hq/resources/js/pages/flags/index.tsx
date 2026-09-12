import { EmptyState } from '@/components/empty-state';
import { Field, FormRow, NativeSelect } from '@/components/form/field';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { humanKey } from '@/lib/format';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Flag, Plus } from 'lucide-react';
import { useState } from 'react';

type FeatureFlag = { id: number; key: string; description: string | null; environment: string; enabled: boolean; rollout: unknown };

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Feature flags', href: '/flags' }];

function NewFlagDialog({ open, onOpenChange }: { open: boolean; onOpenChange: (o: boolean) => void }) {
    const form = useForm<{ key: string; description: string; environment: string; enabled: boolean }>({
        key: '',
        description: '',
        environment: '*',
        enabled: false,
    });
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/flags', {
                            onSuccess: () => {
                                form.reset();
                                onOpenChange(false);
                            },
                        });
                    }}
                    className="flex flex-col gap-5"
                >
                    <DialogHeader>
                        <DialogTitle>New feature flag</DialogTitle>
                        <DialogDescription>Saving a key that already exists for the same environment updates it.</DialogDescription>
                    </DialogHeader>
                    <FormRow>
                        <Field label="Key" htmlFor="key" size="lg" hint="lowercase, dots and underscores" error={form.errors.key}>
                            <Input
                                id="key"
                                className="font-mono"
                                placeholder="phase3.messenger"
                                value={form.data.key}
                                onChange={(e) => form.setData('key', e.target.value)}
                                required
                                autoFocus
                            />
                        </Field>
                        <Field label="Environment" htmlFor="env" size="auto" error={form.errors.environment}>
                            <NativeSelect id="env" value={form.data.environment} onChange={(e) => form.setData('environment', e.target.value)}>
                                <option value="*">every environment</option>
                                {['production', 'staging', 'local'].map((k) => (
                                    <option key={k} value={k}>
                                        {k}
                                    </option>
                                ))}
                            </NativeSelect>
                        </Field>
                    </FormRow>
                    <Field label="What it switches" htmlFor="desc" size="grow" error={form.errors.description}>
                        <Input id="desc" value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
                    </Field>
                    <label className="flex items-center gap-2 text-sm">
                        <input type="checkbox" checked={form.data.enabled} onChange={(e) => form.setData('enabled', e.target.checked)} /> Turn it on
                        now
                    </label>
                    <DialogFooter className="gap-2 sm:gap-0">
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            Save flag
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function FlagsIndex({ flags, environment }: { flags: FeatureFlag[]; environment: string }) {
    const { auth } = usePage<{ auth: { permissions: string[] } }>().props;
    const canManage = auth.permissions.includes('hq.flags.manage');
    const [creating, setCreating] = useState(false);
    const [pending, setPending] = useState<number | null>(null);

    const toggle = (f: FeatureFlag) => {
        setPending(f.id);
        router.post(`/flags/${f.id}/toggle`, {}, { preserveScroll: true, onFinish: () => setPending(null) });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Feature flags" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Feature flags"
                    description={
                        <>
                            Switches for things that shipped dark. This is the {environment} environment: a row for {environment} wins over a row for
                            every environment, and a flag that is absent is off.
                        </>
                    }
                    actions={
                        canManage ? (
                            <Button onClick={() => setCreating(true)}>
                                <Plus className="size-4" aria-hidden /> New flag
                            </Button>
                        ) : undefined
                    }
                />

                {flags.length === 0 ? (
                    <EmptyState icon={Flag} title="No flags yet" description="Later phases ship dark and are switched on here." />
                ) : (
                    <div className="bg-card overflow-x-auto rounded-xl border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-muted-foreground text-left text-xs">
                                <tr>
                                    <th className="px-3 py-2 font-medium">Flag</th>
                                    <th className="px-3 py-2 font-medium">Environment</th>
                                    <th className="px-3 py-2 font-medium">State</th>
                                    <th className="px-3 py-2"></th>
                                </tr>
                            </thead>
                            <tbody>
                                {flags.map((f) => {
                                    const applies = f.environment === '*' || f.environment === environment;
                                    return (
                                        <tr key={f.id} className={`border-t ${applies ? '' : 'text-muted-foreground'}`}>
                                            <td className="px-3 py-2">
                                                <div className="font-medium">{f.description || humanKey(f.key)}</div>
                                                <div className="text-muted-foreground font-mono text-xs">{f.key}</div>
                                            </td>
                                            <td className="px-3 py-2 text-xs whitespace-nowrap">
                                                {f.environment === '*' ? 'every environment' : f.environment}
                                                {!applies && <span className="text-muted-foreground"> (not this one)</span>}
                                            </td>
                                            <td className="px-3 py-2">
                                                <StatusBadge status={f.enabled ? 'on' : 'off'} tone={f.enabled ? 'ok' : 'neutral'} />
                                            </td>
                                            <td className="px-3 py-2 text-right">
                                                {canManage && (
                                                    <Button
                                                        size="sm"
                                                        variant={f.enabled ? 'outline' : 'default'}
                                                        disabled={pending === f.id}
                                                        onClick={() => toggle(f)}
                                                    >
                                                        {f.enabled ? 'Turn off' : 'Turn on'}
                                                    </Button>
                                                )}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
            {canManage && <NewFlagDialog open={creating} onOpenChange={setCreating} />}
        </AppLayout>
    );
}
