<?php

namespace App\Erp\Write\Exceptions;

use RuntimeException;

/** The ERP said no (credentials, validation, a non-draft answer). Never carries the token or the body. */
final class ErpWriteRefused extends RuntimeException {}
