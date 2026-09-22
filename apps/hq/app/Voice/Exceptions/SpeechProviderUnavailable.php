<?php

namespace App\Voice\Exceptions;

use RuntimeException;

/** The speech provider could not be reached, throttled the request or failed on its side. */
class SpeechProviderUnavailable extends RuntimeException {}
