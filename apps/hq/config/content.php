<?php

/*
 | Phase 6: the research engine and the content factory (docs/85, ADR-051 accepted 22 September 2026,
 | docs/88 A.5).
 |
 | ⛔ Nothing here switches anything on. The engine is off by the flags `research.engine`,
 | `content.drafting` and `publishing.write`, none of which has a row; no tool that uses these settings
 | is registered in `config('hq.tools')`; no publishing provider is in `PlatformRegistry`; no search
 | key and no Page token is stored. These are the values a gate will run with, kept apart from
 | `config/hq.php` so the Phase 6 foundation touches no shared setting.
 */

return [

    'research' => [
        // Brave or Tavily is the OWNER's decision (docs/85 I). Null means none is chosen, and every
        // search refuses with that reason. Never inferred from which key happens to be stored.
        'provider' => env('HQ_RESEARCH_PROVIDER'),

        'providers' => [
            // Brave Web Search, read 22 September 2026: GET /res/v1/web/search, X-Subscription-Token.
            'brave' => ['base_url' => 'https://api.search.brave.com/res/v1'],
            // Tavily, read 22 September 2026: POST /search, Authorization: Bearer.
            'tavily' => ['base_url' => 'https://api.tavily.com'],
        ],

        'results_per_search' => 10,
        'max_searches_per_task' => 10,
        'max_fetches_per_task' => 25,
        'search_timeout_seconds' => 15,
        'fetch_timeout_seconds' => 15,
        'max_page_bytes' => 2_000_000,
        'user_agent' => 'BordeGunaHQ-Research/1 (+https://hq.bordeguna.com)',

        // Brave's terms (read 21 September): storing results in part or whole needs a plan that grants
        // storage rights. False keeps only spans and hashes once a task closes.
        'store_bodies' => false,
    ],

    'publishing' => [
        // Facebook Login host; Meta's Pages guide shows its examples on v25.0 (read 22 September 2026).
        'graph_base_url' => 'https://graph.facebook.com',
        'graph_version' => 'v25.0',
        'timeout_seconds' => 20,
    ],

    'render' => [
        // The Bangla card render service (deploy/content-render): headless Chromium, because a
        // library without a shaping engine draws Bangla wrong (docs/85 A.6). Null: defined, not started.
        'url' => env('HQ_CONTENT_RENDER_URL'),
        'timeout_seconds' => 30,
    ],

];
