<?php

namespace App\Avatar;

use App\Platform\Contracts\MediaGenerator;

/**
 * An avatar video / voice provider behind HQ's own contract (docs/20, docs/145-b). HeyGen or ElevenLabs
 * later; `FakeAvatarProvider` now.
 *
 * ⛔ No implementation is bound in the application, so generation refuses with "no provider" for everyone.
 * A provider never sees a consent decision: `AvatarGenerations` decides, and calls the provider only with a
 * request built from a consent row that was valid a moment before, inside a lock, and re-checks it after.
 * Provider keys are the vault's (credential provider named here), never an argument.
 */
interface AvatarProvider extends MediaGenerator
{
    /** `heygen`, `elevenlabs`, `sandbox`; stored on the generation row. */
    public function name(): string;

    /** The `integration_credentials.provider` the key is stored under. */
    public function credentialProvider(): string;

    public function generate(AvatarRequest $request): AvatarResult;
}
