<?php

namespace App\Scheduling\Enums;

enum MissedPolicy: string
{
    case Skip = 'skip';
    case CatchUp = 'catch_up';
    case RunLatestOnly = 'run_latest_only';
}
