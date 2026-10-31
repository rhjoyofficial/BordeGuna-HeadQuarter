<?php

namespace App\Media\Pipeline;

use App\Media\Pipeline\Exceptions\MediaPipelineRefused;
use App\Media\Pipeline\Exceptions\MediaPipelineUnavailable;

/**
 * A pipeline that makes no video and reaches nothing: the first `check()` of a handle says `running`, the second
 * `ready` with a few bytes that look like an MP4 header. For contract tests, the sandbox switch and trying the
 * review screen before any provider is connected. The brief chooses the story: `FAKE-FAIL` fails the job and
 * `FAKE-DOWN` reports the provider unavailable.
 */
final class FakeMediaPipeline implements MediaPipeline
{
    /** @var array<string, int> checks seen per handle, per process: enough for a test, never state that matters */
    private static array $checks = [];

    public function key(): string
    {
        return 'fake_video';
    }

    public function start(MediaJobSpec $spec): string
    {
        if (str_contains($spec->brief, 'FAKE-DOWN')) {
            throw new MediaPipelineUnavailable('fake_video: the fake provider is down.');
        }

        return (str_contains($spec->brief, 'FAKE-FAIL') ? 'fake-fail-' : 'fake-job-').substr(hash('sha256', $spec->brief.$spec->seconds.$spec->aspect), 0, 16);
    }

    public function check(string $handle): MediaJobStatus
    {
        if (! str_starts_with($handle, 'fake-')) {
            throw new MediaPipelineRefused('fake_video: this handle is not one the fake provider issued.');
        }
        self::$checks[$handle] = (self::$checks[$handle] ?? 0) + 1;
        if (self::$checks[$handle] < 2) {
            return new MediaJobStatus(MediaJobStatus::RUNNING);
        }
        if (str_starts_with($handle, 'fake-fail-')) {
            return new MediaJobStatus(MediaJobStatus::FAILED, error: 'The fake provider was asked to fail.');
        }

        return new MediaJobStatus(MediaJobStatus::READY, "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x00", 64), 'video/mp4');
    }
}
