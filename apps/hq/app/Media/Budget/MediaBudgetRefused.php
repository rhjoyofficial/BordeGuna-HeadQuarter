<?php

namespace App\Media\Budget;

use RuntimeException;

/** A paid media operation refused by the media budget BEFORE any provider was called (ruling 31 Oct item 12). */
final class MediaBudgetRefused extends RuntimeException {}
