<?php

namespace App\Conversation;

use RuntimeException;

/** An inbound message named a page or number HQ does not hold. It is refused, never guessed at. */
final class UnknownPlatformAccount extends RuntimeException {}
