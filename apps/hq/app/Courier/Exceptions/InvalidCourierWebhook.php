<?php

namespace App\Courier\Exceptions;

use RuntimeException;

/** A webhook that is not authentic or not readable. Never carries the secret or the body. */
final class InvalidCourierWebhook extends RuntimeException {}
