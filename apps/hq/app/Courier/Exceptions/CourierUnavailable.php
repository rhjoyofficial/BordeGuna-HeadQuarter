<?php

namespace App\Courier\Exceptions;

use RuntimeException;

/** The courier could not be reached or failed on its side; try again later, it is not an answer. */
final class CourierUnavailable extends RuntimeException {}
