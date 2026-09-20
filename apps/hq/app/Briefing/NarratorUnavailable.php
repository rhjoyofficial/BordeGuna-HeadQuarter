<?php

namespace App\Briefing;

use RuntimeException;

/** Narration was asked for and nothing can narrate. The briefing is stored evidence-only and says so. */
final class NarratorUnavailable extends RuntimeException {}
