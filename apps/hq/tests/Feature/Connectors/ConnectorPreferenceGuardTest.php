<?php

use App\Agents\AgentResponseSchema;
use App\Scheduling\Nl\JobSpecSchema;
use App\Tools\ToolRegistry;
use Symfony\Component\Finder\Finder;

/*
 | docs/101 §11 ruling 9.2: `preferProvider` MUST NOT become model-controlled vendor selection. It is
 | set only by HQ code from trusted configuration or routing. These tests hold that structurally:
 |
 |  1. no schema a model fills (every registered tool's arguments, the agent's response, the NL
 |     scheduler's job spec) has a field a model could use to name a provider;
 |  2. no code outside App\Connectors sets `preferProvider` unless it is on the allowlist below, and
 |     no allowlisted file may sit where model output arrives (tools, agents, HTTP);
 |  3. no statement that sets it reads a tool payload or model arguments, whatever file it is in.
 |
 | ⚠ The allowlist is EMPTY in this release: nothing sets a preference yet. Adding a file is a decision
 | recorded in docs/98 §2.4 (the first will be the `model_tiers.provider` bridge, docs/98 §5.4).
 */

/** Files outside app/Connectors allowed to set `preferProvider`, each from trusted config. */
const CONNECTOR_PREFERENCE_SETTERS = [];

/** Where model output or client input arrives; a setter may never live here. */
const CONNECTOR_PREFERENCE_FORBIDDEN_DIRS = ['app/Tools/', 'app/Agents/', 'app/Http/'];

/** A field name that would let whoever fills the schema name a vendor. */
function preferenceFieldIsVendorChoice(string $name): bool
{
    return preg_match('/prefer.?provider|preferred.?provider|^provider$|^vendor$|^connector$|^adapter$/i', $name) === 1;
}

/** Every property name in a JSON-schema-like array, at any depth. @return list<string> */
function preferenceSchemaFields(array $schema): array
{
    $names = [];
    foreach ($schema as $key => $value) {
        if ($key === 'properties' && is_array($value)) {
            foreach ($value as $name => $sub) {
                $names[] = (string) $name;
                if (is_array($sub)) {
                    array_push($names, ...preferenceSchemaFields($sub));
                }
            }
        } elseif (is_array($value)) {
            array_push($names, ...preferenceSchemaFields($value));
        }
    }

    return $names;
}

/** Statements (code only, comments stripped) that mention preferProvider and read model or payload input. @return list<string> */
function preferenceTaintedStatements(string $php): array
{
    $code = implode('', array_map(
        static fn ($t) => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t,
        token_get_all($php),
    ));

    $hits = [];
    foreach (preg_split('/;/', $code) as $statement) {
        if (stripos($statement, 'preferProvider') === false) {
            continue;
        }
        if (preg_match('/\$(payload|args|arguments|input|toolInput|toolCall|call)\b|->args\b|->arguments\b|->payload\b|\[\s*[\'"]prefer/i', $statement) === 1) {
            $hits[] = trim($statement);
        }
    }

    return $hits;
}

it('detects a preference set from a payload or model arguments, and ignores comments and trusted config', function () {
    $tainted = <<<'PHP'
<?php
$r = new ConnectorRequest('ai.complete', preferProvider: $payload['provider']);
$s = new ConnectorRequest(capability: 'ai.complete', preferProvider: $request->args['vendor']);
PHP;
    $clean = <<<'PHP'
<?php
// preferProvider: $payload['provider'] would be wrong
$r = new ConnectorRequest('ai.complete', preferProvider: config('ai.model_tiers.fast.provider'));
PHP;

    expect(preferenceTaintedStatements($tainted))->toHaveCount(2)
        ->and(preferenceTaintedStatements($clean))->toBe([]);
});

it('gives a model no schema field that names a provider: tool arguments, the agent response, the NL job spec', function () {
    $registry = app(ToolRegistry::class);
    expect($registry->keys())->not->toBeEmpty();

    $offenders = [];
    foreach ($registry->keys() as $key) {
        foreach (preferenceSchemaFields($registry->get($key)->inputSchema()) as $field) {
            if (preferenceFieldIsVendorChoice($field)) {
                $offenders[] = "{$key}.{$field}";
            }
        }
    }
    foreach (['agent response' => AgentResponseSchema::schema(), 'nl job spec' => JobSpecSchema::schema()] as $name => $schema) {
        foreach (preferenceSchemaFields($schema) as $field) {
            if (preferenceFieldIsVendorChoice($field)) {
                $offenders[] = "{$name}.{$field}";
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('lets only allowlisted HQ code outside App\\Connectors set preferProvider, never from model input', function () {
    $setters = [];
    $tainted = [];

    foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
        $relative = 'app/'.str_replace('\\', '/', $file->getRelativePathname());
        $php = $file->getContents();
        array_push($tainted, ...array_map(static fn ($s) => "{$relative}: {$s}", preferenceTaintedStatements($php)));

        if (str_starts_with($relative, 'app/Connectors/')) {
            continue;
        }
        if (preg_match('/preferProvider\s*:|->preferProvider\b|[\'"]preferProvider[\'"]/', $php) === 1) {
            $setters[] = $relative;
        }
    }

    expect($tainted)->toBe([])
        ->and(array_values(array_diff($setters, CONNECTOR_PREFERENCE_SETTERS)))->toBe([]);

    foreach (CONNECTOR_PREFERENCE_SETTERS as $allowed) {
        foreach (CONNECTOR_PREFERENCE_FORBIDDEN_DIRS as $dir) {
            expect(str_starts_with($allowed, $dir))->toBeFalse("{$allowed} sets a provider preference where model output arrives");
        }
    }
});
