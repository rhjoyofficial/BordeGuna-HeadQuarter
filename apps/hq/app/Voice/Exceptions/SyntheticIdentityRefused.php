<?php

namespace App\Voice\Exceptions;

use RuntimeException;

/**
 * A request to speak in a real person's cloned voice (or to show their face) that this release refuses.
 *
 * Phase 8 (docs/88-u-phase-7-8-voice-and-avatar-architecture.md): a real person's likeness or voice is
 * used only under that person's written consent record (`ConsentPurpose::SyntheticIdentity`), which does
 * not exist yet. Until gate P8-G0 builds it, every cloned voice is refused, not merely unconfigured.
 */
class SyntheticIdentityRefused extends RuntimeException {}
