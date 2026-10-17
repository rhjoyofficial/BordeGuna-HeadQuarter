import { EmptyState } from '@/components/empty-state';
import { NARRATION_LABEL, STATE_TONE, scopeLabel, type BriefingSummary } from '@/components/observe/briefing';
import { DataTable, Note, Panel } from '@/components/observe/panels';
import { JsonBlock, PageHeader, TechnicalDetail } from '@/components/page-header';
import { InertNotice } from '@/components/ui/notice';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, humanKey } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { Newspaper } from 'lucide-react';

type Props = { briefings: BriefingSummary[]; contract: Record<string, unknown> };

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Briefings', href: '/observe/briefings' }];

export default function Briefings({ briefings, contract }: Props) {
    const t = useT();

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Briefings')} />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Briefings"
                    description="Each stored briefing with the evidence it rests on. A briefing is composed by the tool briefing.compose and never changed afterwards. The evidence bundle is not business truth: it is the bounded set of values a narrator may speak from."
                />

                {briefings.length === 0 ? (
                    <EmptyState
                        icon={Newspaper}
                        title="No briefing has been composed yet"
                        description="Nothing composes one on a schedule. When briefing.compose is run, the stored result appears here."
                    />
                ) : (
                    <Panel title="Stored briefings">
                        <DataTable
                            rowKey={(b) => b.uuid}
                            rows={briefings}
                            empty="No briefing."
                            columns={[
                                {
                                    key: 'date',
                                    label: 'Briefing date',
                                    render: (b) => (
                                        <Link href={`/observe/briefings/${b.uuid}`} className="font-medium underline-offset-2 hover:underline">
                                            {b.briefing_date}
                                        </Link>
                                    ),
                                },
                                { key: 'scope', label: 'Scope', render: (b) => b.business ?? t(scopeLabel(b)) },
                                {
                                    key: 'state',
                                    label: 'State',
                                    render: (b) => <StatusBadge tone={STATE_TONE[b.state] ?? 'neutral'}>{b.state}</StatusBadge>,
                                },
                                {
                                    key: 'reasons',
                                    label: 'Why degraded',
                                    render: (b) =>
                                        b.degraded_reasons.length === 0
                                            ? '-'
                                            : [...new Set(b.degraded_reasons.map((r) => humanKey(r.code)))].join(', '),
                                },
                                {
                                    key: 'narration',
                                    label: 'Narration',
                                    render: (b) => (NARRATION_LABEL[b.narration_state] ? t(NARRATION_LABEL[b.narration_state]) : b.narration_state),
                                },
                                { key: 'items', label: 'Items', align: 'right', render: (b) => b.items },
                                { key: 'generated', label: 'Composed', render: (b) => formatDateTime(b.generated_at) },
                            ]}
                        />
                    </Panel>
                )}

                <Panel title="The capability contract">
                    <Note>
                        The Executive Briefing is an explicit capability: a fixed task owned by HQ, an input of scope and date, and the evidence
                        bundle as the only thing a narrator may speak from. Narration sits behind the feature flag briefing.narration, which does not
                        exist as a row and is therefore off.
                    </Note>
                    <InertNotice
                        title="Narration"
                        reason="The feature flag briefing.narration has no row, so it is off. A briefing holds evidence only."
                        gate="A person creates the briefing.narration flag and switches it on, with the AI runtime on."
                    />
                    <TechnicalDetail summary="Contract">
                        <JsonBlock value={contract} />
                    </TechnicalDetail>
                </Panel>
            </div>
        </AppLayout>
    );
}
