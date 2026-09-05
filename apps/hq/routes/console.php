<?php

use App\Scheduling\Commands\SchedulerTick;
use App\Support\Events\ProcessDomainEvents;
use Illuminate\Support\Facades\Schedule;

/*
 | The Laravel scheduler is only the TICK (ADR-005). Business scheduling lives in the
 | scheduled_jobs table and is driven by hq:scheduler:tick; the event drain delivers
 | inbox/outbox rows. Both hold Redis locks, so overlapping ticks are harmless.
 */
Schedule::command(SchedulerTick::class)->everyMinute()->withoutOverlapping(2)->runInBackground();
Schedule::command(ProcessDomainEvents::class)->everyMinute()->withoutOverlapping(2)->runInBackground();

// Horizon metrics snapshots for its dashboard.
Schedule::command('horizon:snapshot')->everyFiveMinutes();

// Housekeeping.
Schedule::command('model:prune')->daily();
Schedule::command('queue:prune-failed --hours=168')->daily();
