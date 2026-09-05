<?php

use App\Scheduling\Tools\FailingTool;
use App\Scheduling\Tools\NoopTool;

/*
 | BordeGuna-HeadQuarter application configuration.
 | Everything here is Phase 0 foundation: tool registry, scheduler defaults, alerting.
 */
return [
    'timezone' => env('HQ_DEFAULT_TIMEZONE', 'Asia/Dhaka'),

    // Tools a scheduled job may execute. A tool absent from this list cannot be scheduled or run.
    // Phase 0 ships only the no-op and a test failure tool; real tools arrive with the tool gateway (Phase 2).
    'tools' => [
        NoopTool::class,
        FailingTool::class,
    ],

    'scheduler' => [
        'heartbeat_stale_after_seconds' => 180,
        'default_retry_max' => 3,
        'default_retry_backoff_seconds' => 60,
    ],

    'events' => [
        // type => [handler classes]. Empty in Phase 0; handlers arrive with the subsystems that need them.
        'handlers' => [],
        'default_max_attempts' => 5,
        'backoff_seconds' => [30, 120, 600, 1800, 3600],
    ],

    'alerts' => [
        'telegram_bot_token' => env('ALERT_TELEGRAM_BOT_TOKEN'),
        'telegram_chat_id' => env('ALERT_TELEGRAM_CHAT_ID'),
    ],
];
