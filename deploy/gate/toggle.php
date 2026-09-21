<?php

/**
 * Gate helpers, run inside the app container through tinker (docs/66 §E):
 *
 *   docker compose exec -T -e GATE_ACTION=posture app php artisan tinker --execute="require '/tmp/gate-toggle.php';"
 *
 * GATE_ACTION is one of:
 *   posture  read the AI posture from the database: flag row AND Features, agents, grants, consent, case counts
 *   bypass   the model provider's bypass test (docs/54): one tiny call with the vault's key, from inside the
 *            container, so "bad key" is separated from "bad code" before HQ's own code is asked to work.
 *            The key is opened in a vault scope and never printed. Since docs/77 E.1 the answer is also recorded
 *            as the credential's health (App\Ai\ProviderCheck), so a credit refusal shows on the readiness table.
 *            Since docs/81 C.7 it checks the provider that SERVES (Anthropic or OpenAI), the JSON ends with that
 *            provider's name, and when more than one provider serves it checks nothing ("http":null).
 *   on       ai.runtime ON + the gate's agent ACTIVE, each audited with a reason naming the gate
 *   off      the gate's agent PAUSED + ai.runtime OFF, each audited
 *   shadow   the agents with an open A0 shadow period (docs/77), so run.sh can refuse before touching anything
 *
 * GATE_AGENT names the agent the gate measures: `executive` (the default, gates 1 to 11) or `support` (P3-G2,
 * docs/77 C.4). `on` refuses an agent that names no enabling user: naming one is a person's decision, never a
 * gate's side effect.
 *
 * ⛔ Nothing here creates an autonomy grant, lifts a ceiling, or leaves anything on: `run.sh` calls `off` from an
 * EXIT trap, so the posture is restored whether the gate passes, fails or crashes.
 */

use App\Ai\ProviderCheck;
use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\AutonomyGrant;
use App\Models\ConsentEvent;
use App\Models\EvaluationSuite;
use App\Models\FeatureFlag;
use App\Models\ShadowPeriod;
use App\Support\Audit\Auditor;
use App\Support\Features\Features;
use Illuminate\Support\Facades\Schema;

$action = getenv('GATE_ACTION') ?: 'posture';
$gate = getenv('GATE_NAME') ?: 'Gate 4 (docs/66)';
$auditor = app(Auditor::class);
$flag = FeatureFlag::where('key', 'ai.runtime')->orderBy('id')->firstOrFail();
$agentKey = getenv('GATE_AGENT') ?: 'executive';
$executive = Agent::where('key', $agentKey)->firstOrFail();

$posture = function () use ($flag) {
    app(Features::class)->forget();
    $flag->refresh();
    $cases = fn (string $key) => EvaluationSuite::where('key', $key)->first()?->cases()->where('is_active', true)->count();

    echo json_encode([
        'ai.runtime.row' => (bool) $flag->enabled,
        'ai.runtime.features' => app(Features::class)->enabled('ai.runtime'),
        'agents' => Agent::orderBy('key')->get(['key', 'status', 'max_autonomy_level', 'enabling_user_id'])->toArray(),
        'grants' => AutonomyGrant::count(),
        'consent_events' => ConsentEvent::count(),
        'cases' => ['safety.core' => $cases('safety.core'), 'behaviour.executive' => $cases('behaviour.executive'), 'behaviour.support' => $cases('behaviour.support')],
        'last_audit_id' => AuditLog::max('id'),
    ], JSON_PRETTY_PRINT), PHP_EOL;
};

// ⛔ docs/77 C.2: a gate and an open A0 shadow period cannot share the switches. `off` pauses the
// executive and turns the runtime off, which would end the shadow without a record, so neither `on`
// nor `off` runs while a period is open; `shadow` lets run.sh refuse before anything is touched.
$openPeriods = class_exists(ShadowPeriod::class) && Schema::hasTable('shadow_periods')
    ? ShadowPeriod::query()->where('status', 'open')->pluck('agent_key')->all()
    : [];
if (in_array($action, ['on', 'off'], true) && $openPeriods !== []) {
    echo "REFUSED: a shadow period is open for ".implode(', ', $openPeriods).'; close it with hq:shadow:close before a gate touches the switches.', PHP_EOL;

    return;
}

switch ($action) {
    case 'shadow':
        echo json_encode(['open_shadow_periods' => $openPeriods]), PHP_EOL;
        break;

    case 'on':
        if ($executive->enabling_user_id === null) {
            echo "REFUSED: [{$agentKey}] names no enabling user; a person sets one before a gate can run it.", PHP_EOL;
            break;
        }
        $before = ['enabled' => (bool) $flag->enabled];
        $flag->enabled = true;
        $flag->save();
        app(Features::class)->forget();
        $a1 = $auditor->recordSystem('feature_flag.toggled', $flag, $before, ['enabled' => true],
            "{$gate}: ai.runtime enabled deliberately for the fresh safety gate only; turned OFF again immediately after.");

        $before = ['status' => $executive->status];
        $executive->status = 'active';
        $executive->save();
        $a2 = $auditor->recordSystem('agent.status_changed', $executive, $before, ['status' => 'active'],
            "{$gate}: {$agentKey} set active for the evaluation run only, enabling user unchanged; paused again immediately after.");

        echo "audits: flag on #{$a1->id}, {$agentKey} active #{$a2->id}", PHP_EOL;
        $posture();
        break;

    case 'off':
        $before = ['status' => $executive->status];
        $executive->status = 'paused';
        $executive->save();
        $a1 = $auditor->recordSystem('agent.status_changed', $executive, $before, ['status' => 'paused'],
            "{$gate} complete: {$agentKey} paused again immediately after the evaluation run.");

        $before = ['enabled' => (bool) $flag->enabled];
        $flag->enabled = false;
        $flag->save();
        app(Features::class)->forget();
        $a2 = $auditor->recordSystem('feature_flag.toggled', $flag, $before, ['enabled' => false],
            "{$gate} complete: ai.runtime turned OFF immediately after the evaluation run; activation is a separate decision.");

        echo "audits: {$agentKey} paused #{$a1->id}, flag off #{$a2->id}", PHP_EOL;
        $posture();
        break;

    case 'bypass':
        // docs/77 E.1: the same call as before, now recorded on the credential as what the answer
        // means (accepted, invalid key, no credit, no permission, provider down). The JSON still
        // opens with "http", which is what run.sh reads.
        echo json_encode(app(ProviderCheck::class)->run('gate_bypass')), PHP_EOL;
        break;

    default:
        $posture();
}
