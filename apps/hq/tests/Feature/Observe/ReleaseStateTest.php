<?php

use App\Observe\HostProbe;
use App\Observe\ReleaseState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;

/*
 | docs/80 C.4 (instruction point 16): production, main and what is merged but not released.
 |
 | The reader is held to one rule above all: a thing it does not know is never shown as "nothing". So
 | most of these tests are about the ways of NOT knowing.
 */

const RS_RUNNING = 'aaaaaaa';
const RS_OTHER = 'bbbbbbb';
const RS_MAIN = 'ccccccc';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-20 12:00:00', 'UTC'));
    $this->stateDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'hq-release-state-'.Str::random(12);
    File::ensureDirectoryExists($this->stateDir);
    config(['hq.release.commit' => RS_RUNNING, 'hq.release.state_path' => $this->stateDir, 'hq.release.main_stale_after_minutes' => 90]);
});

afterEach(function () {
    File::deleteDirectory($this->stateDir);
});

function rsRecord(array $over = []): array
{
    return array_replace_recursive([
        'schema' => 1, 'kind' => 'deploy', 'status' => 'released', 'failed_stage' => null, 'commit' => RS_RUNNING,
        'previous' => RS_OTHER, 'previous_known' => true, 'recorded_at' => '2026-09-18T04:30:00Z',
        'dump' => '/backups/daily/hq-20260918-042900.dump', 'dump_verified_at' => '2026-09-18T04:29:30Z', 'origin_main_at_release' => RS_RUNNING,
        'verification' => ['status' => 'passed', 'up' => 'ok', 'health' => 'ok', 'health_release' => RS_RUNNING, 'checked_at' => '2026-09-18T04:31:00Z'],
    ], $over);
}

function rsMain(array $over = []): array
{
    return array_replace([
        'schema' => 1, 'read_at' => '2026-09-20T11:55:00Z', 'fetched_at' => '2026-09-20T11:40:00Z', 'fetch_ok' => null,
        'main_commit' => RS_MAIN, 'release_commit' => RS_RUNNING, 'release_basis' => 'running-image-label',
        'ahead' => 3, 'behind' => 0, 'reason' => null,
        'subjects' => ['Merge the evidence view', 'Effective execution context', 'docs/80'], 'subjects_truncated' => false,
        'migrations' => ['2026_09_20_000001_create_things_table.php', '2026_09_20_000002_create_more_table.php'],
    ], $over);
}

function rsPut(string $dir, string $name, array|string $content): void
{
    file_put_contents($dir.DIRECTORY_SEPARATOR.$name, is_array($content) ? json_encode($content) : $content);
}

function rsText(array $state): string
{
    return implode(' ', $state['summary']);
}

it('says "development checkout" when the image carries no commit, and guesses nothing', function (mixed $commit) {
    config(['hq.release.commit' => $commit]);
    // Records ARE present: a development checkout must not borrow them to describe itself.
    rsPut($this->stateDir, 'release.json', rsRecord());
    rsPut($this->stateDir, 'main.json', rsMain());

    $state = app(ReleaseState::class)->build();

    expect($state['mode'])->toBe('development')
        ->and($state['commit'])->toBeNull()
        ->and($state['production'])->toBeNull()
        ->and($state['main'])->toBeNull()
        ->and(rsText($state))->toContain('development checkout')->not->toContain('ahead')->not->toContain('Production runs');
})->with([null, '', 'main', 'HEAD; rm -rf /', 'abc', str_repeat('a', 41)]);

it('states the plain sentence: what production runs, how far main is ahead, and that none of it is live', function () {
    rsPut($this->stateDir, 'release.json', rsRecord());
    rsPut($this->stateDir, 'last-verified.json', rsRecord());
    rsPut($this->stateDir, 'main.json', rsMain());

    $state = app(ReleaseState::class)->build();

    expect($state['mode'])->toBe('release')
        ->and($state['records'])->toBe('present')
        ->and($state['production']['commit'])->toBe(RS_RUNNING)
        ->and($state['production']['verification'])->toBe('verified')
        ->and($state['production']['previous'])->toBe(RS_OTHER)
        ->and($state['production']['released_at'])->not->toBeNull()
        ->and($state['last_verified']['is_running'])->toBeTrue()
        ->and($state['last_attempt'])->toBeNull()
        ->and($state['main']['state'])->toBe('known')
        ->and($state['main']['ahead'])->toBe(3)
        ->and($state['main']['migrations'])->toHaveCount(2)
        ->and($state['main']['subjects'])->toHaveCount(3)
        ->and($state['main']['age_minutes'])->toBe(20)
        ->and($state['summary'][0])->toBe('Production runs '.RS_RUNNING.', released 18 Sep 2026 10:30.')
        ->and($state['summary'][1])->toBe('That release is verified (checked 18 Sep 2026 10:31).')
        ->and($state['summary'][2])->toBe('Main ('.RS_MAIN.') is 3 commit(s) ahead, including 2 migration(s) that have not run here. None of that is live.');
});

it('says so when main holds nothing unreleased, and only then', function () {
    rsPut($this->stateDir, 'last-verified.json', rsRecord());
    rsPut($this->stateDir, 'main.json', rsMain(['main_commit' => RS_RUNNING, 'ahead' => 0, 'subjects' => [], 'migrations' => []]));

    $state = app(ReleaseState::class)->build();

    expect($state['main']['state'])->toBe('known')
        ->and($state['main']['ahead'])->toBe(0)
        ->and(rsText($state))->toContain('holds nothing that is not released');
});

it('never reads absence as "up to date": every way of not knowing withholds the figures and says why', function (Closure $arrange, string $expectedState, string $expectedText) {
    rsPut($this->stateDir, 'last-verified.json', rsRecord());
    $arrange($this->stateDir);

    $state = app(ReleaseState::class)->build();

    expect($state['main']['state'])->toBe($expectedState)
        ->and($state['main']['ahead'])->toBeNull()
        ->and($state['main']['subjects'])->toBe([])
        ->and($state['main']['migrations'])->toBe([])
        ->and(rsText($state))->toContain('Main state unknown')->toContain($expectedText)
        ->not->toContain('holds nothing that is not released')
        ->not->toContain('None of that is live');
})->with([
    'main.json was never written' => [fn (string $d) => null, 'missing', 'has not written main.json'],
    'main.json is not JSON' => [fn (string $d) => rsPut($d, 'main.json', '{"schema":1,"ahead":'), 'unreadable', 'could not be read'],
    'main.json is another schema' => [fn (string $d) => rsPut($d, 'main.json', ['schema' => 2] + rsMain()), 'unreadable', 'could not be read'],
    'the last good fetch is five hours old' => [fn (string $d) => rsPut($d, 'main.json', rsMain(['fetched_at' => '2026-09-20T07:00:00Z', 'fetch_ok' => false])), 'stale', 'last fetched main 5 hour(s) ago'],
    'main was never fetched successfully' => [fn (string $d) => rsPut($d, 'main.json', rsMain(['fetched_at' => null])), 'stale', 'never fetched main successfully'],
    'the host could not tell what is running' => [fn (string $d) => rsPut($d, 'main.json', rsMain(['ahead' => null, 'behind' => null, 'release_commit' => null, 'reason' => 'the running release could not be determined'])), 'undetermined', 'the running release could not be determined'],
    'the count is not a number' => [fn (string $d) => rsPut($d, 'main.json', rsMain(['ahead' => '0'])), 'undetermined', 'could not compare'],
    'main was compared with some other release' => [fn (string $d) => rsPut($d, 'main.json', rsMain(['release_commit' => RS_OTHER, 'ahead' => 0])), 'other_release', 'compared main with '.RS_OTHER],
]);

it('keeps the last known figure beside its age when main is stale, never as the answer', function () {
    rsPut($this->stateDir, 'main.json', rsMain(['fetched_at' => '2026-09-20T07:00:00Z']));

    $state = app(ReleaseState::class)->build();

    expect($state['main']['ahead'])->toBeNull()
        ->and($state['main']['last_known_ahead'])->toBe(3)
        ->and($state['main']['age_minutes'])->toBe(300)
        ->and(rsText($state))->toContain('At that time main was 3 commit(s) ahead; it may be further ahead now.');
});

it('says the directory is not mounted rather than reporting an empty history', function () {
    config(['hq.release.state_path' => $this->stateDir.DIRECTORY_SEPARATOR.'not-there']);

    $state = app(ReleaseState::class)->build();

    expect($state['records'])->toBe('missing_directory')
        ->and($state['production']['commit'])->toBe(RS_RUNNING)
        ->and($state['production']['verification'])->toBe('unverified')
        ->and($state['last_verified'])->toBeNull()
        ->and($state['main']['state'])->toBe('missing')
        ->and(rsText($state))->toContain('not mounted into this container')->not->toContain('No release has been verified');
});

it('shows a release as UNVERIFIED when its check failed, never ran, or is not on record', function (?array $record, string $reason) {
    if ($record !== null) {
        rsPut($this->stateDir, 'release.json', $record);
    }

    $state = app(ReleaseState::class)->build();

    expect($state['production']['verification'])->toBe('unverified')
        ->and($state['production']['verified_at'])->toBeNull()
        ->and($state['last_verified'])->toBeNull()
        ->and($state['production']['verification_reason'])->toContain($reason)
        ->and(rsText($state))->toContain('That release is UNVERIFIED.')->toContain('No release has been verified on this host yet.');
})->with([
    'the check failed' => [rsRecord(['verification' => ['status' => 'failed', 'health' => 'fail']]), 'The post-release check failed: /up ok, health fail'],
    'the check never ran' => [rsRecord(['verification' => ['status' => 'not_run', 'up' => null, 'health' => null]]), 'never ran'],
    'the record is of another commit' => [rsRecord(['commit' => RS_OTHER]), 'no record of releasing this commit'],
    'there is no record at all' => [null, 'no record of releasing this commit'],
    'the release failed at smoke yet this image runs' => [rsRecord(['status' => 'failed', 'failed_stage' => 'smoke', 'verification' => ['status' => 'failed', 'up' => 'failed']]), 'failed at the smoke stage, yet this image is what is running'],
]);

it('does not accept a last-verified file whose own verification did not pass', function () {
    rsPut($this->stateDir, 'last-verified.json', rsRecord(['verification' => ['status' => 'failed']]));

    $state = app(ReleaseState::class)->build();

    expect($state['production']['verification'])->toBe('unverified')->and($state['last_verified'])->toBeNull();
});

it('reports a failed release attempt as a failure, with production still on the verified release', function () {
    rsPut($this->stateDir, 'last-verified.json', rsRecord());
    rsPut($this->stateDir, 'release.json', rsRecord(['commit' => RS_MAIN, 'previous' => RS_RUNNING, 'status' => 'failed', 'failed_stage' => 'build',
        'recorded_at' => '2026-09-20T05:00:00Z', 'verification' => ['status' => 'not_run', 'up' => null, 'health' => null, 'health_release' => null, 'checked_at' => null]]));

    $state = app(ReleaseState::class)->build();

    expect($state['production']['commit'])->toBe(RS_RUNNING)
        ->and($state['production']['verification'])->toBe('verified')
        ->and($state['last_attempt'])->toMatchArray(['commit' => RS_MAIN, 'status' => 'failed', 'failed_stage' => 'build', 'is_running' => false])
        ->and(rsText($state))->toContain('The last deploy attempt, of '.RS_MAIN.' on 20 Sep 2026 11:00, FAILED at the build stage. It is not what is running.');
});

it('names the last verified release when it is not the one running', function () {
    rsPut($this->stateDir, 'last-verified.json', rsRecord(['commit' => RS_OTHER, 'verification' => ['health_release' => RS_OTHER]]));
    rsPut($this->stateDir, 'release.json', rsRecord(['verification' => ['status' => 'failed', 'health' => 'unreadable', 'health_release' => null]]));

    $state = app(ReleaseState::class)->build();

    expect($state['production']['verification'])->toBe('unverified')
        ->and($state['last_verified'])->toMatchArray(['commit' => RS_OTHER, 'is_running' => false])
        ->and(rsText($state))->toContain('The last verified release is '.RS_OTHER)->toContain('which is not the one running');
});

it('treats a short and a long form of the same commit as the same commit', function () {
    $long = RS_RUNNING.str_repeat('0', 33);
    config(['hq.release.commit' => $long]);
    rsPut($this->stateDir, 'last-verified.json', rsRecord());
    rsPut($this->stateDir, 'main.json', rsMain());

    $state = app(ReleaseState::class)->build();

    expect($state['production']['verification'])->toBe('verified')->and($state['main']['state'])->toBe('known');
});

it('passes nothing from the host files through except commits, short tokens, bounded subjects and migration file names', function () {
    rsPut($this->stateDir, 'release.json', rsRecord(['kind' => '<script>alert(1)</script>', 'previous' => '../../etc/passwd',
        'verification' => ['status' => 'failed', 'up' => 'ok; drop table', 'health' => str_repeat('x', 500)]]));
    rsPut($this->stateDir, 'main.json', rsMain([
        'subjects' => [str_repeat('s', 900), 42, ['nested'], 'fine'],
        'migrations' => ['../../../etc/passwd', '2026_09_20_000001_create_things_table.php', 'not-php.sh', 7],
    ]));

    $state = app(ReleaseState::class)->build();

    expect($state['production']['kind'])->toBe('deploy')
        ->and($state['production']['previous'])->toBeNull()
        ->and($state['production']['verification_reason'])->toBe('The post-release check failed: /up not checked, health not checked, health named release '.RS_RUNNING.'.')
        ->and($state['main']['migrations'])->toBe(['2026_09_20_000001_create_things_table.php'])
        ->and($state['main']['subjects'])->toHaveCount(2)
        ->and(mb_strlen($state['main']['subjects'][0]))->toBe(200)
        ->and($state['main']['subjects_truncated'])->toBeTrue();
});

it('lists at most fifty subjects and says when there are more', function () {
    rsPut($this->stateDir, 'main.json', rsMain(['ahead' => 80, 'subjects' => array_map(fn ($i) => "commit {$i}", range(1, 60)), 'subjects_truncated' => true]));

    $main = app(ReleaseState::class)->build()['main'];

    expect($main['ahead'])->toBe(80)->and($main['subjects'])->toHaveCount(50)->and($main['subjects_truncated'])->toBeTrue();
});

it('says when production runs commits that are not on main', function () {
    rsPut($this->stateDir, 'main.json', rsMain(['behind' => 2]));

    expect(rsText(app(ReleaseState::class)->build()))->toContain('Production also runs 2 commit(s) that are not on main.');
});

it('reads and never writes: the directory is byte-identical after a build', function () {
    rsPut($this->stateDir, 'release.json', rsRecord());
    rsPut($this->stateDir, 'last-verified.json', rsRecord());
    rsPut($this->stateDir, 'main.json', rsMain());
    $snapshot = fn () => collect(File::allFiles($this->stateDir, true))->mapWithKeys(fn ($f) => [$f->getFilename() => md5_file($f->getPathname())])->sortKeys()->all();
    $before = $snapshot();

    app(ReleaseState::class)->build();

    expect($snapshot())->toBe($before)->and($before)->toHaveCount(3);
});

it('puts the release state on the Platform dashboard, behind the permission that page already has', function () {
    $this->mock(MasterSupervisorRepository::class, fn ($m) => $m->shouldReceive('all')->andReturn([(object) ['status' => 'running']]));
    $this->mock(WorkloadRepository::class, fn ($m) => $m->shouldReceive('get')->andReturn([]));
    $this->mock(HostProbe::class, function ($m) {
        $m->shouldReceive('disk')->andReturn(null);
        $m->shouldReceive('memory')->andReturn(null);
    });
    rsPut($this->stateDir, 'last-verified.json', rsRecord());
    rsPut($this->stateDir, 'main.json', rsMain());

    $this->actingAsHq($this->hqUser())->get('/observe/platform')->assertOk()
        ->assertInertia(fn ($page) => $page->component('observe/platform')
            ->where('release.mode', 'release')
            ->where('release.production.commit', RS_RUNNING)
            ->where('release.production.verification', 'verified')
            ->where('release.main.ahead', 3)
            ->where('release.main.migrations.0', '2026_09_20_000001_create_things_table.php')
            ->where('release.summary.2', fn ($line) => str_contains($line, 'None of that is live.')));

    config(['hq.release.commit' => null]);
    $this->get('/observe/platform')->assertOk()
        ->assertInertia(fn ($page) => $page->where('release.mode', 'development')->where('release.main', null));

    $this->actingAsHq($this->hqUser('finance_viewer'))->get('/observe/platform')->assertForbidden();
});

it('gives the public health endpoint the release commit and nothing else about releases', function () {
    rsPut($this->stateDir, 'release.json', rsRecord());
    rsPut($this->stateDir, 'main.json', rsMain());

    $json = $this->getJson('/api/health')->json();
    $body = json_encode($json);

    expect($json['checks']['application']['release'])->toBe(RS_RUNNING)
        ->and($body)->not->toContain('Effective execution context')
        ->not->toContain('create_things_table')
        ->not->toContain('/backups/')
        ->not->toContain(RS_MAIN)
        ->not->toContain(RS_OTHER);

    config(['hq.release.commit' => null]);
    expect($this->getJson('/api/health')->json('checks.application.release'))->toBe('development');
});
