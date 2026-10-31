<?php

namespace App\Courier\Exceptions;

use RuntimeException;

/** The courier answered, and the answer is no: unknown consignment, bad credentials, a refused call. */
final class CourierRefused extends RuntimeException {}
