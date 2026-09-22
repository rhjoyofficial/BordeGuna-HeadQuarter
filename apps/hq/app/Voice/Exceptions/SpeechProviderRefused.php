<?php

namespace App\Voice\Exceptions;

use RuntimeException;

/** The speech provider, or the adapter before calling it, refused the request. Asking again will not help. */
class SpeechProviderRefused extends RuntimeException {}
