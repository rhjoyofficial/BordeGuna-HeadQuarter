<?php

namespace App\Ingestion\Mailbox\Exceptions;

use RuntimeException;

/** The mailbox could not be reached or failed on its side. */
final class MailboxUnavailable extends RuntimeException {}
