/** The `console` prop of `/dashboard` (docs/135 W-UI2), as `App\Observe\Console\ConsoleReader` builds it. */

export type FreshnessState = 'fresh' | 'stale' | 'missing' | 'not_connected' | 'live';

export type ConsoleSource = {
    key: string;
    kind: 'orders' | 'erp_stock' | 'integrity';
    business: string | null;
    store: string | null;
    label: string | null;
    state: FreshnessState;
    age_minutes: number | null;
    budget_minutes: number | null;
    in_future: boolean;
    as_of: string | null;
    note: string | null;
};

export type ConsoleFigure = {
    id: string;
    figure: 'placed' | 'gross' | 'confirmed' | 'delivered' | 'cancelled' | string;
    business: string | null;
    business_name: string | null;
    value: number | string;
    unit: string;
    as_of: string | null;
    freshness: { state: FreshnessState; age_minutes: number | null; budget_minutes: number | null; in_future: boolean };
};

export type ConsoleBrief = {
    date: string;
    group_wide: boolean;
    figures: ConsoleFigure[];
    notes: { status: string; business?: string | null; store?: string; reason: string }[];
    empty_reason: string | null;
    sources: ConsoleSource[];
    not_connected: { key: string; reason: string }[];
    stored: {
        uuid: string;
        briefing_date: string;
        business: string | null;
        state: string;
        narration_state: string | null;
        narrative: string | null;
        degraded_reasons: string[] | null;
        generated_at: string;
    } | null;
};

export type DecisionCardData = {
    id: number;
    tool_key: string;
    tool_title: string | null;
    risk_tier: string | null;
    risk_label: string | null;
    business: string | null;
    store: string | null;
    agent_key: string | null;
    requester_kind: string | null;
    reason: string | null;
    created_at: string | null;
    sla_due_at: string | null;
    expires_at: string | null;
    decidable: boolean;
    href: string;
};

export type Receipt = {
    id: number;
    tool_key: string;
    tool_title: string | null;
    status: string;
    execution_status: string | null;
    decided_at: string | null;
    href: string;
};

export type LiveScheduler = {
    last_tick: string | null;
    age_seconds: number | null;
    stale_after_seconds: number;
    running: boolean;
    jobs: { active: number; paused: number; failed_24h: number; next: { id: number; name: string; next_run_at: string | null }[] } | null;
};

export type LiveAlerts = { total: number; items: { id: number; rule: string; severity: string; subject: string; last_seen_at: string }[] };

export type LiveCost = {
    scope: string;
    spent_usd: number;
    cap_usd: number | null;
    share_percent: number;
    downgrade: boolean;
    refuse: boolean;
};

export type LiveGuard = {
    window: string;
    calls_total: number;
    policy_outcomes: { value: string | null; count: number }[];
    approvals_pending: number;
    tools_disabled: number;
    active_grants: number;
    open_shadow_periods: number;
};

export type LivePosture = { ai_runtime: boolean; agents: { key: string; name: string; status: string; autonomy: string | null }[] };

export type ComposerData = {
    permitted: boolean;
    flag_on: boolean;
    runtime_on: boolean;
    usable: boolean;
    agents: { key: string; refusal: string | null }[];
    businesses: { id: number; name: string }[];
    max_question: number;
};

export type ConsoleData = {
    generated_at: string;
    timezone: string;
    restricted: boolean;
    brief: ConsoleBrief | null;
    decisions: { total: number; overdue: number; cards: DecisionCardData[] } | null;
    receipts: Receipt[] | null;
    live: {
        scheduler: LiveScheduler;
        alerts: LiveAlerts | null;
        cost: LiveCost | null;
        guard: LiveGuard | null;
        posture: LivePosture;
    };
    composer: ComposerData;
};
