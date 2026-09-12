<?php

use App\Ai\Providers\AnthropicProvider;
use App\Support\Events\Handlers\CustomerRegisteredHandler;
use App\Support\Events\Handlers\ShopOrderEventHandler;
use App\Tools\Commerce\CheckFactIntegrityTool;
use App\Tools\Commerce\ListOrdersTool;
use App\Tools\Commerce\ReconcileShopFactsTool;
use App\Tools\Diagnostics\ApprovalDemoTool;
use App\Tools\Diagnostics\FailingTool;
use App\Tools\Diagnostics\NoopTool;
use App\Tools\Erp\PollErpLowStockTool;
use App\Tools\Erp\PollErpOverviewTool;
use App\Tools\Erp\ReadErpSnapshotTool;
use App\Tools\Erp\RunErpReportTool;
use App\Tools\Facts\QueryOrdersTool;
use App\Tools\Facts\SnapshotFreshnessTool;
use App\Tools\Hq\ListApprovalsTool;
use App\Tools\Hq\ListJobsTool;
use App\Tools\Hq\RecentEventsTool;

/*
 | BordeGuna-HeadQuarter application configuration.
 | Tool registry, gateway, scheduler defaults, event handlers, alerting.
 */
return [
    'timezone' => env('HQ_DEFAULT_TIMEZONE', 'Asia/Dhaka'),

    // The capability list. A tool absent from here cannot be scheduled, called or run by anything:
    // this is the OUTER kill switch, and changing it takes a deploy, which is the right weight for
    // "does this capability exist at all". The `tools` table is the inner switch and can only ever
    // narrow what is here (docs/48 D.2).
    //
    // ⚠ Class names, not keys. The keys these classes declare are referenced by scheduled_jobs rows
    // and must not be renamed; the classes moved namespace in M2 and the keys did not.
    'tools' => [
        NoopTool::class,
        FailingTool::class,
        // M3: an R4 tool whose only effect is one HQ audit row, so the approval mechanism can be
        // proven on production without creating or cancelling a real customer order to test it.
        // Not agent-executable, and switched OFF at runtime once the acceptance test has run.
        ApprovalDemoTool::class,
        // Phase 1: read-only ERP polls that fill facts_erp_snapshots (ADR-007 read models).
        PollErpOverviewTool::class,
        PollErpLowStockTool::class,
        // The order reconciliation safety net, so a lost webhook is caught by a nightly job
        // rather than by somebody remembering to run the command.
        ReconcileShopFactsTool::class,
        // The arithmetic check that would have caught the 0.00 line prices on day one.
        CheckFactIntegrityTool::class,

        // M5: the read set the two agents answer from. Every one is R1 or R2, read-only, and
        // `agentExecutable: true` - the first tools in HQ an agent may run at all.
        QueryOrdersTool::class,
        SnapshotFreshnessTool::class,
        ReadErpSnapshotTool::class,
        ListApprovalsTool::class,
        ListJobsTool::class,
        RecentEventsTool::class,
        ListOrdersTool::class,
        RunErpReportTool::class,
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

        // M5: the report keys `erp.run_report` may run. NOT free-form, and deliberately not "every
        // report the ERP offers": an open key space handed to a model is an interface whose reach
        // nobody here has enumerated. Widening it is a deploy somebody reviews.
        //
        // Measured against production on 10 September 2026: the HQ token can see exactly one
        // report, `sales_by_brand` (it requires `sales.reports.view`). That is V.9 working - the
        // purchasing reports are not merely unlisted here, the ERP does not offer them to this
        // token at all.
        'report_whitelist' => [
            'sales_by_brand',
        ],
    ],

    // Phase 2 M1: the Tool Gateway (docs/48, docs/49, ADR-016).
    //
    // Everything the gateway needs that is genuinely deployment configuration. Note what is NOT
    // here: risk tiers, permissions and credential requirements all live on the tool class, because
    // they are statements about what the code does. A risk tier that could be edited in a config
    // file would be a risk tier that could be edited to make an expensive action look cheap.
    'gateway' => [
        // What unattended internal work may do when no human owns it: the scheduler tick with an
        // ownerless job, the event drain. Deliberately narrow, and deliberately a fixed list rather
        // than a role, so widening it is a code change somebody reviews. There is no wildcard.
        'system_permissions' => [
            'hq.tools.diagnostics',
            'hq.tools.erp.read',
            'hq.tools.commerce.read',
            'hq.tools.facts.read',
        ],

        // The oldest a recorded result may be and still be replayed for an explicit idempotency
        // key. A redelivery arrives in seconds; a day is generous and stops a key from resurrecting
        // an answer nobody remembers asking for.
        'replay_ceiling_seconds' => 86400,

        // Joy's decision V.8, 8 September 2026: a SOFT ADVISORY cap. Notify at 80 per cent,
        // downgrade the model tier at 100 per cent, and never refuse work because of it. A briefing
        // that did not run because of an advisory cap would be the cap lying about its own name.
        // Reads zero today: no model has ever been called (M4 connects the first provider).
        'ai_monthly_soft_cap_usd' => (float) env('HQ_AI_MONTHLY_SOFT_CAP_USD', 50.0),
    ],

    /*
     | M4: the model router.
     |
     | ⚠ Only PROVIDER DRIVERS are listed here. Model ids, prices, context windows and capabilities
     | live in the `model_tiers` table, because a price change or a new model must be a row edit and
     | not a deploy (ADR-019). A grep test fails the build if a model id appears in `app/` outside
     | the driver layer.
     |
     | Provider API keys are `integration_credentials` rows, added from the UI, never env values.
     */
    'ai' => [
        'providers' => [
            AnthropicProvider::class,
        ],

        // Turns the runtime on. OFF means every model call refuses, which is the Phase 2 default
        // until Joy has added a provider key and said to enable it.
        'enabled_flag' => 'ai.runtime',

        'probe' => [
            'max_output_tokens' => 64,
            'timeout_seconds' => 60,
        ],

        // How much room a reply may take, by class of work (ADR-038). A REQUESTED budget, never the
        // number sent: `OutputBudget` clamps it to the model's own limit and to `ceiling` first, and
        // when a provider still cuts a reply off it re-asks ONCE with `growth_factor` times the room.
        // The third safety gate failed on a constant 4,096 that an eight-call sweep could not fit in;
        // these are not a bigger constant, they are the inputs a policy needs to stay right when the
        // model, the provider or the length of a day's answer changes.
        'output' => [
            'default' => 1024,
            'agent_turn' => (int) env('HQ_AI_AGENT_OUTPUT_TOKENS', 8192),
            'ceiling' => (int) env('HQ_AI_OUTPUT_CEILING', 16384),
            'truncation_retries' => 1,
            'growth_factor' => 2,
        ],
    ],

    // M2: the scheduler runs its tools THROUGH the gateway when this feature flag is on.
    //
    // The flag is the rollback switch for the migration, not a permanent fork in the road. Direct
    // mode is the known-good Phase 1 path and stays available until a separately approved step
    // removes it; gateway mode is the intended path once the equivalence suite has passed against
    // production (ADR-016).
    'scheduler_gateway_flag' => 'scheduler.gateway',

    'scheduler' => [
        'heartbeat_stale_after_seconds' => 180,
        'default_retry_max' => 3,
        'default_retry_backoff_seconds' => 60,
    ],

    'events' => [
        // type => [handler classes]. Empty in Phase 0; handlers arrive with the subsystems that need them.
        'handlers' => [
            'order.created' => [ShopOrderEventHandler::class],
            // Full-state, and the only way HQ can hold current lines for an order edited on the shop.
            'order.updated' => [ShopOrderEventHandler::class],
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
