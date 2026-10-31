<?php

namespace App\Media\Pipeline\Exceptions;

use RuntimeException;

/** The provider could not be reached or failed on its side. */
final class MediaPipelineUnavailable extends RuntimeException {}
