<?php

namespace App\Observe;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * What is released here, what main holds that is not, and how much of that is actually known
 * (docs/80 C.4, instruction point 16). Read-only: it reads one baked-in value and three small files
 * the HOST writes; it never runs git, never calls GitHub and never writes anything.
 *
 * Four facts, four sources, none inferred from another:
 *
 *   - the release that is running: `hq.release.commit`, baked into the image at build time;
 *   - the last release attempt and its verification: `release.json` (deploy.sh, rollback.sh);
 *   - the last VERIFIED release: `last-verified.json`, written only when the smoke check passed;
 *   - main, and what it holds beyond the running release: `main.json` (release-state-refresh.sh).
 *
 * ⛔ Absence never reads as "up to date". This project has been bitten by exactly that: a missing
 * backup directory did not fire its check, it removed the check (docs/58). So every way of NOT
 * knowing is a named state with its own sentence - development checkout, no directory, no file,
 * unreadable file, stale file, a file computed against some other release - and in every one of them
 * the "ahead" figures are withheld rather than shown as zero.
 *
 * ⛔ Nothing here says that code merged to main is production behaviour. The sentence that closes the
 * "ahead" line is "None of that is live", and it is tested.
 */
class ReleaseState
{
    private const COMMIT = '/^[0-9a-f]{7,40}$/';

    private const MAX_FILE_BYTES = 262144;

    private const MAX_SUBJECTS = 50;

    /** The public, non-sensitive part: the commit this image was built from, or null when it is not a release image. */
    public function commit(): ?string
    {
        $commit = config('hq.release.commit');

        return is_string($commit) && preg_match(self::COMMIT, $commit) === 1 ? $commit : null;
    }

    public function build(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $commit = $this->commit();

        if ($commit === null) {
            return [
                'mode' => 'development',
                'commit' => null,
                'records' => 'not_applicable',
                'production' => null,
                'last_attempt' => null,
                'last_verified' => null,
                'main' => null,
                'summary' => ['This is a development checkout, not a release image. Nothing on this screen describes production.'],
            ];
        }

        $directory = (string) config('hq.release.state_path');
        $present = $directory !== '' && is_dir($directory);
        $record = $present ? $this->read($directory.'/release.json') : null;
        $verified = $present ? $this->read($directory.'/last-verified.json') : null;

        $production = $this->production($commit, $record['data'] ?? null, $verified['data'] ?? null);
        $lastAttempt = $this->lastAttempt($commit, $record['data'] ?? null);
        $lastVerified = $this->lastVerified($commit, $verified['data'] ?? null);
        $main = $this->main($commit, $present ? $directory.'/main.json' : null, $now);

        return [
            'mode' => 'release',
            'commit' => $commit,
            'records' => ! $present ? 'missing_directory' : ($record === null && $verified === null ? 'no_record' : 'present'),
            'production' => $production,
            'last_attempt' => $lastAttempt,
            'last_verified' => $lastVerified,
            'main' => $main,
            'summary' => $this->summary($production, $lastAttempt, $lastVerified, $main, $present),
        ];
    }

    /** @param array<string, mixed>|null $record  @param array<string, mixed>|null $verified */
    private function production(string $commit, ?array $record, ?array $verified): array
    {
        $base = ['commit' => $commit, 'kind' => null, 'released_at' => null, 'previous' => null, 'previous_known' => false,
            'dump' => null, 'verification' => 'unverified', 'verified_at' => null];

        if ($verified !== null && $this->same($verified['commit'] ?? null, $commit) && ($verified['verification']['status'] ?? null) === 'passed'
            && ($verified['status'] ?? null) === 'released') {
            return [
                'verification' => 'verified',
                'verified_at' => $this->time($verified['verification']['checked_at'] ?? null),
                'verification_reason' => 'The post-release check passed: /up answered, /api/health was ok, and it named this commit.',
            ] + $this->fromRecord($base, $verified);
        }

        if ($record !== null && $this->same($record['commit'] ?? null, $commit)) {
            $check = is_array($record['verification'] ?? null) ? $record['verification'] : [];

            $reason = match (true) {
                ($record['status'] ?? null) !== 'released' => 'The release of this commit failed at the '.$this->word($record['failed_stage'] ?? null, 'unknown').' stage, yet this image is what is running.',
                ($check['status'] ?? null) === 'failed' => 'The post-release check failed: /up '.$this->word($check['up'] ?? null, 'not checked')
                    .', health '.$this->word($check['health'] ?? null, 'not checked')
                    .', health named release '.$this->word($check['health_release'] ?? null, 'none').'.',
                default => 'The post-release check never ran for this release.',
            };

            return ['verification_reason' => $reason] + $this->fromRecord($base, $record);
        }

        return $base + ['verification_reason' => 'This host has no record of releasing this commit, so when it was released and whether it was checked are unknown.'];
    }

    /**
     * The last attempt, when it is NOT simply the verified release that is running: a failure, or an
     * attempt at some other commit. A failed build leaves production on the old release, and the
     * panel has to say both halves of that.
     *
     * @param  array<string, mixed>|null  $record
     */
    private function lastAttempt(string $commit, ?array $record): ?array
    {
        if ($record === null || ! $this->isCommit($record['commit'] ?? null)) {
            return null;
        }

        $failed = ($record['status'] ?? null) !== 'released';
        $running = $this->same($record['commit'], $commit);

        if (! $failed && $running) {
            return null;
        }

        return [
            'commit' => $record['commit'],
            'kind' => $this->word($record['kind'] ?? null, 'deploy'),
            'status' => $failed ? 'failed' : 'released',
            'failed_stage' => $failed ? $this->word($record['failed_stage'] ?? null, 'unknown') : null,
            'recorded_at' => $this->time($record['recorded_at'] ?? null),
            'is_running' => $running,
        ];
    }

    /** @param array<string, mixed>|null $verified */
    private function lastVerified(string $commit, ?array $verified): ?array
    {
        if ($verified === null || ! $this->isCommit($verified['commit'] ?? null) || ($verified['verification']['status'] ?? null) !== 'passed') {
            return null;
        }

        return [
            'commit' => $verified['commit'],
            'kind' => $this->word($verified['kind'] ?? null, 'deploy'),
            'released_at' => $this->time($verified['recorded_at'] ?? null),
            'verified_at' => $this->time($verified['verification']['checked_at'] ?? null),
            'is_running' => $this->same($verified['commit'], $commit),
        ];
    }

    private function main(string $commit, ?string $file, CarbonImmutable $now): array
    {
        $staleAfter = max(1, (int) config('hq.release.main_stale_after_minutes', 90));
        $unknown = fn (string $state, string $reason, array $extra = []) => $extra + [
            'state' => $state, 'reason' => $reason, 'commit' => null, 'fetched_at' => null, 'age_minutes' => null,
            'stale_after_minutes' => $staleAfter, 'fetch_failing' => false, 'ahead' => null, 'behind' => null,
            'last_known_ahead' => null, 'subjects' => [], 'subjects_truncated' => false, 'migrations' => [], 'compared_with' => null, 'basis' => null,
        ];

        if ($file === null) {
            return $unknown('missing', 'The release-state directory is not mounted into this container, so nothing is known about main.');
        }

        $read = $this->read($file);

        if ($read === null) {
            return $unknown('missing', 'The host has not written main.json: the refresher has never run here, or the directory was replaced.');
        }

        if ($read['data'] === null) {
            return $unknown('unreadable', 'main.json is present but could not be read as JSON.');
        }

        $data = $read['data'];
        $fetchedAt = $this->time($data['fetched_at'] ?? null);
        $age = $fetchedAt === null ? null : max(0, (int) CarbonImmutable::parse($fetchedAt)->diffInMinutes($now));
        $mainCommit = $this->isCommit($data['main_commit'] ?? null) ? $data['main_commit'] : null;
        $ahead = is_int($data['ahead'] ?? null) && $data['ahead'] >= 0 ? $data['ahead'] : null;
        $behind = is_int($data['behind'] ?? null) && $data['behind'] >= 0 ? $data['behind'] : null;
        $common = [
            'commit' => $mainCommit, 'fetched_at' => $fetchedAt, 'age_minutes' => $age,
            'fetch_failing' => ($data['fetch_ok'] ?? null) === false,
            'compared_with' => $this->isCommit($data['release_commit'] ?? null) ? $data['release_commit'] : null,
            'basis' => $this->word($data['release_basis'] ?? null, null),
        ];

        // Old, or never successfully fetched: the last figure is kept beside its age, never as the answer.
        if ($age === null || $age > $staleAfter) {
            return $unknown('stale', $age === null
                ? 'The host has never fetched main successfully.'
                : 'The host last fetched main '.$this->age($age).' ago.', ['last_known_ahead' => $ahead] + $common);
        }

        if ($mainCommit === null || $ahead === null) {
            return $unknown('undetermined', is_string($data['reason'] ?? null) && $data['reason'] !== ''
                ? mb_substr($data['reason'], 0, 300)
                : 'The host could not compare main with the running release.', $common);
        }

        // Computed against a release other than the one answering this request (a deploy in the last
        // few minutes, or a label the host read differently). The figures describe that release, not this.
        if (! $this->same($common['compared_with'], $commit)) {
            return $unknown('other_release', 'The host compared main with '.($common['compared_with'] ?? 'an unknown release').', not with the release that is running here.', $common);
        }

        $subjects = array_values(array_filter(is_array($data['subjects'] ?? null) ? $data['subjects'] : [], 'is_string'));
        $migrations = array_values(array_filter(is_array($data['migrations'] ?? null) ? $data['migrations'] : [],
            fn ($name) => is_string($name) && preg_match('/^[A-Za-z0-9_.\-]{1,200}\.php$/', $name) === 1));

        return [
            'state' => 'known', 'reason' => null, 'stale_after_minutes' => $staleAfter,
            'ahead' => $ahead, 'behind' => $behind ?? 0, 'last_known_ahead' => $ahead,
            'subjects' => array_map(fn (string $s) => mb_substr($s, 0, 200), array_slice($subjects, 0, self::MAX_SUBJECTS)),
            'subjects_truncated' => ($data['subjects_truncated'] ?? false) === true || count($subjects) > self::MAX_SUBJECTS || $ahead > count($subjects),
            'migrations' => $migrations,
        ] + $common;
    }

    /** Plain sentences, built here so they are tested rather than assembled differently on every screen. */
    private function summary(array $production, ?array $lastAttempt, ?array $lastVerified, array $main, bool $present): array
    {
        $lines = [];
        $commit = $production['commit'];

        $lines[] = $production['released_at'] === null
            ? "Production runs {$commit}."
            : "Production runs {$commit}, ".($production['kind'] === 'rollback' ? 'rolled back to' : 'released').' '.$this->date($production['released_at']).'.';

        $lines[] = $production['verification'] === 'verified'
            ? 'That release is verified (checked '.$this->date($production['verified_at']).').'
            : 'That release is UNVERIFIED. '.$production['verification_reason'];

        if ($lastAttempt !== null && $lastAttempt['status'] === 'failed' && ! $lastAttempt['is_running']) {
            $lines[] = "The last {$lastAttempt['kind']} attempt, of {$lastAttempt['commit']}".($lastAttempt['recorded_at'] === null ? '' : ' on '.$this->date($lastAttempt['recorded_at']))
                .", FAILED at the {$lastAttempt['failed_stage']} stage. It is not what is running.";
        }

        if ($lastAttempt !== null && $lastAttempt['status'] === 'released' && ! $lastAttempt['is_running']) {
            $lines[] = "The host's last release record is of {$lastAttempt['commit']}, which is not the commit answering here.";
        }

        if ($lastVerified === null) {
            $lines[] = $present ? 'No release has been verified on this host yet.' : 'The release records are not mounted into this container, so the last verified release is unknown.';
        } elseif (! $lastVerified['is_running']) {
            $lines[] = "The last verified release is {$lastVerified['commit']} (checked ".$this->date($lastVerified['verified_at']).'), which is not the one running.';
        }

        $lines[] = match ($main['state']) {
            'known' => $this->aheadSentence($main),
            'stale' => 'Main state unknown: '.lcfirst($main['reason'])
                .($main['last_known_ahead'] === null ? '' : " At that time main was {$main['last_known_ahead']} commit(s) ahead; it may be further ahead now."),
            default => 'Main state unknown: '.lcfirst($main['reason']),
        };

        return $lines;
    }

    private function aheadSentence(array $main): string
    {
        $behind = $main['behind'] > 0 ? " Production also runs {$main['behind']} commit(s) that are not on main." : '';

        if ($main['ahead'] === 0) {
            return "Main ({$main['commit']}) holds nothing that is not released.".$behind;
        }

        $migrations = count($main['migrations']);

        return "Main ({$main['commit']}) is {$main['ahead']} commit(s) ahead"
            .($migrations > 0 ? ", including {$migrations} migration(s) that have not run here" : ', with no new migrations')
            .'. None of that is live.'.$behind;
    }

    /**
     * Null when the file is not there; `data` null when it is there and is not a JSON object.
     *
     * @return array{data: array<string, mixed>|null}|null
     */
    private function read(string $file): ?array
    {
        if (! is_file($file)) {
            return null;
        }

        try {
            $raw = @file_get_contents($file, false, null, 0, self::MAX_FILE_BYTES);
            $data = is_string($raw) ? json_decode($raw, true) : null;
        } catch (Throwable) {
            $data = null;
        }

        return ['data' => is_array($data) && ($data['schema'] ?? null) === 1 ? $data : null];
    }

    /** @param array<string, mixed> $record */
    private function fromRecord(array $base, array $record): array
    {
        return [
            'commit' => $base['commit'],
            'kind' => $this->word($record['kind'] ?? null, 'deploy'),
            'released_at' => $this->time($record['recorded_at'] ?? null),
            'previous' => $this->isCommit($record['previous'] ?? null) ? $record['previous'] : null,
            'previous_known' => ($record['previous_known'] ?? false) === true,
            'dump' => is_string($record['dump'] ?? null) && $record['dump'] !== '' ? mb_substr($record['dump'], 0, 300) : null,
        ] + $base;
    }

    private function isCommit(mixed $value): bool
    {
        return is_string($value) && preg_match(self::COMMIT, $value) === 1;
    }

    /** The same commit, whatever length each side abbreviated it to. */
    private function same(mixed $a, mixed $b): bool
    {
        return $this->isCommit($a) && $this->isCommit($b) && (str_starts_with($a, $b) || str_starts_with($b, $a));
    }

    /** A short token from a host-written file, or the fallback: never free text into a sentence. */
    private function word(mixed $value, ?string $fallback): ?string
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9_.\-]{1,40}$/', $value) === 1 ? $value : $fallback;
    }

    private function time(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }

    private function date(?string $iso): string
    {
        return $iso === null
            ? 'at an unrecorded time'
            : CarbonImmutable::parse($iso)->setTimezone((string) config('hq.timezone', 'Asia/Dhaka'))->format('j M Y H:i');
    }

    private function age(int $minutes): string
    {
        return $minutes < 120 ? "{$minutes} minute(s)" : intdiv($minutes, 60).' hour(s)';
    }
}
