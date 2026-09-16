<?php

use App\Console\Commands\ApprovalsSweep;
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

// M3: expire approvals nobody decided and escalate ones past their deadline. Never approves.
Schedule::command(ApprovalsSweep::class)->everyFiveMinutes()->withoutOverlapping(2)->runInBackground();

// docs/77 C.2: an open A0 shadow period. Each command does nothing when no period is open, and none
// of them can switch anything on: the tick asks the period's own questions, the check stops a period
// that broke a safety condition, the store snapshots each complete day once.
Schedule::command('hq:shadow:tick --slot=morning')->dailyAt('09:00')->timezone('Asia/Dhaka')->withoutOverlapping(30)->runInBackground();
Schedule::command('hq:shadow:tick --slot=evening')->dailyAt('17:00')->timezone('Asia/Dhaka')->withoutOverlapping(30)->runInBackground();
Schedule::command('hq:shadow:evidence --check')->hourly()->withoutOverlapping(10)->runInBackground();
Schedule::command('hq:shadow:evidence --store')->dailyAt('00:15')->timezone('Asia/Dhaka')->withoutOverlapping(10)->runInBackground();

// Horizon metrics snapshots for its dashboard.
Schedule::command('horizon:snapshot')->everyFiveMinutes();

// Housekeeping.
Schedule::command('model:prune')->daily();
Schedule::command('queue:prune-failed --hours=168')->daily();
