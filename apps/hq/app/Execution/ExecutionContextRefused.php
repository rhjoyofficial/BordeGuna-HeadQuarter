<?php

namespace App\Execution;

use RuntimeException;

/**
 * No effective authority could be resolved, so nothing runs (ADR-047).
 *
 * The message is one of HQ's own sentences about ids, agent keys and permission names. It carries
 * nothing a model wrote and nothing secret, so a surface may show it to the person who asked.
 */
final class ExecutionContextRefused extends RuntimeException {}
