<?php

namespace App\Facts;

/** A status event arrived before its order.created; the event drain retries with backoff (docs/07). */
final class UnknownOrderException extends \RuntimeException {}
