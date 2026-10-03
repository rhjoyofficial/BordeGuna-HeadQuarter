<?php

use App\Connectors\Capabilities;
use App\Platform\Contracts\PlatformProvider;

/*
 | docs/97 §6 D2: every NEW external adapter enters through the ConnectorRegistry. These tests hold the
 | rule structurally, so a sixth registry or a provider bound straight into the container fails the
 | suite instead of waiting for a reviewer to notice.
 |
 | ⚠ Precise on purpose. Each allowlist below names the EXISTING code it admits and why; adding to one
 | is a decision for docs/98 §5, not a way to make a red test green.
 */

/** The legacy shapes (docs/95 §10 item 1) and the registry itself; ToolRegistry holds tools, not providers. */
const CONNECTOR_ALLOWED_REGISTRIES = [
    'App\\Platform\\PlatformRegistry',
    'App\\Commerce\\Support\\CommerceRegistry',
    'App\\Erp\\ErpRegistry',
    'App\\Ai\\Providers\\ProviderRegistry',
    'App\\Voice\\SpeechProviders',
    'App\\Connectors\\ConnectorRegistry',
    'App\\Tools\\ToolRegistry',
];

/**
 * Classes that name two or more adapters of one contract, so they choose between them.
 * `ResearchService::PROVIDERS` is a sixth legacy shape docs/95 did not count (docs/98 §5.3); the
 * legacy bridge names one adapter per contract and is listed for clarity.
 */
const CONNECTOR_ALLOWED_SELECTORS = [
    'App\\Research\\ResearchService',
    'App\\Connectors\\Legacy\\LegacyConnectors',
];

/** Existing container bindings of an adapter contract: the storage driver, swapped by its binding (docs/98 §5.7). */
const CONNECTOR_ALLOWED_BINDINGS = [
    'App\\Platform\\Contracts\\StorageDriver',
    'App\\Media\\LocalStorageDriver',
];

/** Source with comments removed: a docblock may name a class to say it is not wired; code may not. */
function connectorCodeOnly(string $php): string
{
    return implode('', array_map(
        static fn ($t) => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t,
        token_get_all($php),
    ));
}

/** @return array{namespace: string, uses: array<string, string>} */
function connectorNames(string $code): array
{
    preg_match('/^\s*namespace\s+([^;\s]+)\s*;/m', $code, $ns);
    $uses = [];
    preg_match_all('/^use\s+([A-Za-z0-9_\\\\]+)(?:\s+as\s+(\w+))?\s*;/m', $code, $matches, PREG_SET_ORDER);
    foreach ($matches as $m) {
        $uses[$m[2] ?? '' ?: substr((string) strrchr('\\'.$m[1], '\\'), 1)] = $m[1];
    }

    return ['namespace' => $ns[1] ?? '', 'uses' => $uses];
}

function connectorResolve(string $name, array $names): string
{
    if (str_starts_with($name, '\\')) {
        return ltrim($name, '\\');
    }
    $head = explode('\\', $name)[0];
    if (isset($names['uses'][$head])) {
        return $names['uses'][$head].substr($name, strlen($head));
    }

    return ltrim($names['namespace'].'\\'.$name, '\\');
}

/** The adapter contract a class is or implements, or null. */
function connectorContractOf(string $class): ?string
{
    if (! class_exists($class) && ! interface_exists($class)) {
        return null;
    }
    foreach ([...Capabilities::contracts(), PlatformProvider::class] as $contract) {
        if (is_a($class, $contract, true)) {
            return $contract;
        }
    }

    return null;
}

/** @return list<string> every class referenced as `X::class` or `new X` in a fragment, resolved */
function connectorReferences(string $fragment, array $names): array
{
    preg_match_all('/([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)::class|\bnew\s+([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)/', $fragment, $m, PREG_SET_ORDER);

    return array_values(array_unique(array_map(
        static fn ($match) => connectorResolve($match[1] !== '' ? $match[1] : $match[2], $names),
        array_filter($m, static fn ($match) => ! in_array(strtolower($match[1] !== '' ? $match[1] : $match[2]), ['static', 'self', 'parent'], true)),
    )));
}

/**
 * Container bindings in a service provider whose abstract or concrete is an adapter or its contract.
 *
 * @return list<string>
 */
function connectorAdapterBindings(string $php): array
{
    $code = connectorCodeOnly($php);
    $names = connectorNames($code);
    $found = [];

    preg_match_all('/->(bind|bindIf|singleton|singletonIf|scoped|scopedIf|instance|extend)\s*\(/', $code, $calls, PREG_OFFSET_CAPTURE);
    foreach ($calls[0] as [$call, $offset]) {
        // The whole argument list, by counting parentheses, so a closure body is included.
        $start = $offset + strlen($call);
        $depth = 1;
        for ($i = $start; $i < strlen($code) && $depth > 0; $i++) {
            $depth += match ($code[$i]) {
                '(' => 1, ')' => -1, default => 0
            };
        }
        foreach (connectorReferences(substr($code, $start, $i - $start), $names) as $class) {
            if (connectorContractOf($class) !== null && ! in_array($class, CONNECTOR_ALLOWED_BINDINGS, true)) {
                $found[] = $class;
            }
        }
    }

    return array_values(array_unique($found));
}

/** @return list<string> FQCNs declared in a file */
function connectorDeclaredClasses(string $php): array
{
    $tokens = token_get_all($php);
    $names = connectorNames(connectorCodeOnly($php));
    $classes = [];
    foreach ($tokens as $i => $token) {
        if (is_array($token) && in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
            // Skip `X::class` and anonymous `new class`.
            $prev = $tokens[$i - 1] ?? null;
            $before = is_array($prev) && $prev[0] === T_WHITESPACE ? ($tokens[$i - 2] ?? null) : $prev;
            if ((is_array($before) && in_array($before[0], [T_DOUBLE_COLON, T_NEW], true))) {
                continue;
            }
            for ($j = $i + 1; isset($tokens[$j]); $j++) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                    $classes[] = ltrim($names['namespace'].'\\'.$tokens[$j][1], '\\');

                    break;
                }
                if (! is_array($tokens[$j]) || $tokens[$j][0] !== T_WHITESPACE) {
                    break;
                }
            }
        }
    }

    return $classes;
}

/** @return iterable<string, string> path => source for every PHP file under a root */
function connectorPhpFiles(string $root): iterable
{
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (str_ends_with($file->getFilename(), '.php')) {
            yield str_replace('\\', '/', $file->getPathname()) => (string) file_get_contents($file->getPathname());
        }
    }
}

// ── The detectors catch what they are for ────────────────────────────────────────────────────────

it('detects an adapter bound straight into the container, through an import, an alias or a closure', function () {
    $php = <<<'PHP'
    <?php
    namespace App\Providers;
    use App\Voice\SpeechToText;
    use App\Voice\Providers\GoogleSpeechToText as Google;
    use App\Media\LocalStorageDriver;
    use App\Platform\Contracts\StorageDriver;
    class X {
        public function register(): void {
            $this->app->bind(SpeechToText::class, Google::class);
            $this->app->singleton('stt', function ($app) { return new \App\Research\Providers\TavilySearch($app->make('x')); });
            // $this->app->bind(\App\Ai\Providers\OpenAiProvider::class);  a comment is not code
            $this->app->bind(StorageDriver::class, fn () => new LocalStorageDriver);
            $this->app->singleton(\App\Tools\ToolRegistry::class);
        }
    }
    PHP;

    expect(connectorAdapterBindings($php))->toBe([
        'App\\Voice\\SpeechToText',
        'App\\Voice\\Providers\\GoogleSpeechToText',
        'App\\Research\\Providers\\TavilySearch',
    ]);
});

it('detects a declared class name, not a ::class reference or an anonymous class', function () {
    $php = "<?php\nnamespace App\\Media;\nfinal class ImageRegistry { public function f() { return [Foo::class, new class {}]; } }\n";

    expect(connectorDeclaredClasses($php))->toBe(['App\\Media\\ImageRegistry']);
});

// ── The rule, over the application ───────────────────────────────────────────────────────────────

it('admits no new *Registry or *Providers class: provider resolution goes through the ConnectorRegistry', function () {
    $offenders = [];
    foreach (connectorPhpFiles(app_path()) as $path => $php) {
        foreach (connectorDeclaredClasses($php) as $class) {
            if (preg_match('/(Registry|Providers)$/', $class) === 1 && ! in_array($class, CONNECTOR_ALLOWED_REGISTRIES, true)) {
                $offenders[] = "{$class} ({$path})";
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('admits no new class that chooses between two adapters of one contract', function () {
    $offenders = [];
    foreach (connectorPhpFiles(app_path()) as $path => $php) {
        $code = connectorCodeOnly($php);
        $names = connectorNames($code);
        $declared = connectorDeclaredClasses($php);
        if (array_intersect($declared, CONNECTOR_ALLOWED_SELECTORS) !== []) {
            continue;
        }

        $byContract = [];
        foreach (connectorReferences($code, $names) as $class) {
            $contract = connectorContractOf($class);
            // A contract itself is not a choice, and an adapter naming itself is not one either.
            if ($contract !== null && $contract !== $class && ! in_array($class, $declared, true) && ! interface_exists($class)) {
                $byContract[$contract][$class] = true;
            }
        }
        foreach ($byContract as $contract => $adapters) {
            if (count($adapters) > 1) {
                $offenders[] = "{$path} chooses between ".implode(', ', array_keys($adapters))." ({$contract})";
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('binds no provider adapter or adapter contract directly in a service provider or bootstrap file', function () {
    $offenders = [];
    foreach ([...connectorPhpFiles(app_path('Providers')), ...connectorPhpFiles(base_path('bootstrap'))] as $path => $php) {
        if (str_contains($path, '/bootstrap/cache/')) {
            continue;
        }
        foreach (connectorAdapterBindings($php) as $class) {
            $offenders[] = "{$path} binds {$class}";
        }
    }

    expect($offenders)->toBe([]);
});

it('ships config/hq-connectors.php empty, so this release activates no provider', function () {
    $config = require config_path('hq-connectors.php');

    expect($config)->toBe(['connectors' => []]);
});
