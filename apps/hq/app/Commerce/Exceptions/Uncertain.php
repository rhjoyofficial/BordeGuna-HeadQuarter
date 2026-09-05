<?php

namespace App\Commerce\Exceptions;

/** A write may have taken effect (timeout after send, 5xx): reconcile before retrying. */
class Uncertain extends CommerceException {}
