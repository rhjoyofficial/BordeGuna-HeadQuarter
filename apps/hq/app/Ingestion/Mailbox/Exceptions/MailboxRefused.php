<?php

namespace App\Ingestion\Mailbox\Exceptions;

use RuntimeException;

/** The mailbox said no: rejected credentials, a mailbox that does not exist, a refused request. */
final class MailboxRefused extends RuntimeException {}
