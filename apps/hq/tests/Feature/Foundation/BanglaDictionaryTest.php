<?php

use Illuminate\Support\Facades\Route;

/*
 | docs/88 A point 7 (stream V): every operator screen reads in Bangla or English, and Joy's rules for
 | both hold everywhere: English digits in both languages, Bangla stored as Unicode NFC, the brand spelled
 | "Bor de Guna" / "বর দে গুনা", and no em dash in the screens. The dictionary is `resources/js/lib/i18n/bn.json`,
 | keyed by the English text the screens already show, so a missing entry falls back to English and a
 | screen is never blank.
 */

function bnDictionary(): array
{
    $json = file_get_contents(resource_path('js/lib/i18n/bn.json'));
    $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

    return $decoded;
}

/** The screens translated in full by stream V: every literal they hand to t() or to a translating prop. */
const BANGLA_SCREENS = [
    'js/pages/observe/posture.tsx',
    'js/components/app-sidebar.tsx',
    'js/components/observe/panels.tsx',
    'js/pages/observe/ai-health.tsx',
    'js/pages/observe/control-plane.tsx',
    'js/pages/observe/autonomy-evidence.tsx',
    'js/pages/credentials/index.tsx',
    'js/pages/approvals/index.tsx',
    'js/pages/agents/index.tsx',
    'js/pages/audit/index.tsx',
    // docs/108 (S7): the consent screen.
    'js/pages/customers/consent.tsx',
    // docs/109 (S2): the Customer 360 page.
    'js/pages/customers/show.tsx',
    // docs/111 (S5): the decision corpus page.
    'js/pages/observe/decision-corpus.tsx',
    // docs/112 (S6): the playbook store.
    'js/pages/playbooks/index.tsx',
    'js/pages/playbooks/show.tsx',
    // docs/113 (S4 part 1): follow-ups and opportunities.
    'js/pages/follow-ups/index.tsx',
    // docs/123: Customer 360's read-only follow-ups section.
    'js/components/customers/follow-ups-section.tsx',
    // docs/117: the cross-business quarantine review screen.
    'js/pages/customers/quarantine.tsx',
];

/** The literals a screen passes to `t()` or to a prop the shared components translate. */
function translatableLiterals(string $source): array
{
    $patterns = [
        "/\\bt\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/",
        '/\\bt\\(\\s*"((?:[^"\\\\]|\\\\.)*)"/',
        '/\\b(?:label|title|description|empty|detail|aside|summary)=\\s*"([^"]+)"/',
        "/\\b(?:label|title|description|empty|detail|aside|summary):\\s*'((?:[^'\\\\]|\\\\.)*)'/",
        '/<Note>\\s*([A-Z][^<{]+?)\\s*<\\/Note>/s',
    ];

    $out = [];
    foreach ($patterns as $pattern) {
        preg_match_all($pattern, $source, $m);
        foreach ($m[1] as $s) {
            $s = trim(preg_replace('/\\s+/', ' ', str_replace("\\'", "'", $s)));
            if (preg_match('/[A-Za-z]/', $s) && ! str_starts_with($s, '/') && ! str_starts_with($s, '#')) {
                $out[$s] = true;
            }
        }
    }

    return array_keys($out);
}

it('is valid JSON with a Bangla value for every English key', function () {
    $dictionary = bnDictionary();

    expect($dictionary)->not->toBeEmpty();
    foreach ($dictionary as $en => $bn) {
        expect($en)->toBeString()->not->toBe('')
            ->and($bn)->toBeString()->not->toBe('');
    }
});

it('stores every Bangla entry as NFC, with English digits and the same placeholders as its English key', function () {
    foreach (bnDictionary() as $en => $bn) {
        expect(Normalizer::isNormalized($bn, Normalizer::FORM_C))->toBeTrue("not NFC: {$en}")
            ->and(preg_match('/[\x{09E6}-\x{09EF}]/u', $bn))->toBe(0, "Bangla digit in: {$en}");

        preg_match_all('/\{\w+\}/', $en, $a);
        preg_match_all('/\{\w+\}/', $bn, $b);
        sort($a[0]);
        sort($b[0]);
        expect($b[0])->toBe($a[0], "placeholders differ: {$en}");
    }
});

it('spells the brand Bor de Guna and বর দে গুনা, never বোর', function () {
    $dictionary = bnDictionary();

    expect($dictionary['Bor de Guna'] ?? null)->toBe('বর দে গুনা');
    foreach ($dictionary as $en => $bn) {
        expect($bn)->not->toContain('বোর')
            ->and($en)->not->toContain('Bor De Guna');
    }
});

it('translates every string on the screens stream V covers', function () {
    $dictionary = bnDictionary();
    $missing = [];

    foreach (BANGLA_SCREENS as $file) {
        foreach (translatableLiterals(file_get_contents(resource_path($file))) as $literal) {
            if (! array_key_exists($literal, $dictionary)) {
                $missing[] = "{$file}: {$literal}";
            }
        }
    }

    expect($missing)->toBe([]);
});

it('never formats a number or a date in the browser\'s locale, so Bangla digits cannot appear', function () {
    $offenders = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('js'), FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if (! preg_match('/\.(ts|tsx)$/', $file->getFilename())) {
            continue;
        }
        $source = file_get_contents($file->getPathname());

        // toLocaleString(), toLocaleDateString(undefined, ...) and friends take the browser's locale.
        if (preg_match('/\.toLocale(?:Date|Time)?String\(\s*(?:\)|undefined)/', $source)) {
            $offenders[] = $file->getFilename();
        }
        // A Bangla digit written into a screen; the digit map in i18n.ts is the one place that needs them.
        if ($file->getFilename() !== 'i18n.ts' && preg_match('/[\x{09E6}-\x{09EF}]/u', $source)) {
            $offenders[] = $file->getFilename().' (Bangla digit)';
        }
    }

    expect($offenders)->toBe([]);
});

it('has no em dash anywhere in the screens or the dictionary', function () {
    $offenders = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('js'), FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if (preg_match('/\.(ts|tsx|json)$/', $file->getFilename()) && str_contains(file_get_contents($file->getPathname()), "\u{2014}")) {
            $offenders[] = $file->getFilename();
        }
    }

    expect($offenders)->toBe([]);
});

it("saves the language choice as the person's own preference, through the profile route and nothing else", function () {
    // docs/90 A.6 (stream V2) superseded the browser-kept choice of stream V: the switch now applies at once
    // and saves the preference through the person's profile settings; there is still no separate route.
    $toggle = file_get_contents(resource_path('js/components/language-toggle.tsx'));
    $i18n = file_get_contents(resource_path('js/lib/i18n.ts'));

    expect($toggle)->toContain("router.patch(route('profile.update')")->not->toContain('fetch(')->not->toContain('axios')
        ->and($i18n)->not->toContain('router.')->not->toContain('fetch(')->not->toContain('axios')->not->toContain('localStorage')
        ->and(collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_contains($r->uri(), 'locale'))->all())->toBe([]);
});
