<?php

namespace App\Erp\Write\Exceptions;

use RuntimeException;

/** The ERP could not be reached or failed on its side; the outcome is unknown, so the caller checks before retrying. */
final class ErpWriteUnavailable extends RuntimeException {}
