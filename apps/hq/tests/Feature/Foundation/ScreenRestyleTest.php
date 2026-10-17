<?php

/*
 | docs/135 (h), W-UI3: every existing screen restyled onto the W-UI1 tokens. A restyle changes how HQ looks, never
 | what it does, so these tests pin what a later edit could quietly undo: status tones that read at 4.5:1 in both
 | themes, no screen with its own hard-coded palette colour, every table in a scrolling table box, the inert state
 | that names why a feature is off and what turns it on, and dialogs that fit a phone.
 |
 | The dashboard is left out on purpose: `/dashboard` belongs to W-UI2 (`ui/w-ui2-console`).
 */

/** The custom properties a theme block in app.css sets, by name. */
function restyleThemeTokens(string $selector): array
{
    $css = file_get_contents(resource_path('css/app.css'));
    preg_match('/^'.preg_quote($selector, '/').' \{(.*?)^\}/ms', $css, $block);
    preg_match_all('/--([a-z0-9-]+):\s*([^;]+);/', $block[1] ?? '', $m, PREG_SET_ORDER);

    $out = [];
    foreach ($m as $row) {
        $out[$row[1]] = trim(preg_replace('/\s+/', ' ', $row[2]));
    }

    return $out;
}

/** [r, g, b, alpha] from `#rrggbb` or `rgb(r g b / a)`. */
function restyleColour(string $value): array
{
    if (preg_match('/^#([0-9a-f]{6})$/i', $value, $m)) {
        return [hexdec(substr($m[1], 0, 2)), hexdec(substr($m[1], 2, 2)), hexdec(substr($m[1], 4, 2)), 1.0];
    }
    if (preg_match('/^rgb\((\d+) (\d+) (\d+)(?: \/ ([0-9.]+))?\)$/', $value, $m)) {
        return [(int) $m[1], (int) $m[2], (int) $m[3], isset($m[4]) ? (float) $m[4] : 1.0];
    }

    throw new RuntimeException("not a colour: {$value}");
}

function restyleOver(array $under, array $over): array
{
    $a = $over[3];

    return [$under[0] * (1 - $a) + $over[0] * $a, $under[1] * (1 - $a) + $over[1] * $a, $under[2] * (1 - $a) + $over[2] * $a, 1.0];
}

function restyleContrast(array $a, array $b): float
{
    $lum = function (array $rgb): float {
        $c = array_map(function ($v) {
            $v /= 255;

            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }, array_slice($rgb, 0, 3));

        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    };
    [$hi, $lo] = [max($lum($a), $lum($b)), min($lum($a), $lum($b))];

    return ($hi + 0.05) / ($lo + 0.05);
}

/** Every screen W-UI3 restyled: all pages but the dashboard, and the components only those pages use. */
function restyledScreens(): array
{
    $files = [];
    foreach (['js/pages', 'js/components/customers', 'js/components/agents', 'js/components/observe', 'js/components/scheduler', 'js/components/approvals', 'js/layouts/settings'] as $dir) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path($dir), FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $path = str_replace('\\', '/',$file->getPathname());
            if (str_ends_with($path, '.tsx') && ! str_ends_with($path, 'js/pages/dashboard.tsx')) {
                $files[] = $file->getPathname();
            }
        }
    }
    sort($files);

    return $files;
}

const RESTYLE_TONES = ['ok', 'pending', 'warn', 'danger', 'info', 'neutral'];

it('defines every status tone in both themes', function () {
    foreach ([':root', '.dark'] as $selector) {
        $t = restyleThemeTokens($selector);
        foreach (RESTYLE_TONES as $tone) {
            foreach (["tone-{$tone}", "tone-{$tone}-bg", "tone-{$tone}-line", "tone-{$tone}-dot"] as $name) {
                expect($t)->toHaveKey($name);
            }
        }
        expect($t)->toHaveKey('hq-field');
    }
});

it('keeps every status tone at 4.5:1 or better on its own tint, over the ground, the brightest glow and a card', function () {
    foreach ([':root' => [108, 92, 231, 0.1], '.dark' => [108, 92, 231, 0.38]] as $selector => [$r, $g, $b, $glow]) {
        $t = restyleThemeTokens($selector);
        $ground = restyleColour($t['background']);
        $grounds = [
            'ground' => $ground,
            'glow' => restyleOver($ground, [$r, $g, $b, $glow]),
            'card' => restyleOver($ground, restyleColour($t['card'])),
        ];

        foreach (RESTYLE_TONES as $tone) {
            $text = restyleColour($t["tone-{$tone}"]);
            foreach ($grounds as $where => $under) {
                $chip = restyleOver($under, restyleColour($t["tone-{$tone}-bg"]));
                expect(restyleContrast($text, $chip))->toBeGreaterThanOrEqual(4.5, "{$selector} {$tone} chip on the {$where}")
                    // The same tone used as plain text on the page.
                    ->and(restyleContrast($text, $under))->toBeGreaterThanOrEqual(4.5, "{$selector} {$tone} text on the {$where}");
            }
        }
    }
});

it('gives no restyled screen a hard-coded palette colour of its own', function () {
    $offenders = [];
    foreach (restyledScreens() as $file) {
        $source = file_get_contents($file);
        if (preg_match_all('/\b(?:text|bg|border|ring)-(?:amber|yellow|orange|red|rose|emerald|green|sky|blue|neutral|gray|slate|zinc)-\d{2,3}\b/', $source, $m)) {
            $offenders[] = basename($file).': '.implode(', ', array_unique($m[0]));
        }
    }

    expect($offenders)->toBe([]);
});

it('puts every table on a restyled screen in a scrolling table box', function () {
    $offenders = [];
    foreach (restyledScreens() as $file) {
        $lines = explode("\n", file_get_contents($file));
        foreach ($lines as $i => $line) {
            if (! str_contains($line, '<table')) {
                continue;
            }
            $j = $i - 1;
            while ($j >= 0 && trim($lines[$j]) === '') {
                $j--;
            }
            if (! str_contains($lines[$j] ?? '', 'hq-table-box') && ! str_contains($lines[$j] ?? '', '<TableBox')) {
                $offenders[] = basename($file).':'.($i + 1);
            }
        }
    }

    expect($offenders)->toBe([])
        ->and(file_get_contents(resource_path('js/components/observe/panels.tsx')))->toContain('<TableBox>')
        ->and(file_get_contents(resource_path('css/app.css')))->toContain('.hq-table-box > table > thead > tr > th')->toContain('position: sticky;');
});

it('uses the shared multi-line box rather than a hand-styled textarea', function () {
    $offenders = [];
    foreach (restyledScreens() as $file) {
        if (str_contains(file_get_contents($file), '<textarea')) {
            $offenders[] = basename($file);
        }
    }

    expect($offenders)->toBe([]);
});

it('shows a switched-off feature with why it is off and what turns it on, never hiding it', function () {
    $empty = file_get_contents(resource_path('js/components/empty-state.tsx'));
    $notice = file_get_contents(resource_path('js/components/ui/notice.tsx'));

    expect($empty)->toContain("variant?: 'empty' | 'inert'")->toContain('reason?: ReactNode')->toContain('gate?: ReactNode')
        ->toContain("tn('Switched off')")->toContain("tn('Turns on when')")
        ->and($notice)->toContain('export function InertNotice(')->toContain('reason: ReactNode')->toContain("tn('Switched off')");

    $dictionary = json_decode(file_get_contents(resource_path('js/lib/i18n/bn.json')), true);
    expect($dictionary)->toHaveKeys(['Switched off', 'Why', 'Turns on when']);
});

it('draws status chips and callouts from the tone tokens only', function () {
    $badge = file_get_contents(resource_path('js/components/ui/status-badge.tsx'));
    $notice = file_get_contents(resource_path('js/components/ui/notice.tsx'));

    foreach (RESTYLE_TONES as $tone) {
        expect($badge)->toContain("bg-tone-{$tone}-bg text-tone-{$tone} ring-tone-{$tone}-line")
            ->and($notice)->toContain("bg-tone-{$tone}-bg border-tone-{$tone}-line text-tone-{$tone}");
    }
    expect($badge)->not->toMatch('/(?:emerald|amber|orange|red|sky)-\d{2,3}/');
});

it('keeps dialogs inside a phone screen and scrollable, with a close control large enough to touch', function () {
    $dialog = file_get_contents(resource_path('js/components/ui/dialog.tsx'));

    expect($dialog)->toContain('w-[calc(100%-2rem)]')->toContain('max-h-[calc(100dvh-2rem)]')->toContain('overflow-y-auto')
        ->toContain('touch:size-11');
});

