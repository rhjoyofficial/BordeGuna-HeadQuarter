<?php

use App\Agents\AgentRunner;
use App\Consent\ConsentRefusal;
use App\Execution\ExecutionContextRefused;
use App\Gateway\OutcomeClass;
use App\Models\Approval;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/*
 | Ruling 1 Nov 2026 (ChatGPT, binding): relative times and dates read in the reader's language on Bangla screens
 | (bn-BD, Asia/Dhaka, English digits), through ONE helper in `resources/js/lib/format.ts`; and every server reason
 | that reaches a screen keeps a stable code that `resources/js/lib/reasons.ts` maps to an approved sentence in
 | both languages, with a safe generic sentence for a code nobody mapped and the code kept for operators.
 */

function bnEntries(): array
{
    return json_decode(file_get_contents(resource_path('js/lib/i18n/bn.json')), true, flags: JSON_THROW_ON_ERROR);
}

/** The code => [label, message] pairs written in reasons.ts, read the way a reviewer reads them. */
function mappedReasons(): array
{
    $source = file_get_contents(resource_path('js/lib/reasons.ts'));
    // A string in either quote (prettier picks double quotes for one holding an apostrophe).
    $pattern = <<<'RE'
        /^\s*['"]?([a-z][a-z0-9_.]*)['"]?:\s*\{\s*label:\s*(['"])((?:(?!\2)[^\\]|\\.)*)\2,\s*message:\s*(['"])((?:(?!\4)[^\\]|\\.)*)\4,?\s*\}/ms
        RE;
    preg_match_all(
        $pattern,
        $source,
        $m,
        PREG_SET_ORDER,
    );

    $out = [];
    foreach ($m as $set) {
        $out[$set[1]] = [stripslashes($set[3]), stripslashes($set[5])];
    }

    return $out;
}

it('maps every reason code with an English label and message, each with its Bangla', function () {
    $reasons = mappedReasons();
    $bn = bnEntries();

    expect(count($reasons))->toBeGreaterThan(50);
    $missing = [];
    foreach ($reasons as $code => [$label, $message]) {
        foreach ([$label, $message] as $english) {
            if ($english === '' || ! array_key_exists($english, $bn)) {
                $missing[] = "{$code}: {$english}";
            }
        }
    }

    expect($missing)->toBe([]);
});

it('has the generic sentences in both languages, with the code placeholder kept', function () {
    $bn = bnEntries();

    foreach (['This action was refused. Details for operators: {code}', 'Unrecognised reason: {code}'] as $key) {
        expect($bn)->toHaveKey($key)
            ->and($bn[$key])->toContain('{code}');
    }
    expect($bn)->toHaveKey('This action was refused.')
        ->and($bn)->toHaveKey('Details for operators')
        ->and($bn)->toHaveKey('Reason code');
});

it('maps every reason code the server can hand a screen today', function () {
    $mapped = array_keys(mappedReasons());

    $server = array_merge(
        array_map(fn (OutcomeClass $c) => $c->value, OutcomeClass::cases()),
        array_map(fn (ConsentRefusal $c) => $c->value, ConsentRefusal::cases()),
        array_values(Approval::DECISION_REFUSAL_CODES),
        array_values(array_filter(
            (new ReflectionClass(ExecutionContextRefused::class))->getConstants(),
            fn ($v, $k) => $k !== 'GENERIC',
            ARRAY_FILTER_USE_BOTH,
        )),
        array_values(array_filter(
            (new ReflectionClass(AgentRunner::class))->getConstants(),
            fn ($v, $k) => str_starts_with($k, 'STOP_') && is_string($v),
            ARRAY_FILTER_USE_BOTH,
        )),
        ['model_error', 'ai_runtime_off', 'agent_unknown', 'kill_switch.ai_paused'],
    );

    // The group-wide switch refusals are literals in GroupWideAuthority::refusalDetail().
    preg_match_all("/'code' => '([a-z_]+)'/", file_get_contents(app_path('Support/Authorization/GroupWideAuthority.php')), $m);
    expect($m[1])->toHaveCount(5);
    $server = array_merge($server, $m[1]);

    expect(array_values(array_diff(array_unique($server), $mapped)))->toBe([]);
});

it('keeps a code on every fixed approval decision refusal', function () {
    $source = file_get_contents(app_path('Models/Approval.php'));
    preg_match('/function decisionRefusalReason.*?\n    }\n/s', $source, $decision);
    preg_match('/function separationOfDutiesReason.*?\n    }\n/s', $source, $separation);
    preg_match('/function doPlanSelfApprovalReason.*?\n    }\n/s', $source, $doPlan);

    preg_match_all("/(?:return|\\?)\\s+'((?:[^'\\\\]|\\\\.)+)'/", $decision[0].$separation[0].$doPlan[0], $m);
    $sentences = array_map(fn ($s) => str_replace("\\'", "'", $s), $m[1]);

    expect($sentences)->toHaveCount(5);
    foreach ($sentences as $sentence) {
        expect(Approval::DECISION_REFUSAL_CODES)->toHaveKey($sentence);
    }
});

it('leaves no English relative-time literal in a screen outside the one helper', function () {
    $bn = bnEntries();
    $offenders = [];
    $allowed = [resource_path('js/lib/format.ts'), resource_path('js/lib/i18n.ts')];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('js'), FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if (! preg_match('/\.(ts|tsx)$/', $file->getFilename()) || in_array(realpath($file->getPathname()), array_map('realpath', $allowed), true)) {
            continue;
        }
        $source = file_get_contents($file->getPathname());
        // Comments say what a helper prints; they are not on a screen.
        $source = preg_replace(['#/\*.*?\*/#s', '#(^|[^:])//[^\n]*#'], ['', '$1'], $source);

        // A literal handed to t(), translate() or a translating prop is a dictionary key: it must have its Bangla.
        $keyed = '/(?:\bt\(\s*|\btranslate\(\s*\w+,\s*|\b(?:label|title|description|empty|detail|aside|summary|placeholder)=\s*)([\'"])((?:(?!\1)[^\\\\]|\\\\.)*)\1/';
        preg_match_all($keyed, $source, $m);
        foreach ($m[2] as $literal) {
            $literal = str_replace("\\'", "'", $literal);
            if (preg_match('/\b(ago|just now|yesterday|tomorrow|in a moment)\b/i', $literal) && ! array_key_exists($literal, $bn)) {
                $offenders[] = $file->getFilename().': untranslated "'.$literal.'"';
            }
        }
        $rest = preg_replace($keyed, '', $source);

        // Anything else written as a string is English text that never reaches the dictionary.
        preg_match_all('/([\'"`])((?:(?!\1)[^\\\\\n]|\\\\.)*)\1/', $rest, $s);
        foreach ($s[2] as $literal) {
            // A placeholder such as {ago} names a value the helper fills; it is not English text.
            $literal = preg_replace('/\{\w+\}/', '{x}', $literal);
            if (preg_match('/\b(\d+|\{\w+\}|\$\{[^}]+\})\s*(s|sec|secs|seconds?|min|mins|minutes?|h|hr|hrs|hours?|days?|weeks?|months?)\s+ago\b|\bjust now\b|\bin a moment\b|^(yesterday|tomorrow)$|\bago\b/i', $literal)) {
                $offenders[] = $file->getFilename().': "'.$literal.'"';
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('routes the console ages and every timeAgo through the one helper', function () {
    $age = file_get_contents(resource_path('js/components/console/age.ts'));
    $format = file_get_contents(resource_path('js/lib/format.ts'));

    expect($age)->toContain("from '@/lib/format'")->not->toContain("'en-GB'")
        ->and($format)->toContain("'bn-BD'")->toContain("HQ_TIME_ZONE = 'Asia/Dhaka'")->toContain('Intl.RelativeTimeFormat')
        ->toContain('englishDigits(');
});

it('says relative times, dates and reasons in the reader\'s language when the helpers run', function () {
    $node = (new ExecutableFinder)->find('node');
    if ($node === null || ! is_dir(base_path('node_modules/esbuild'))) {
        $this->markTestSkipped('node or esbuild is not installed here');
    }

    // esbuild (a Vite dependency) bundles the harness with the app's own `@/` alias; node runs it.
    $out = tempnam(sys_get_temp_dir(), 'hq-bn-harness').'.mjs';
    $script = 'require("esbuild").buildSync({entryPoints:[process.argv[1]],bundle:true,platform:"node",format:"esm",'
        .'alias:{"@":process.argv[2]},logLevel:"error",outfile:process.argv[3]})';
    $build = new Process([$node, '-e', $script, base_path('tests/js/bangla-time-and-reasons.harness.ts'), resource_path('js'), $out], base_path());
    $build->mustRun();
    $run = new Process([$node, $out], base_path());
    $run->mustRun();
    @unlink($out);

    $r = json_decode($run->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    [$en, $bn] = [$r['en'], $r['bn']];

    // English stays as it read.
    expect($en['justNow'])->toBe('just now')
        ->and($en['fiveMinAgo'])->toBe('5 min ago')
        ->and($en['yesterday'])->toBe('yesterday')
        ->and($en['dateTime'])->toBe('1 Nov 2026, 11:03')   // 05:03 UTC is 11:03 in Dhaka
        ->and($en['date'])->toBe('2 Nov 2026');             // 20:00 UTC is already the next day in Dhaka

    // Bangla words, English digits, Dhaka time.
    expect($bn['justNow'])->toBe('এইমাত্র')
        ->and($bn['fiveMinAgo'])->toBe('5 মিনিট আগে')
        ->and($bn['twoHoursAgo'])->toBe('2 ঘণ্টা আগে')
        ->and($bn['yesterday'])->toBe('গতকাল')
        ->and($bn['fiveDaysAgo'])->toBe('5 দিন আগে')
        ->and($bn['never'])->toBe('কখনো না')
        ->and($bn['dateTime'])->toContain('11:03')->toContain('2026')->toContain('নভে')
        ->and($bn['duration'])->toBe('1 ঘণ্টা 30 মিনিট')
        ->and($bn['cron'])->toBe('প্রতিদিন 09:30-এ');
    foreach ($bn as $key => $value) {
        expect(preg_match('/[\x{09E6}-\x{09EF}]/u', json_encode($value, JSON_UNESCAPED_UNICODE)))->toBe(0, "Bangla digit in {$key}");
        if (is_string($value)) {
            expect($value)->not->toMatch('/\b(ago|just now|yesterday|tomorrow)\b/i');
        }
    }

    // A known code: the approved message in each language, the code and the server's sentence kept for operators.
    expect($en['known'])->toMatchArray(['code' => 'feature_disabled', 'known' => true, 'detail' => 'Tool x is behind flag y.'])
        ->and($en['known']['message'])->toStartWith('This feature is switched off')
        ->and($bn['known']['message'])->toBe(bnEntries()[$en['known']['message']]);

    // An unknown code: the generic message, with the code shown.
    expect($en['unknown']['message'])->toBe('This action was refused. Details for operators: some_new_code')
        ->and($bn['unknown']['message'])->toContain('some_new_code')->not->toContain('This action')
        ->and($bn['unknown']['known'])->toBeFalse();

    // Free text: as written on the English screen, never translated on the Bangla one (generic + details).
    expect($en['freeText']['message'])->toBe('A sentence the server wrote.')
        ->and($bn['freeText']['message'])->toBe(bnEntries()['This action was refused.'])
        ->and($bn['freeText']['detail'])->toBe('A sentence the server wrote.');

    expect($bn['label'])->toBe(bnEntries()['Timed out'])
        ->and($bn['unknownLabel'])->toContain('some_new_code');
});
