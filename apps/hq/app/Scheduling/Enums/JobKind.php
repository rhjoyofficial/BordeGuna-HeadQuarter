<?php

namespace App\Scheduling\Enums;

enum JobKind: string
{
    case Once = 'once';
    case Cron = 'cron';
    case Interval = 'interval';
    case Event = 'event';
}
