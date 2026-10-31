<?php

namespace App\Media\Upload;

use RuntimeException;

/** A file HQ will not store, with a reason a person can read. Never carries the file's bytes. */
final class AttachmentRejected extends RuntimeException {}
