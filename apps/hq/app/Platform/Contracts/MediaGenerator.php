<?php

namespace App\Platform\Contracts;

/**
 * Image, video, voice and avatar generation (Phases 6 to 8).
 *
 * ⚠ Generated media carries `Provenance::Generated`, which is NOT trusted, and synthetic use of a
 * real person's likeness or voice needs its own consent class (ConsentPurpose::SyntheticIdentity).
 * Both rules exist before the first generator does, so neither can be forgotten by the first one.
 */
interface MediaGenerator extends PlatformProvider
{
    public const IMAGE = 'generate_image';

    public const VIDEO = 'generate_video';

    public const VOICE = 'generate_voice';

    public const AVATAR = 'generate_avatar';

    /** Using a specific real person's likeness or voice, which consent gates separately. */
    public const SYNTHETIC_IDENTITY = 'synthetic_identity';
}
