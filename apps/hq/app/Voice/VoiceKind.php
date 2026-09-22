<?php

namespace App\Voice;

/** Whose voice a synthesis request would speak in. */
enum VoiceKind: string
{
    /** A provider's own catalogue voice, belonging to no real person HQ knows (e.g. Azure `bn-BD-NabanitaNeural`). */
    case Stock = 'stock';

    /** A voice cloned from a real person. Phase 8, under that person's written consent record only. */
    case Cloned = 'cloned';
}
