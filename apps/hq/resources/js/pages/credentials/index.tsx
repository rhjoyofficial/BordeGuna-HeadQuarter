import { ConfirmDialog } from '@/components/confirm-dialog';
import { EmptyState } from '@/components/empty-state';
import { Field, FormRow, NativeSelect } from '@/components/form/field';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { PasswordInput } from '@/components/ui/password-input';
import { StatusBadge, type Tone } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, humanKey, timeAgo } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { KeyRound, Plus } from 'lucide-react';
import { useState } from 'react';

type Credential = {
    id: number;
    provider: string;
    account_ref: string | null;
    credential_type: string;
    status: string;
    has_payload: boolean;
    expires_at: string | null;
    last_verified_at: string | null;
    business: string | null;
    brand: string | null;
    scopes: string[] | null;
    last_result: { at: string; ok: boolean; class: string | null; message: string | null; source: string } | null;
};
type Option = { id: number; name: string };

/** A credential kind the code reads (`CredentialKinds`): the only values the store accepts. */
type Kind = { key: string; account_ref: string; purpose: string };

/**
 * One target and one provider (ADR-043, docs/77 E.1): whether HQ would send a call, and separately whether
 * the provider last accepted the credential. Never a value.
 */
type Readiness = {
    target_kind: string;
    target: string;
    target_name: string;
    provider: string;
    account_ref: string;
    supported: boolean;
    state: string;
    reason: string;
    credential_id: number | null;
    credential_status: string | null;
    has_payload: boolean;
    last_verified_at: string | null;
    last_result: { at: string; ok: boolean; class: string | null; message: string | null; source: string } | null;
    would_attempt: boolean;
    verified: boolean;
    incident: boolean;
};

function readinessTone(r: Readiness): Tone {
    if (r.incident) return 'danger';
    if (!r.supported) return 'neutral';
    return r.verified ? 'ok' : 'warn';
}

/** What the provider last said, in a few words: "ok, 2 hours ago via tool call #763". */
function lastResult(r: { last_result: Readiness['last_result'] }): string {
    if (!r.last_result) return 'never verified';
    const what = r.last_result.ok ? 'accepted' : humanKey(r.last_result.class ?? 'failed').toLowerCase();
    return `${what} ${timeAgo(r.last_result.at)} via ${r.last_result.source.replace('_', ' ')}`;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Credentials', href: '/credentials' }];

const TYPES: Record<string, string> = { api_key: 'API key', token: 'Token', basic: 'Username and password', custom: 'Custom' };

/** Expiry as the operator needs to read it: red once passed, orange inside two weeks. */
function expiry(c: Credential): { tone: Tone; label: string } {
    if (!c.expires_at) return { tone: 'neutral', label: 'no expiry' };
    const days = (new Date(c.expires_at).getTime() - Date.now()) / 86400000;
    if (days < 0) return { tone: 'danger', label: `expired ${timeAgo(c.expires_at)}` };
    if (days < 14) return { tone: 'warn', label: `expires ${timeAgo(c.expires_at)}` };
    return { tone: 'neutral', label: `expires ${formatDateTime(c.expires_at).split(',')[0]}` };
}

function NewCredentialDialog({
    businesses,
    brands,
    kinds,
    open,
    onOpenChange,
}: {
    businesses: Option[];
    brands: Option[];
    kinds: Kind[];
    open: boolean;
    onOpenChange: (o: boolean) => void;
}) {
    const t = useT();
    const form = useForm({ provider: '', account_ref: '', business_id: '', brand_id: '', credential_type: 'api_key', secret: '', expires_at: '' });
    const kind = kinds.find((k) => k.key === form.data.provider);
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-2xl">
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/credentials', {
                            onSuccess: () => {
                                form.reset();
                                onOpenChange(false);
                            },
                        });
                    }}
                    className="flex flex-col gap-5"
                >
                    <DialogHeader>
                        <DialogTitle>Store a credential</DialogTitle>
                        <DialogDescription>
                            Storing replaces: whatever was active for this provider and account is revoked in the same transaction, so two keys can
                            never be live for one identity. The value is encrypted and never shown again.
                        </DialogDescription>
                    </DialogHeader>
                    <FormRow>
                        <Field
                            label="Provider"
                            htmlFor="provider"
                            size="md"
                            hint={kind ? t(kind.purpose) : t('Only the kinds HQ reads can be stored.')}
                            error={form.errors.provider}
                        >
                            <NativeSelect
                                id="provider"
                                value={form.data.provider}
                                onChange={(e) => form.setData('provider', e.target.value)}
                                required
                                autoFocus
                            >
                                <option value="">{t('Choose a kind')}</option>
                                {kinds.map((k) => (
                                    <option key={k.key} value={k.key}>
                                        {k.key}
                                    </option>
                                ))}
                            </NativeSelect>
                        </Field>
                        <Field
                            label="Account"
                            htmlFor="acct"
                            size="md"
                            hint={kind ? t('HQ reads it by: {rule}', { rule: t(kind.account_ref) }) : t('Optional, when a provider has several')}
                            error={form.errors.account_ref}
                        >
                            <Input id="acct" value={form.data.account_ref} onChange={(e) => form.setData('account_ref', e.target.value)} />
                        </Field>
                        <Field label="Type" htmlFor="type" size="auto" error={form.errors.credential_type}>
                            <NativeSelect
                                id="type"
                                value={form.data.credential_type}
                                onChange={(e) => form.setData('credential_type', e.target.value)}
                            >
                                {Object.entries(TYPES).map(([k, label]) => (
                                    <option key={k} value={k}>
                                        {label}
                                    </option>
                                ))}
                            </NativeSelect>
                        </Field>
                    </FormRow>
                    <FormRow>
                        <Field label="Business" htmlFor="biz" size="auto" error={form.errors.business_id}>
                            <NativeSelect id="biz" value={form.data.business_id} onChange={(e) => form.setData('business_id', e.target.value)}>
                                <option value="">group-wide</option>
                                {businesses.map((b) => (
                                    <option key={b.id} value={b.id}>
                                        {b.name}
                                    </option>
                                ))}
                            </NativeSelect>
                        </Field>
                        <Field label="Brand" htmlFor="brand" size="auto" error={form.errors.brand_id}>
                            <NativeSelect id="brand" value={form.data.brand_id} onChange={(e) => form.setData('brand_id', e.target.value)}>
                                <option value="">none</option>
                                {brands.map((b) => (
                                    <option key={b.id} value={b.id}>
                                        {b.name}
                                    </option>
                                ))}
                            </NativeSelect>
                        </Field>
                        <Field label="Expires" htmlFor="exp" size="auto" hint="Optional" error={form.errors.expires_at}>
                            <Input id="exp" type="date" value={form.data.expires_at} onChange={(e) => form.setData('expires_at', e.target.value)} />
                        </Field>
                    </FormRow>
                    <Field label="Secret value" htmlFor="secret" size="grow" error={form.errors.secret}>
                        <PasswordInput
                            id="secret"
                            autoComplete="off"
                            value={form.data.secret}
                            onChange={(e) => form.setData('secret', e.target.value)}
                            required
                        />
                    </Field>
                    <DialogFooter className="gap-2 sm:gap-0">
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            Store encrypted
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function CredentialsIndex({
    credentials,
    businesses,
    brands,
    readiness,
    kinds,
}: {
    credentials: Credential[];
    businesses: Option[];
    brands: Option[];
    readiness: Readiness[];
    kinds: Kind[];
}) {
    const incidents = readiness.filter((r) => r.incident);
    const { auth } = usePage<{ auth: { permissions: string[] } }>().props;
    const canManage = auth.permissions.includes('hq.credentials.manage');
    const [creating, setCreating] = useState(false);
    const [revoking, setRevoking] = useState<Credential | null>(null);
    const [busy, setBusy] = useState(false);

    const revoke = (c: Credential) => {
        setBusy(true);
        router.post(
            `/credentials/${c.id}/revoke`,
            {},
            {
                preserveScroll: true,
                onFinish: () => {
                    setBusy(false);
                    setRevoking(null);
                },
            },
        );
    };

    const active = credentials.filter((c) => c.status === 'active');
    const expiringSoon = active.filter((c) => c.expires_at && new Date(c.expires_at).getTime() - Date.now() < 14 * 86400000);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Integration credentials" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Credentials"
                    description="The keys HQ uses to talk to other systems. Secrets are encrypted at rest and never shown again; this screen only says whether a value is present, its status and when it expires."
                    actions={
                        <>
                            {expiringSoon.length > 0 && <StatusBadge tone="warn">{expiringSoon.length} expiring soon</StatusBadge>}
                            {canManage && (
                                <Button onClick={() => setCreating(true)}>
                                    <Plus className="size-4" aria-hidden /> Store a credential
                                </Button>
                            )}
                        </>
                    }
                />

                <section className="flex flex-col gap-2">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <h2 className="text-base font-semibold">Readiness</h2>
                            <p className="text-muted-foreground text-sm">
                                For each store, ERP business and the model provider: whether HQ would send a call, and whether the provider last
                                accepted the credential. Those are two facts: an unverified key, or an account with no credit, would still be tried
                                and would not work. A store with no connector is unsupported by design, not an incident.
                            </p>
                        </div>
                        {incidents.length > 0 ? (
                            <StatusBadge tone="danger" dot>
                                {incidents.length} incident{incidents.length === 1 ? '' : 's'}
                            </StatusBadge>
                        ) : (
                            <StatusBadge tone="ok">no incidents</StatusBadge>
                        )}
                    </div>
                    <div className="hq-table-box">
                        <table className="w-full text-sm">
                            <thead className="text-left text-xs">
                                <tr>
                                    <th className="px-3 py-2 font-medium">Target</th>
                                    <th className="px-3 py-2 font-medium">Provider</th>
                                    <th className="px-3 py-2 font-medium">State</th>
                                    <th className="px-3 py-2 font-medium">Would attempt</th>
                                    <th className="px-3 py-2 font-medium">Verified</th>
                                    <th className="px-3 py-2 font-medium">Credential</th>
                                    <th className="px-3 py-2 font-medium">Last result</th>
                                    <th className="px-3 py-2 font-medium">Why</th>
                                </tr>
                            </thead>
                            <tbody>
                                {readiness.map((r) => (
                                    <tr
                                        key={`${r.target_kind}:${r.target}:${r.provider}`}
                                        className={`border-t ${!r.supported ? 'text-muted-foreground' : ''}`}
                                    >
                                        <td className="px-3 py-2 whitespace-nowrap">
                                            <div className="font-medium">{r.target_name}</div>
                                            <div className="text-muted-foreground font-mono text-xs">
                                                {r.target_kind}:{r.target}
                                            </div>
                                        </td>
                                        <td className="px-3 py-2 text-xs whitespace-nowrap">{humanKey(r.provider)}</td>
                                        <td className="px-3 py-2 whitespace-nowrap">
                                            <StatusBadge tone={readinessTone(r)} dot={r.incident}>
                                                {humanKey(r.state).toLowerCase()}
                                            </StatusBadge>
                                        </td>
                                        <td className="px-3 py-2 text-xs whitespace-nowrap">
                                            {r.supported ? (r.would_attempt ? 'yes' : 'no') : '-'}
                                        </td>
                                        <td className="px-3 py-2 text-xs whitespace-nowrap">{r.supported ? (r.verified ? 'yes' : 'no') : '-'}</td>
                                        <td className="px-3 py-2 text-xs whitespace-nowrap">
                                            {r.credential_id === null
                                                ? 'none'
                                                : `#${r.credential_id} ${r.credential_status}${r.has_payload ? '' : ' (no value)'}`}
                                        </td>
                                        <td className="px-3 py-2 text-xs whitespace-nowrap" title={r.last_result?.message ?? ''}>
                                            {lastResult(r)}
                                        </td>
                                        <td className="px-3 py-2 text-xs">{r.reason}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>

                {credentials.length === 0 ? (
                    <EmptyState
                        icon={KeyRound}
                        title="No credentials stored"
                        description="Connectors that need a key refuse to run until one is stored here."
                    />
                ) : (
                    <div className="hq-table-box">
                        <table className="w-full text-sm">
                            <thead className="text-left text-xs">
                                <tr>
                                    <th className="px-3 py-2 font-medium">Provider</th>
                                    <th className="px-3 py-2 font-medium">Used for</th>
                                    <th className="px-3 py-2 font-medium">Type</th>
                                    <th className="px-3 py-2 font-medium">Status</th>
                                    <th className="px-3 py-2 font-medium">Expiry</th>
                                    <th className="px-3 py-2 font-medium">Last result</th>
                                    <th className="px-3 py-2"></th>
                                </tr>
                            </thead>
                            <tbody>
                                {credentials.map((c) => {
                                    const ex = expiry(c);
                                    return (
                                        <tr key={c.id} className={`border-t ${c.status === 'revoked' ? 'text-muted-foreground' : ''}`}>
                                            <td className="px-3 py-2">
                                                <div className="font-medium">{humanKey(c.provider)}</div>
                                                {c.account_ref && <div className="text-muted-foreground font-mono text-xs">{c.account_ref}</div>}
                                            </td>
                                            <td className="px-3 py-2 text-xs">
                                                {c.business ?? 'the whole group'}
                                                {c.brand ? ` · ${c.brand}` : ''}
                                            </td>
                                            <td className="px-3 py-2 text-xs whitespace-nowrap">{TYPES[c.credential_type] ?? c.credential_type}</td>
                                            <td className="px-3 py-2">
                                                <div className="flex flex-wrap items-center gap-1.5">
                                                    <StatusBadge status={c.status} />
                                                    {c.status === 'active' && !c.has_payload && <StatusBadge tone="danger">no value</StatusBadge>}
                                                </div>
                                            </td>
                                            <td className="px-3 py-2 whitespace-nowrap">
                                                {c.status === 'active' ? (
                                                    <StatusBadge tone={ex.tone} dot={ex.tone !== 'neutral'}>
                                                        {ex.label}
                                                    </StatusBadge>
                                                ) : (
                                                    <span className="text-muted-foreground text-xs">-</span>
                                                )}
                                            </td>
                                            <td className="px-3 py-2 text-xs whitespace-nowrap" title={c.last_result?.message ?? ''}>
                                                {c.status === 'active' ? lastResult(c) : <span className="text-muted-foreground">-</span>}
                                            </td>
                                            <td className="px-3 py-2 text-right">
                                                {canManage && c.status !== 'revoked' && (
                                                    <Button size="sm" variant="outline" disabled={busy} onClick={() => setRevoking(c)}>
                                                        Revoke
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

            {canManage && <NewCredentialDialog businesses={businesses} brands={brands} kinds={kinds} open={creating} onOpenChange={setCreating} />}

            <ConfirmDialog
                open={revoking !== null}
                onOpenChange={(o) => !o && setRevoking(null)}
                title={`Revoke the ${revoking ? humanKey(revoking.provider) : ''} credential?`}
                description="The stored value is erased and every connector that used it stops working until a new one is stored. This cannot be undone."
                confirmLabel="Revoke"
                destructive
                busy={busy}
                onConfirm={() => revoking && revoke(revoking)}
            />
        </AppLayout>
    );
}
