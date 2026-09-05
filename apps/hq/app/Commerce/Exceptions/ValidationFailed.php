<?php

namespace App\Commerce\Exceptions;

/** The store refused the request as invalid (422); carries the field errors. */
class ValidationFailed extends CommerceException
{
    public function __construct(string $message = '', public readonly array $errors = [])
    {
        parent::__construct($message, 422);
    }
}
