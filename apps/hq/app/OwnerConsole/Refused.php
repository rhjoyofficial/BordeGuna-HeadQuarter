<?php

namespace App\OwnerConsole;

use RuntimeException;

/** A refusal with a reason a person can read; the controllers turn it into a form error, never a 500. */
final class Refused extends RuntimeException {}
