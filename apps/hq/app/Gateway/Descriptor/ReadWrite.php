<?php

namespace App\Gateway\Descriptor;

/**
 * Coarse and blunt on purpose. The risk tier carries the nuance; this exists so the environment
 * guard can ask one cheap question: could this change anything anywhere?
 */
enum ReadWrite: string
{
    case Read = 'read';
    case Write = 'write';
}
