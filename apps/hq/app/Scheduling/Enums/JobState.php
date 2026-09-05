<?php

namespace App\Scheduling\Enums;

enum JobState: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Cancelled = 'cancelled';
    case Done = 'done';
}
