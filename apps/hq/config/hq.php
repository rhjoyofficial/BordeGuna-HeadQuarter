<?php

use App\Ai\Providers\AnthropicProvider;
use App\Ai\Providers\OpenAiProvider;
use App\Briefing\ExecutiveBriefingContract;
use App\Observe\Alerts\Rules\AiBudgetPressure;
use App\Observe\Alerts\Rules\ApprovalsPastDeadline;
use App\Observe\Alerts\Rules\CredentialExpiring;
use App\Observe\Alerts\Rules\EventsDeadLettered;
use App\Observe\Alerts\Rules\FactIntegrityDiscrepancy;
use App\Observe\Alerts\Rules\HorizonDown;
use App\Observe\Alerts\Rules\HostDisk;
use App\Observe\Alerts\Rules\HostMemory;
use App\Observe\Alerts\Rules\ProviderCircuitOpen;
use App\Observe\Alerts\Rules\ReadinessIncident;
use App\Observe\Alerts\Rules\RepeatedPolicyDenials;
use App\Observe\Alerts\Rules\SchedulerTickStale;
use App\Observe\Alerts\Rules\ShadowPeriodStopped;
use App\Observe\Alerts\Rules\SnapshotStale;
use App\Observe\Alerts\Rules\WebhookSignatureFailures;
use App\Support\Events\Handlers\ConversationEventHandler;
use App\Support\Events\Handlers\CustomerRegisteredHandler;
use App\Support\Events\Handlers\MetaInboundHandler;
use App\Support\Events\Handlers\ShopOrderEventHandler;
use App\Tools\Briefing\ComposeBriefingTool;
use App\Tools\Commerce\CancelSalesOrderTool;
use App\Tools\Commerce\CheckFactIntegrityTool;
use App\Tools\Commerce\GetOrderStatusTool;
use App\Tools\Commerce\ListOrdersTool;
use App\Tools\Commerce\QuoteOrderTool;
use App\Tools\Commerce\ReconcileShopFactsTool;
use App\Tools\Commerce\TakeOrderTool;
use App\Tools\Conversation\ReadThreadTool;
use App\Tools\Conversation\SendReplyTool;
use App\Tools\Conversation\SendWhatsAppReplyTool;
use App\Tools\Diagnostics\ApprovalDemoTool;
use App\Tools\Diagnostics\FailingTool;
use App\Tools\Diagnostics\NoopTool;
use App\Tools\Erp\CheckOrderEligibilityTool;
use App\Tools\Erp\LookupProductTool;
use App\Tools\Erp\LookupSkuTool;
use App\Tools\Erp\PollErpLowStockTool;
use App\Tools\Erp\PollErpOverviewTool;
use App\Tools\Erp\ReadAvailabilityTool;
use App\Tools\Erp\ReadErpSnapshotTool;
use App\Tools\Erp\ReadPriceTool;
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

        // Phase 3 (docs/72): the support agent reads a thread, and PROPOSES a reply. The reply tool is
        // customer-facing and consent-gated by construction, and cannot execute for anyone until a
        // messaging provider is registered, which none is (gate P3-G4).
        ReadThreadTool::class,
        SendReplyTool::class,

        // M7 (docs/48 M.4, docs/80 C.1): collects, validates and stores the Executive Briefing's
        // evidence bundle. R3 because it writes the briefing row; not agent-executable; it calls no
        // model while narration is off, which is the shipped state. No scheduled job runs it.
        ComposeBriefingTool::class,

        // Phase 4 read side (docs/81 C.5, docs/79): what HQ must know before it could ever take an
        // order, read live from the ERP. Every one is R2 and read-only. ⛔ NO agent holds any of them
        // (agents.allowed_tools is untouched), no order write exists, and `take_order` is registered
        // nowhere. The eligibility check names a person from a phone and is not agent-executable.
        LookupProductTool::class,
        LookupSkuTool::class,
        ReadPriceTool::class,
        ReadAvailabilityTool::class,
        CheckOrderEligibilityTool::class,

        // Phase 4 order write (docs/83 C.3, docs/88 A point 3, docs/79): `take_order`, R4, approval-required,
        // the requester never decides it, not agent-executable, held by no agent. It leaves a DRAFT the
        // ERP prices and checks, then an ORDER made from that draft, which WAITS in the ERP's own approval
        // inbox: HQ approves nothing in the ERP. ⛔ INERT: no ERP token holds a write ability, and
        // `orders.take_order_stores` below is empty, so the gateway refuses every store.
        TakeOrderTool::class,

        // Phase 3 channel adapters (docs/88 A point 2): the WhatsApp reply, its own tool (ADR-026). ⛔ INERT: not
        // agent-executable, held by no agent, and no WhatsApp provider is registered in PlatformRegistry, so the
        // capability is absent and it fails for everyone, human included.
        SendWhatsAppReplyTool::class,
        // Phase 4 order completion (docs/88 A point 3, docs/79 D): the quote and the status are R2 reads
        // of the ERP's own answers, held by no agent; the cancellation is `take_order`'s compensating
        // tool, R4, approval-required, irreversible, reaching only an order HQ placed while nothing has
        // been delivered, inert under the same empty store list. No payment, no courier: those are the
        // ERP's, and HQ may read the resulting state later.
        QuoteOrderTool::class,
        GetOrderStatusTool::class,
        CancelSalesOrderTool::class,
    ],

    // docs/83 C.3: the HQ stores `commerce.take_order` may write an order for, and (docs/88 A point 3)
    // `commerce.cancel_sales_order` may cancel one for. ⛔ EMPTY, and it stays empty until a store lives
    // on the ERP's own website module (docs/79 F, ADR-046 point 3) and gate P4-G2 is run. Admitting a
    // store is a deploy, never a switch or an env value.
    'orders' => [
        'take_order_stores' => [],
    ],

    // docs/88 A point 4 (stream S): the Phase 5 ads READ side. Configuration only: the provider is
    // registered nowhere, no ads tool is in `tools` above, nothing is scheduled and no credential exists,
    // so none of this is read on production until gate P5-G1. The API version is configuration, never a
    // literal in code (docs/84 C.4): v26.0 is the newest (29 July 2026, sunset not announced).
    'ads' => [
        'meta' => [
            'base_url' => 'https://graph.facebook.com',
            'graph_version' => 'v26.0',
            'timeout_seconds' => 20,
            // Stop short of Meta's own limit: at this usage percentage the next call is not sent.
            'backoff_at_percent' => 80,
        ],
    ],

    // docs/86: what each agent ROLE may not do, whatever it is asked (`App\Agents\RolePolicy`, read by
    // the runtime's `RoleBoundary` on the operator's own words BEFORE the model is called). Code, not a
    // switch: changing a role is a reviewed release. A kind not forbidden is NOT thereby a capability;
    // the Support agent's customer reply still runs only through its gated reply tool.
    //   outbound_message: send, message, text, email, WhatsApp, contact, tell or ask a party outside HQ
    //   publication:      post, publish or put up content on a page, feed, account or site; run an ad
    //   customer_reply:   answer or reply to a customer, or draft that reply
    'roles' => [
        // v2 (docs/88 points 10 and 14): one profile per role, none derived from another, and
        // `role_expansion` forbidden to every role. v1 was the three outbound kinds only.
        'version' => 'v2',
        'profiles' => [
            'executive' => [
                'purpose' => 'Reads across the group and says what needs a person; sends, publishes and answers no one.',
                'forbidden_actions' => ['outbound_message', 'publication', 'customer_reply', 'role_expansion'],
            ],
            // Its own profile: the same forbidden classes as the Executive today because it is also
            // a read-only role, free to diverge from the Executive's without touching it.
            'analytics' => [
                'purpose' => 'Measures and compares the businesses with the coverage behind every figure; sends, publishes and answers no one.',
                'forbidden_actions' => ['outbound_message', 'publication', 'customer_reply', 'role_expansion'],
            ],
            // Answering the customer on their own thread IS the support role (docs/72); nothing else outbound is.
            'support' => [
                'purpose' => 'Proposes one reply to the customer on their own thread, in their language, or hands the thread to a person.',
                'forbidden_actions' => ['outbound_message', 'publication', 'role_expansion'],
            ],
        ],
    ],

    // docs/80 C.1: the Executive Briefing capability. The contract is code (its trusted task, its
    // input and its evidence surface are constants on the class); what is here is deployment tuning.
    //
    // ⛔ Narration is behind the feature flag `briefing.narration`, which has no row anywhere and is
    // therefore off (`Features::enabled` answers false for a missing key). It also needs `ai.runtime`.
    'briefing' => [
        'contract' => ExecutiveBriefingContract::class,

        // How old a source may be before the briefing says, at the top, that it is stale. The ERP
        // polls and the shop reconciliation are DAILY, so a day plus two hours of grace, the same
        // figure the snapshot alert uses; the alert check runs every five minutes.
        'freshness_budget_minutes' => [
            'facts_orders' => 1560,
            'erp_low_stock' => 1560,
            'integrity_check' => 1560,
            'alerts' => 15,
        ],

        // The order-volume band exists only above this history floor, the same one
        // `facts.query_orders` uses before it will call anything a trend. Below it the briefing says
        // "insufficient history" and gives no band.
        'band' => ['min_days' => 14, 'min_rows' => 30],

        // Individual rows listed beside a count (failed runs, alerts, approvals, low-stock lines).
        'max_listed' => 20,
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
        // Every driver HQ knows. Registering one serves nothing: which provider serves is which
        // `model_tiers` rows are active, and only `hq:ai:provider --apply --by=<person>` moves them
        // (docs/81 C.7). Anthropic stays registered while OpenAI serves, so going back is that lever.
        'providers' => [
            AnthropicProvider::class,
            OpenAiProvider::class,
        ],

        // OpenAI's reasoning effort (none, low, medium, high, xhigh, max; the model's own default is
        // medium). Part of what a safety gate measures, so it is fixed before a gate and printed by
        // `hq:ai:probe`, never tuned after a result. `low` (docs/81 C.7.4 point 3): reasoning tokens
        // come out of the same `max_output_tokens`, so medium risks the truncation ADR-038 exists for,
        // and none leaves the judgement calls of the safety corpus with no deliberation at all.
        'openai' => [
            'reasoning_effort' => env('HQ_OPENAI_REASONING_EFFORT', 'low'),
        ],

        // Turns the runtime on. OFF means every model call refuses, which is the Phase 2 default
        // until Joy has added a provider key and said to enable it.
        'enabled_flag' => 'ai.runtime',

        'probe' => [
            'max_output_tokens' => 64,
            // `hq:ai:probe --live --schema`: one agent-shaped reply under the agent schema, with room
            // for the JSON object and a little reasoning.
            'schema_max_output_tokens' => 512,
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
            // Phase 3 (docs/72): the Meta webhook row becomes conversation messages in the drain, and
            // the conversation events are recorded and, for now, nothing more.
            'conversation.inbound_received' => [MetaInboundHandler::class],
            'conversation.message_received' => [ConversationEventHandler::class],
            'conversation.state_changed' => [ConversationEventHandler::class],
            'conversation.reply_proposed' => [ConversationEventHandler::class],
        ],
        'default_max_attempts' => 5,
        'backoff_seconds' => [30, 120, 600, 1800, 3600],
    ],

    // Phase 3 (docs/72 B): the rules an outbound reply must pass at the tool, before any provider is
    // looked up. Small and named; a rule that cannot be named here does not exist.
    'outbound' => [
        'per_thread_hourly_cap' => (int) env('HQ_OUTBOUND_THREAD_HOURLY_CAP', 6),
        'reply_window_hours' => 24,
        'quiet_hours' => ['start' => 23, 'end' => 8],
    ],

    // Phase 7 (docs/88 A point 6, stream U): speech. ⛔ Nothing here switches anything on: the providers
    // are registered nowhere (`SpeechProviders` ships empty) and hold no credential. These are the
    // settings an adapter reads once a person registers it at gate P7-G2. No env() on purpose: a value
    // appears in deploy/.env only when that gate names it.
    'voice' => [
        // Customer voice notes are personal data: kept this many days, then eligible for deletion.
        'retention_days' => 90,
        // Below this provider confidence a transcript is flagged `low_confidence` on the message.
        'low_confidence' => 0.6,
        'google' => [
            // The Google Cloud project that owns the service account; empty means the adapter refuses.
            'project' => '',
            // Chirp 2 locations read 22 Sep: us-central1, europe-west4, asia-southeast1 (closest to Dhaka).
            'location' => 'asia-southeast1',
            'model' => 'chirp_2',
            'language' => 'bn-BD',
            // Synchronous recognize is for short audio; a voice note, not a recording.
            'max_bytes' => 10 * 1024 * 1024,
            'timeout' => 30,
        ],
        'elevenlabs' => [
            // The models page lists Bengali for eleven_v3, not for eleven_multilingual_v2 (read 22 Sep).
            'model' => 'eleven_v3',
            'output_format' => 'mp3_44100_128',
            'language_code' => null,
            'timeout' => 60,
        ],
        'azure' => [
            'region' => 'southeastasia',
            'voice' => 'bn-BD-NabanitaNeural',
            'output_format' => 'ogg-24khz-16bit-mono-opus',
            'timeout' => 60,
        ],
    ],

    // docs/88 A point 2: the Meta and WhatsApp send adapters. ⛔ None is registered in PlatformRegistry, so
    // nothing here is reached outside tests. The version is the one Meta's send pages show on 22 September
    // 2026; the rates are HQ's own ceiling per sending account, below Meta's, refused locally before a request.
    'messaging' => [
        // docs/95 §5 "Delivery": how long a `sending` or `uncertain` attempt may sit before the daily
        // reconciliation (`hq:deliveries:reconcile`) asks the provider what became of it. Never a resend.
        'reconcile_after_minutes' => 30,

        'meta' => [
            'graph_base' => 'https://graph.facebook.com',
            'graph_version' => 'v25.0',
            'timeout' => 15,
            'send_per_minute' => [
                'facebook_page' => 60,
                'instagram_account' => 60,
                'whatsapp_number' => 60,
            ],
        ],
        // The WhatsApp templates HQ may send outside the 24-hour window: name => ['language' => 'bn',
        // 'category' => 'utility'|'authentication', 'parameters' => <count of body values>]. ⛔ EMPTY: a
        // template is approved by Meta first and listed here by a deploy, never by a setting. Marketing
        // templates are refused by the reply tool whatever this says.
        'whatsapp' => [
            'templates' => [],
        ],
    ],

    // docs/78 B.3: the agents Ask HQ puts an operator's question to. The support agent answers
    // customers and is not one of them; widening this list is a decision, not a setting.
    'ask' => [
        'agents' => ['executive', 'analytics'],
    ],

    // docs/77 C.2: the agents an A0 shadow period may be opened for. ChatGPT's instruction of
    // 16 September names the Executive only; widening this list is a decision, not a setting.
    'shadow' => [
        'agents' => ['executive'],
    ],

    // docs/80 C.4: what release this is, and what the HOST says about releases and about main.
    //
    // `commit` is baked into the image by `deploy/Dockerfile` (`ENV HQ_RELEASE_COMMIT`, from the same
    // build argument as the image label). ⚠ Empty means "not a release image", and the application then
    // says "development checkout": it never guesses a commit from anything else.
    //
    // `state_path` is a directory the host writes and the containers mount READ-ONLY. The application
    // never writes there, never runs git and never calls GitHub.
    //
    // `main_stale_after_minutes`: the host fetches main at most every 30 minutes, so 90 is three missed
    // fetches. Past that the panel withholds "N commits ahead" and shows the age instead, because a
    // stale or missing reading must never look like "up to date".
    'release' => [
        'commit' => env('HQ_RELEASE_COMMIT'),
        'state_path' => env('HQ_RELEASE_STATE_PATH', base_path('release-state')),
        'main_stale_after_minutes' => (int) env('HQ_RELEASE_MAIN_STALE_AFTER_MINUTES', 90),
    ],

    // docs/78 B.2, decision V.7 (docs/48): the governed retention policy. Deliberately NOT read from the
    // environment: a production that quietly kept less than the decision says would be the policy lying
    // about itself, so changing a number is a deploy somebody reviews, and a test pins these values.
    //
    // ⛔ Only the three detail tables and the event payloads are named, and naming anything else does
    // nothing: `RetentionPolicy` knows these four keys and no others. audit_logs, decision_records, the
    // evaluation, shadow, agent-run, approval and consent tables are kept forever by not being here.
    'retention' => [
        'version' => 'retention.v1',
        'detail_keep_days' => [
            'tool_calls' => 180,
            'ai_runs' => 180,
            'connector_calls' => 180,
        ],
        'event_payload_keep_days' => 365,
        // The bounds of one pass: rows per transaction, rows per table, days per table, and wall time.
        // A pass that reaches a bound stops cleanly between transactions and the next pass continues.
        'batch_size' => 500,
        'max_rows_per_table' => 50000,
        'max_days_per_table' => 31,
        'max_seconds' => 600,
    ],

    'alerts' => [
        'telegram_bot_token' => env('ALERT_TELEGRAM_BOT_TOKEN'),
        'telegram_chat_id' => env('ALERT_TELEGRAM_CHAT_ID'),

        // docs/48 N.5 and docs/25: the conditions HQ checks every five minutes (`hq:alerts:check`). Each
        // rule says whether it is paged elsewhere already (the host monitor, the model runtime, the
        // approvals sweep), in which case its alert is recorded and shown but not sent a second time.
        'rules' => [
            SchedulerTickStale::class,
            HorizonDown::class,
            ProviderCircuitOpen::class,
            AiBudgetPressure::class,
            ApprovalsPastDeadline::class,
            RepeatedPolicyDenials::class,
            FactIntegrityDiscrepancy::class,
            EventsDeadLettered::class,
            CredentialExpiring::class,
            SnapshotStale::class,
            ReadinessIncident::class,
            ShadowPeriodStopped::class,
            HostDisk::class,
            HostMemory::class,
            WebhookSignatureFailures::class,
        ],

        // A condition must be absent on this many consecutive checks before its alert resolves, so a
        // value hovering at a threshold is one alert, not one per check.
        'resolve_after_clear_checks' => 2,
        // An open alert HQ pages is sent again, once, after this long.
        'renotify_after_hours' => 24,

        'thresholds' => [
            'repeated_denials' => ['count' => 3, 'window_minutes' => 60],
            'dead_letters_window_days' => 7,
            'credential_expiry_days' => 14,
            // The ERP polls are DAILY (04:00 and 04:05 Dhaka), so a snapshot is up to a day old by
            // design. The alert is for a poll that did not land: a day plus two hours of grace.
            // `facts.snapshot_freshness` keeps its own two-hour label, which is about how an answer
            // describes its data, not about whether a job failed.
            'snapshot_stale_after_hours' => 26,
            'shadow_stopped_window_days' => 7,
            'disk_warning_percent' => 80,
            'disk_critical_percent' => 90,
            'memory_min_available_mb' => 1024,
            'webhook_signature_failures' => ['count' => 10, 'window_hours' => 2],
        ],
    ],
];
