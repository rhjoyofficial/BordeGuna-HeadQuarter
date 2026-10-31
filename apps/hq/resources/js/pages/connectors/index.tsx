import { EmptyState } from '@/components/empty-state';
import { PageHeader, SectionHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Notice } from '@/components/ui/notice';
import { StatusBadge, type Tone } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, humanKey } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { Check, X } from 'lucide-react';

type Need = { kind: string; for: string; stored: boolean; businesses?: string[] };
type Provider = { capability: string; provider: string; sandbox: boolean; switch_on: boolean; needs: Need[]; credentials_stored: boolean };
type Family = { key: string; title: string; flag: string; enabled: boolean; state: string; providers: Provider[]; also_needs: string[] };
type Token = {
    id: number;
    kind: string;
    account_ref: string;
    status: string;
    expires_at: string | null;
    last_verified_at: string | null;
    scopes: string[];
    expiring_soon: boolean;
    valid: boolean | null;
};
type Meta = {
    app_id_configured: boolean;
    app_secret_stored: boolean;
    verify_token_stored: boolean;
    webhook_flag: boolean;
    health_flag: boolean;
    tokens: Token[];
};

const STATE_TONE: Record<string, Tone> = { ready: 'ok', sandbox: 'info', needs_credentials: 'pending', off: 'neutral' };

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Connectors', href: '/connectors' }];

function Yes({ on, label }: { on: boolean; label: string }) {
    return (
        <span className="inline-flex items-center gap-1 text-sm">
            {on ? <Check className="text-tone-ok size-4" aria-hidden /> : <X className="text-tone-warn size-4" aria-hidden />}
            {label}
        </span>
    );
}

/**
 * Every external capability HQ can use, and what is still missing before each works (docs/144-*). A connector needs two
 * things from a person: its switch turned on (Feature flags) and its key stored (Credentials). Nothing here calls a
 * provider or shows a secret.
 */
export default function Connectors({ families, meta, can }: { families: Family[]; meta: Meta; can: { check_tokens: boolean } }) {
    const t = useT();
    const stateLabel = (s: string) =>
        (({ ready: t('Connected'), sandbox: t('Sandbox only (no real provider)'), needs_credentials: t('Not connected yet: a key is needed'), off: t('Not connected yet: switched off') }) as Record<string, string>)[s] ?? s;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Connectors')} />
            <div className="flex max-w-6xl flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={t('Connectors')}
                    description={t(
                        'What HQ can reach outside itself, and what is still needed before each works. A connector needs its switch on and its key stored; either alone does nothing.',
                    )}
                    actions={
                        <>
                            <Button variant="outline" size="sm" asChild>
                                <Link href="/flags">{t('Feature flags')}</Link>
                            </Button>
                            <Button variant="outline" size="sm" asChild>
                                <Link href="/credentials">{t('Credentials')}</Link>
                            </Button>
                        </>
                    }
                />

                <div className="grid gap-4 md:grid-cols-2">
                    {families.map((f) => (
                        <section key={f.key} className="hq-card flex flex-col gap-3 p-4" aria-labelledby={`fam-${f.key}`}>
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <h2 id={`fam-${f.key}`} className="font-semibold">
                                    {t(f.title)}
                                </h2>
                                <StatusBadge tone={STATE_TONE[f.state] ?? 'neutral'}>{stateLabel(f.state)}</StatusBadge>
                            </div>
                            <div className="text-muted-foreground text-xs">
                                {t('Switch')}: <span className="font-mono">{f.flag}</span> ({f.enabled ? t('on') : t('off')})
                            </div>
                            {f.providers.length === 0 ? (
                                <p className="text-muted-foreground text-sm">{t('No outside provider: this runs inside HQ.')}</p>
                            ) : (
                                <ul className="flex flex-col gap-2">
                                    {f.providers.map((p) => (
                                        <li key={`${p.capability}-${p.provider}`} className="border-glass-border rounded-lg border p-2.5 text-sm">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <span className="font-medium">{humanKey(p.provider)}</span>
                                                <span className="text-muted-foreground font-mono text-xs">{p.capability}</span>
                                                {p.sandbox && <StatusBadge tone="info">{t('sandbox')}</StatusBadge>}
                                            </div>
                                            {p.needs.length > 0 && (
                                                <ul className="mt-1.5 flex flex-col gap-1">
                                                    {p.needs.map((n) => (
                                                        <li key={n.kind}>
                                                            <Yes on={n.stored} label={`${t('Key')} ${n.kind} (${n.for === 'HQ' ? t('for HQ') : t('for each business')})`} />
                                                        </li>
                                                    ))}
                                                </ul>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            )}
                            {f.also_needs.length > 0 && (
                                <ul className="text-muted-foreground list-disc pl-5 text-xs">
                                    {f.also_needs.map((n) => (
                                        <li key={n}>{t(n)}</li>
                                    ))}
                                </ul>
                            )}
                        </section>
                    ))}
                </div>

                <section className="flex flex-col gap-3" aria-labelledby="meta-health">
                    <SectionHeader title={t('Meta token health')} aside={meta.health_flag ? t('Checking is on') : t('Checking is off: the switch connectors.meta_health')} />
                    <div className="hq-card flex flex-col gap-2 p-4">
                        <div className="flex flex-wrap gap-4">
                            <Yes on={meta.app_secret_stored} label={t('App secret stored (signs and checks webhooks)')} />
                            <Yes on={meta.verify_token_stored} label={t('Webhook verify token stored')} />
                            <Yes on={meta.webhook_flag} label={t('Webhook intake is on')} />
                            <Yes on={meta.app_id_configured} label={t('App id configured (needed to inspect tokens)')} />
                        </div>
                    </div>
                    {meta.tokens.length === 0 ? (
                        <EmptyState title={t('No Meta token is stored')} description={t('Store a Page, Instagram, WhatsApp or ads token on the Credentials screen; it appears here.')} />
                    ) : (
                        <div className="hq-table-box">
                            <table className="w-full text-sm">
                                <thead className="text-left text-xs">
                                    <tr>
                                        <th className="px-3 py-2 font-medium">{t('Token')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Account')}</th>
                                        <th className="px-3 py-2 font-medium">{t('State')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Expires')}</th>
                                        <th className="px-3 py-2 font-medium">{t('Last checked')}</th>
                                        <th className="px-3 py-2 font-medium" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {meta.tokens.map((k) => (
                                        <tr key={k.id} className="border-t">
                                            <td className="px-3 py-2 font-mono text-xs">{k.kind}</td>
                                            <td className="px-3 py-2 font-mono text-xs">{k.account_ref}</td>
                                            <td className="px-3 py-2">
                                                <StatusBadge tone={k.valid === false || k.status !== 'active' ? 'danger' : k.expiring_soon ? 'warn' : k.valid ? 'ok' : 'neutral'}>
                                                    {k.valid === false || k.status !== 'active' ? t('Not valid') : k.expiring_soon ? t('Expires soon') : k.valid ? t('Valid') : t('Not checked yet')}
                                                </StatusBadge>
                                            </td>
                                            <td className="px-3 py-2">{k.expires_at ? formatDateTime(k.expires_at) : t('No expiry known')}</td>
                                            <td className="px-3 py-2">{k.last_verified_at ? formatDateTime(k.last_verified_at) : '-'}</td>
                                            <td className="px-3 py-2 text-right">
                                                {can.check_tokens && (
                                                    <Button size="sm" variant="outline" onClick={() => router.post('/connectors/meta/check', { kind: k.kind, account_ref: k.account_ref }, { preserveScroll: true })}>
                                                        {t('Check now')}
                                                    </Button>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                    <Notice tone="info">{t('Checking a token asks Meta only whether it is valid, when it expires and what it may do. The token itself never leaves the vault.')}</Notice>
                </section>
            </div>
        </AppLayout>
    );
}
