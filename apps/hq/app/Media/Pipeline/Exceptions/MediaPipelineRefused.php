<?php

namespace App\Media\Pipeline\Exceptions;

use RuntimeException;

/** The provider (or this adapter, before calling it) said no. */
final class MediaPipelineRefused extends RuntimeException {}
