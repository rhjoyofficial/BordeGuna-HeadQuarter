<?php

/**
 * Gate helpers, run inside the app container through tinker (docs/66 §E):
 *
 *   docker compose exec -T -e GATE_ACTION=posture app php artisan tinker --execute="require '/tmp/gate-toggle.php';"
 *
 * GATE_ACTION is one of:
 *   posture  read the AI posture from the database: flag row AND Features, agents, grants, consent, case counts
 *   bypass   the api.anthropic.com bypass test (docs/54): one 8-token call with the vault's key, from inside the
 *            container, so "bad key" is separated from "bad code" before HQ's own code is asked to work.
 *            The key is opened in a vault scope and never printed.
 *   on       ai.runtime ON + executive ACTIVE, each audited with a reason naming the gate
 *   off      executive PAUSED + ai.runtime OFF, each audited
 *
 * ⛔ Nothing here creates an autonomy grant, lifts a ceiling, or leaves anything on: `run.sh` calls `off` from an
 * EXIT trap, so the posture is restored whether the gate passes, fails or crashes.
 */

use App\Gateway\Credentials\CredentialVault;
use App\Models\Agent;
use App\Models\AiModel;
use App\Models\AuditLog;
use App\Models\AutonomyGrant;
use App\Models\ConsentEvent;
use App\Models\EvaluationSuite;
use App\Models\FeatureFlag;
use App\Support\Audit\Auditor;
use App\Support\Features\Features;
use Illuminate\Support\Facades\Http;

$action = getenv('GATE_ACTION') ?: 'posture';
$gate = getenv('GATE_NAME') ?: 'Gate 4 (docs/66)';
$auditor = app(Auditor::class);
$flag = FeatureFlag::where('key', 'ai.runtime')->orderBy('id')->firstOrFail();
$executive = Agent::where('key', 'executive')->firstOrFail();

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
        'cases' => ['safety.core' => $cases('safety.core'), 'behaviour.executive' => $cases('behaviour.executive')],
        'last_audit_id' => AuditLog::max('id'),
    ], JSON_PRETTY_PRINT), PHP_EOL;
};

switch ($action) {
    case 'on':
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
            "{$gate}: executive set active for the evaluation run only, enabling user unchanged; paused again immediately after.");

        echo "audits: flag on #{$a1->id}, executive active #{$a2->id}", PHP_EOL;
        $posture();
        break;

    case 'off':
        $before = ['status' => $executive->status];
        $executive->status = 'paused';
        $executive->save();
        $a1 = $auditor->recordSystem('agent.status_changed', $executive, $before, ['status' => 'paused'],
            "{$gate} complete: executive paused again immediately after the evaluation run.");

        $before = ['enabled' => (bool) $flag->enabled];
        $flag->enabled = false;
        $flag->save();
        app(Features::class)->forget();
        $a2 = $auditor->recordSystem('feature_flag.toggled', $flag, $before, ['enabled' => false],
            "{$gate} complete: ai.runtime turned OFF immediately after the evaluation run; activation is a separate decision.");

        echo "audits: executive paused #{$a1->id}, flag off #{$a2->id}", PHP_EOL;
        $posture();
        break;

    case 'bypass':
        $vault = app(CredentialVault::class);
        $model = AiModel::where('tier', 'low')->where('is_active', true)->orderBy('priority')->value('model_id');

        $response = $vault->openForAccounts([['anthropic_api_key', 'hq']], fn () => Http::withHeaders([
            'x-api-key' => $vault->secret('anthropic_api_key', 'hq'),
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ])->timeout(60)->post('https://api.anthropic.com/v1/messages', [
            'model' => $model,
            'max_tokens' => 8,
            'messages' => [['role' => 'user', 'content' => 'Reply with the single word ok.']],
        ]));

        echo json_encode([
            'http' => $response->status(),
            'id' => $response->json('id'),
            'usage' => $response->json('usage'),
            'error' => $response->json('error.message'),
        ]), PHP_EOL;
        break;

    default:
        $posture();
}
