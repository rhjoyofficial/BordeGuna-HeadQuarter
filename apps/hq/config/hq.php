<?php

use App\Scheduling\Tools\Commerce\CheckFactIntegrityTool;
use App\Scheduling\Tools\Commerce\ReconcileShopFactsTool;
use App\Scheduling\Tools\Erp\PollErpLowStockTool;
use App\Scheduling\Tools\Erp\PollErpOverviewTool;
use App\Scheduling\Tools\FailingTool;
use App\Scheduling\Tools\NoopTool;
use App\Support\Events\Handlers\CustomerRegisteredHandler;
use App\Support\Events\Handlers\ShopOrderEventHandler;

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
        // Phase 1: read-only ERP polls that fill facts_erp_snapshots (ADR-007 read models).
        PollErpOverviewTool::class,
        PollErpLowStockTool::class,
        // The order reconciliation safety net, so a lost webhook is caught by a nightly job
        // rather than by somebody remembering to run the command.
        ReconcileShopFactsTool::class,
        // The arithmetic check that would have caught the 0.00 line prices on day one.
        CheckFactIntegrityTool::class,
    ],

    // The ERP's HQ API (bordeguna-erp feature/hq-api). The per-business token is NOT here: it is an
    // integration_credentials row (provider erp_api_token, account_ref = business slug).
    //
    // ⚠ The default used to be the hosting provider's hostname, `erp.srv1808022.hstgr.cloud`. The
    // ERP moved to its own domain on 7 September 2026 and removed the provider hostname from its
    // routing, and because nothing set HQ_ERP_BASE_URL in production the default was the operative
    // value - so HQ went on calling a host that answered 404 to every ERP read, with no error
    // anywhere until somebody probed it. A default that names a hostname the business no longer
    // uses is not a fallback, it is a trap; this one names the real domain.
    'erp' => [
        'base_url' => env('HQ_ERP_BASE_URL', 'https://erp.bordeguna.com'),
        'timeout_seconds' => (int) env('HQ_ERP_TIMEOUT', 15),
    ],

    'scheduler' => [
        'heartbeat_stale_after_seconds' => 180,
        'default_retry_max' => 3,
        'default_retry_backoff_seconds' => 60,
    ],

    'events' => [
        // type => [handler classes]. Empty in Phase 0; handlers arrive with the subsystems that need them.
        'handlers' => [
            'order.created' => [ShopOrderEventHandler::class],
            'order.status_changed' => [ShopOrderEventHandler::class],
            'order.payment_updated' => [ShopOrderEventHandler::class],
            'shipment.status_updated' => [ShopOrderEventHandler::class],
            'payment.completed' => [ShopOrderEventHandler::class],
            'shipment.delivered' => [ShopOrderEventHandler::class],
            'customer.registered' => [CustomerRegisteredHandler::class],
        ],
        'default_max_attempts' => 5,
        'backoff_seconds' => [30, 120, 600, 1800, 3600],
    ],

    'alerts' => [
        'telegram_bot_token' => env('ALERT_TELEGRAM_BOT_TOKEN'),
        'telegram_chat_id' => env('ALERT_TELEGRAM_CHAT_ID'),
    ],
];
