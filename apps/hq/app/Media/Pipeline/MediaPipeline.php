<?php

namespace App\Media\Pipeline;

use App\Media\Pipeline\Exceptions\MediaPipelineRefused;
use App\Media\Pipeline\Exceptions\MediaPipelineUnavailable;

/**
 * Generating a short video or reel from a brief and the person's own photos (capability
 * `media.video.generate`, docs/144-media-pipeline). Asynchronous: `start()` returns a handle, `check()` is
 * asked until the job is ready or failed.
 *
 * ⛔ **Draft only.** What comes back is a draft asset with `Provenance::Generated`; nothing here publishes,
 * posts or sends it. A person approves the draft on the review screen, and publishing stays the existing
 * content gate's own approval (`content.publish`). A real person's face or voice is never asked of a
 * pipeline: `MediaJobSpec` carries no field for one (the synthetic-identity consent class applies first).
 *
 * An implementation authenticates only through the `CredentialVault` scope the caller opened.
 *
 * ⭐ Contract tests (`tests/Feature/Media/MediaPipelineContractTest`): a started job is `queued` or `running`
 * and has a handle; a finished job is `ready` with bytes and a video mime; an unknown handle is refused; a
 * down provider is `MediaPipelineUnavailable`; a spec asking for more seconds than the cap is refused before
 * any request leaves HQ.
 */
interface MediaPipeline
{
    public function key(): string;

    /**
     * @throws MediaPipelineRefused
     * @throws MediaPipelineUnavailable
     */
    public function start(MediaJobSpec $spec): string;

    /**
     * @throws MediaPipelineRefused
     * @throws MediaPipelineUnavailable
     */
    public function check(string $handle): MediaJobStatus;
}
