import { Note, Panel, Stat, StatGrid } from '@/components/observe/panels';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateTime, timeAgo } from '@/lib/format';

/**
 * What is released here against what is only merged (docs/80 C.4). Read-only: no button, no form.
 *
 * ⛔ Every way of NOT knowing is drawn as "unknown", with its reason and its age. A missing or stale
 * reading of main must never look like "up to date", so the "ahead" tile only shows a number when the
 * server says the state is `known`.
 */
export type ReleaseState = {
    mode: 'development' | 'release';
    commit: string | null;
    records: 'not_applicable' | 'missing_directory' | 'no_record' | 'present';
    production: {
        commit: string;
        kind: string | null;
        released_at: string | null;
        previous: string | null;
        previous_known: boolean;
        dump: string | null;
        verification: 'verified' | 'unverified';
        verification_reason: string;
        verified_at: string | null;
    } | null;
    last_attempt: {
        commit: string;
        kind: string;
        status: 'failed' | 'released';
        failed_stage: string | null;
        recorded_at: string | null;
        is_running: boolean;
    } | null;
    last_verified: { commit: string; kind: string; released_at: string | null; verified_at: string | null; is_running: boolean } | null;
    main: {
        state: 'known' | 'stale' | 'missing' | 'unreadable' | 'undetermined' | 'other_release';
        reason: string | null;
        commit: string | null;
        fetched_at: string | null;
        age_minutes: number | null;
        stale_after_minutes: number;
        fetch_failing: boolean;
        ahead: number | null;
        behind: number | null;
        last_known_ahead: number | null;
        subjects: string[];
        subjects_truncated: boolean;
        migrations: string[];
        compared_with: string | null;
        basis: string | null;
    } | null;
    summary: string[];
};

const Sha = ({ value }: { value: string | null | undefined }) => <span className="font-mono text-xl break-all">{value ?? 'unknown'}</span>;

export function ReleaseStatePanel({ release }: { release: ReleaseState }) {
    const { production, main, last_verified: lastVerified, last_attempt: lastAttempt } = release;

    if (release.mode === 'development' || production === null || main === null) {
        return (
            <Panel title="Release state" aside={<StatusBadge tone="neutral">development checkout</StatusBadge>}>
                <div className="hq-card p-4 text-sm">{release.summary[0]}</div>
            </Panel>
        );
    }

    const known = main.state === 'known';
    const ahead = known ? (main.ahead ?? 0) : null;

    return (
        <Panel
            title="Release state"
            aside={
                known ? (
                    <StatusBadge tone={ahead === 0 ? 'ok' : 'info'}>{ahead === 0 ? 'nothing unreleased' : `${ahead} unreleased on main`}</StatusBadge>
                ) : (
                    <StatusBadge tone="warn">main state unknown</StatusBadge>
                )
            }
        >
            <div className="hq-card flex flex-col gap-1 p-4 text-sm">
                {release.summary.map((line) => (
                    <p key={line}>{line}</p>
                ))}
            </div>

            <StatGrid>
                <Stat
                    label="Production runs"
                    value={<Sha value={production.commit} />}
                    detail={
                        production.released_at === null
                            ? 'no release record on this host'
                            : `${production.kind === 'rollback' ? 'rolled back' : 'released'} ${formatDateTime(production.released_at)}${
                                  production.previous === null
                                      ? ''
                                      : `, after ${production.previous}${production.previous_known ? '' : ' (predecessor not verified)'}`
                              }`
                    }
                    badge={<StatusBadge tone={production.verification === 'verified' ? 'ok' : 'warn'}>{production.verification}</StatusBadge>}
                />
                <Stat
                    label="Last verified release"
                    value={<Sha value={lastVerified?.commit ?? 'none'} />}
                    detail={
                        lastVerified === null
                            ? 'no release has passed its post-release check on this host'
                            : `checked ${formatDateTime(lastVerified.verified_at)}${lastVerified.is_running ? '' : '; NOT the release that is running'}`
                    }
                    badge={lastVerified !== null && !lastVerified.is_running ? <StatusBadge tone="warn">not running</StatusBadge> : undefined}
                />
                <Stat
                    label="Main"
                    value={<Sha value={main.commit} />}
                    detail={
                        main.fetched_at === null
                            ? 'never fetched by the host'
                            : `fetched by the host ${timeAgo(main.fetched_at)}${main.fetch_failing ? '; the latest fetch failed' : ''}`
                    }
                    badge={known ? undefined : <StatusBadge tone="warn">{main.state.replace('_', ' ')}</StatusBadge>}
                />
                <Stat
                    label="Unreleased on main"
                    value={ahead === null ? 'unknown' : `${ahead} commit(s)`}
                    detail={
                        ahead === null
                            ? (main.reason ?? 'not known')
                            : ahead === 0
                              ? 'nothing merged is waiting'
                              : `${main.migrations.length} migration(s) not run here. None of it is live.`
                    }
                />
            </StatGrid>

            {lastAttempt !== null && lastAttempt.status === 'failed' && (
                <Note>
                    Last {lastAttempt.kind} attempt: <span className="font-mono">{lastAttempt.commit}</span> failed at the {lastAttempt.failed_stage}{' '}
                    stage
                    {lastAttempt.recorded_at === null ? '' : ` (${formatDateTime(lastAttempt.recorded_at)})`}.
                    {lastAttempt.is_running ? ' That image is nevertheless what is running.' : ' It is not what is running.'}
                </Note>
            )}

            {known && ahead !== null && ahead > 0 && (
                <div className="grid gap-4 xl:grid-cols-2">
                    <div className="hq-card min-w-0 p-4">
                        <div className="hq-eyebrow mb-2">Merged to main, not released ({ahead})</div>
                        <ul className="flex flex-col gap-1 text-sm">
                            {main.subjects.map((subject, i) => (
                                <li key={`${i}-${subject}`} className="break-words">
                                    {subject}
                                </li>
                            ))}
                        </ul>
                        {main.subjects_truncated && (
                            <p className="text-muted-foreground mt-2 text-xs">Only the newest {main.subjects.length} are listed.</p>
                        )}
                    </div>
                    <div className="hq-card min-w-0 p-4">
                        <div className="hq-eyebrow mb-2">
                            Migrations on main that have not run here ({main.migrations.length})
                        </div>
                        {main.migrations.length === 0 ? (
                            <p className="text-muted-foreground text-sm">None.</p>
                        ) : (
                            <ul className="flex flex-col gap-1 font-mono text-xs">
                                {main.migrations.map((name) => (
                                    <li key={name} className="break-all">
                                        {name}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                </div>
            )}

            <Note>
                The release is read from the image itself; the release record and main are written by the host (deploy.sh and the 5-minute monitor)
                into a read-only directory. HQ never calls GitHub. Main is considered unknown once the host's last successful fetch is older than{' '}
                {main.stale_after_minutes} minutes. Only commit subjects and migration file names are listed: nothing here infers what a change does.
            </Note>
        </Panel>
    );
}
