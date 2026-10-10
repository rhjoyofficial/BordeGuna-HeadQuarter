<?php

use App\Context\Provenance;
use App\Models\Business;
use App\Models\FactsOrder;
use App\Models\OutcomeAttribution;
use App\Models\Store;
use App\Models\ToolCall;
use App\Models\User;
use App\Outcomes\OutcomeRecorder;
use App\Outcomes\OutcomeSource;
use App\Outcomes\OutcomeSpec;
use App\Outcomes\ResultRefSpec;
use App\Outcomes\ResultType;
use App\Tools\Outcomes\AttributeOutcomeTool;
use App\Tools\ToolContext;
use Database\Seeders\DomainSeeder;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
 | docs/110 §3 Q11 ("no direct controller/database write path") and Q7 ("explicit BusinessScope::restrict() at
 | service/query boundaries; a global scope can supplement but never replace"), docs/115 §4.3 and §7.
 |
 | STRUCTURAL: these tests read the source, so a new write path or an unscoped read fails the suite before anybody
 | has to remember to review it. The allow-lists below are the reviewed exceptions; adding a file to one is a
 | conscious change made together with the code it permits.
 */

const OUTCOME_TABLES = 'outcomes|outcome_attributions|outcome_result_refs';

/** PHP source with comments and docblocks removed, so prose naming a method or a table is never read as code. */
function owpCode(string $path): string
{
    $out = '';
    foreach (token_get_all((string) file_get_contents($path)) as $token) {
        if (is_array($token)) {
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                continue;
            }
            $out .= $token[1];
        } else {
            $out .= $token;
        }
    }

    return $out;
}

/** @return array<string, string> path relative to the given root => code without comments */
function owpFiles(string $root): array
{
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            $files[$relative] = owpCode($file->getPathname());
        }
    }
    ksort($files);

    return $files;
}

it('writes the three ledgers from OutcomeRecorder and nowhere else in app/', function () {
    $writers = [];
    foreach (owpFiles(app_path()) as $path => $code) {
        // Eloquent writes on the three models, the relation write the recorder uses, and raw table writes.
        if (preg_match('/\b(Outcome|OutcomeAttribution|OutcomeResultRef)::(create|forceCreate|insert|insertGetId|upsert|updateOrCreate|firstOrCreate)\s*\(/', $code)
            || preg_match('/->(results|attributions)\(\)->(create|insert|save|createMany)\s*\(/', $code)
            || preg_match('/table\(\s*[\'"](?:'.OUTCOME_TABLES.')[\'"]\s*\)(?:->[a-zA-Z]+\([^)]*\))*->(insert|insertGetId|update|upsert|delete|truncate)\s*\(/', $code)
            || preg_match('/\b(unprepared|statement|insert|update|delete)\s*\(\s*[\'"][^\'"]*\b(?:INSERT|UPDATE|DELETE)\b[^\'"]*\b(?:'.OUTCOME_TABLES.')\b/i', $code)) {
            $writers[] = $path;
        }
    }

    expect($writers)->toBe(['Outcomes/OutcomeRecorder.php']);
});

it('reaches OutcomeRecorder only from the outcome namespace and the one gateway tool; no controller, command or route touches the ledger', function () {
    $permitted = ['Tools/Outcomes/AttributeOutcomeTool.php'];
    $callers = [];
    foreach (owpFiles(app_path()) as $path => $code) {
        if (str_starts_with($path, 'Outcomes/')) {
            continue;
        }
        if (preg_match('/\bOutcomeRecorder\b/', $code)) {
            $callers[] = $path;
        }
    }
    expect($callers)->toBe($permitted);

    // The tool is the real caller, and it asserts only as an operator: it never names a proof method.
    $tool = owpCode(app_path('Tools/Outcomes/AttributeOutcomeTool.php'));
    expect(preg_match('/\$this->recorder->attribute\s*\(/', $tool))->toBe(1)
        ->and(preg_match('/AttributionMethod::OperatorAsserted/', $tool))->toBe(1)
        ->and(preg_match('/AttributionMethod::(DeterministicKey|Correlation|RuleWindow|ModelInferred)/', $tool))->toBe(0)
        ->and(preg_match('/->(supersede|retract|record)\s*\(/', $tool))->toBe(0, 'the tool creates assertions only; correction and retraction have no tool in v1');

    // No HTTP, console or route path names the ledger at all (models, recorder or tables).
    $ledger = '/\b(App\\\\Models\\\\(Outcome|OutcomeAttribution|OutcomeResultRef)|OutcomeRecorder|'.OUTCOME_TABLES.')\b/';
    $surface = [];
    foreach (owpFiles(app_path()) as $path => $code) {
        if ((str_starts_with($path, 'Http/') || str_starts_with($path, 'Console/')) && preg_match($ledger, $code)) {
            $surface[] = $path;
        }
    }
    foreach (owpFiles(base_path('routes')) as $path => $code) {
        if (preg_match($ledger, $code)) {
            $surface[] = 'routes/'.$path;
        }
    }
    expect($surface)->toBe([]);

    foreach (Route::getRoutes()->getRoutes() as $route) {
        expect(str_contains(mb_strtolower($route->uri()), 'outcome'))->toBeFalse("route {$route->uri()} exposes the outcome ledger");
    }
});

it('reads the ledger only through an explicit BusinessScope restriction, and declares no global scope (ruling Q7, D19)', function () {
    // Every file in app/ that reads one of the three models or tables, and why each is allowed:
    $readers = [
        'Models/Outcome.php',                        // scopeVisibleTo -> BusinessScope::restrict (pinned below)
        'Models/OutcomeAttribution.php',             // same
        'Models/OutcomeResultRef.php',               // whereHas('outcome') -> the outcome's restriction
        'Observe/Retention/RetentionPolicy.php',     // EXISTS sub-selects in keep-clauses: decide what to KEEP, expose no row to anybody
        'Outcomes/OutcomeRecorder.php',              // the write path's own idempotency look-ups by key, inside a validated spec; exposes nothing
        'Outcomes/Recorders/ErpOrderPlacedRecorder.php', // type-hints the model it returns; reads none
        'Tools/Outcomes/AttributeOutcomeTool.php',   // restrictBusinesses() + business_id = the gateway-resolved business (pinned below)
    ];
    $found = [];
    foreach (owpFiles(app_path()) as $path => $code) {
        if (preg_match('/\b(Outcome|OutcomeAttribution|OutcomeResultRef)\b/', $code) && preg_match('/\\\\Models\\\\(Outcome|OutcomeAttribution|OutcomeResultRef)\b|\bnamespace App\\\\Models\b/', $code)
            // A table name counts only where a query names it (a builder call or SQL), never as a plain word or array key.
            || preg_match('/(?:table|from|join)\(\s*[\'"](?:'.OUTCOME_TABLES.')\b|\b(?:FROM|JOIN|INTO|UPDATE)\s+(?:'.OUTCOME_TABLES.')\b/i', $code)) {
            $found[] = $path;
        }
    }
    expect($found)->toBe($readers);

    // The models' scope is the one existing rule, named explicitly, and no Eloquent global scope stands in for it.
    foreach (['Outcome', 'OutcomeAttribution'] as $model) {
        $code = owpCode(app_path("Models/{$model}.php"));
        expect(preg_match('/function scopeVisibleTo\(.*?BusinessScope::class\)->restrict\(\$query, \$user, \$query->qualifyColumn\(\'business_id\'\)\)/s', $code))->toBe(1, "{$model}::scopeVisibleTo does not call BusinessScope::restrict")
            ->and(str_contains($code, 'addGlobalScope'))->toBeFalse("{$model} declares a global scope (D19)");
    }
    $refs = owpCode(app_path('Models/OutcomeResultRef.php'));
    expect(preg_match('/function scopeVisibleTo\(.*?whereHas\(\'outcome\'/s', $refs))->toBe(1)
        ->and(str_contains($refs, 'addGlobalScope'))->toBeFalse();

    // The tool's read is restricted twice: by the caller's permitted businesses and by the business the gateway resolved.
    $tool = owpCode(app_path('Tools/Outcomes/AttributeOutcomeTool.php'));
    expect(preg_match('/\$context->restrictBusinesses\(Outcome::query\(\)\)\s*->where\(\'business_id\', \$context->businessId\)/s', $tool))->toBe(1);
});

it('holds the tool\'s own restriction even if the gateway\'s scope check were ever bypassed', function () {
    $this->seed(DomainSeeder::class);
    $laladia = Business::where('slug', 'laladia')->sole();
    $bionic = Business::where('slug', 'bionic')->sole();
    $store = Store::where('slug', 'laladia-com')->sole();
    $order = FactsOrder::query()->forceCreate(['business_id' => $laladia->id, 'store_id' => $store->id, 'order_ref' => 'LD-8', 'source' => 'checkout', 'status' => 'delivered']);
    $outcome = app(OutcomeRecorder::class)->record(new OutcomeSpec(
        type: 'order.delivered', businessId: $laladia->id, occurredAt: now(), source: OutcomeSource::ShopWebhook, sourceSystem: 'shop',
        provenance: Provenance::Business, idempotencyKey: 'order.delivered:shop:'.$store->id.':LD-8',
        results: [new ResultRefSpec(ResultType::ShopOrder, 'shop', 'laladia-com:LD-8', (int) $order->id)],
    ));
    $call = ToolCall::query()->forceCreate(['tool_key' => 'messaging.send_reply', 'status' => 'succeeded', 'idempotency_key' => 'r-'.Str::random(6), 'business_id' => $laladia->id, 'created_at' => now()]);
    $operator = User::factory()->create();
    $args = ['business_slug' => 'laladia', 'outcome_uuid' => $outcome->uuid, 'action_type' => 'tool_call', 'action_id' => $call->id, 'role' => 'contributed',
        'confidence' => 0.5, 'reason' => 'a reason long enough to pass the schema', 'evidence_ref' => 'ticket:1'];
    $tool = app(AttributeOutcomeTool::class);

    // A caller permitted only bionic, handed laladia's business id: the outcome is not reachable.
    $narrowed = $tool->execute($args, new ToolContext(idempotencyKey: 'k1', correlationId: 'c', businessId: $laladia->id, actorUserId: $operator->id, permittedBusinessIds: [$bionic->id]));
    expect($narrowed->ok())->toBeFalse()->and($narrowed->error)->toContain('No outcome');

    // The resolved business is bionic: laladia's outcome is not reachable either, whatever the uuid says.
    $wrongBusiness = $tool->execute($args, new ToolContext(idempotencyKey: 'k2', correlationId: 'c', businessId: $bionic->id, actorUserId: $operator->id));
    expect($wrongBusiness->ok())->toBeFalse()->and($wrongBusiness->error)->toContain('No outcome');

    // No business resolved at all: refused, never a group-wide read.
    $none = $tool->execute($args, new ToolContext(idempotencyKey: 'k3', correlationId: 'c', businessId: null, actorUserId: $operator->id));
    expect($none->ok())->toBeFalse()->and($none->error)->toContain('Name the business');

    expect(OutcomeAttribution::count())->toBe(0);

    // And the right business, the right caller: recorded.
    $ok = $tool->execute($args, new ToolContext(idempotencyKey: 'k4', correlationId: 'c', businessId: $laladia->id, actorUserId: $operator->id, permittedBusinessIds: [$laladia->id]));
    expect($ok->ok())->toBeTrue((string) $ok->error)->and(OutcomeAttribution::count())->toBe(1);
});
