<?php

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;

/*
 | docs/135 (W-UI1): the redesigned shell, tokens and navigation. The wave changes how HQ looks, never what it does,
 | so these tests pin the parts a restyle could quietly break: every sidebar entry still leads to a real screen behind
 | the same permission the route enforces; both themes define the same tokens with readable contrast; the Bangla font,
 | the focus ring, the touch size and reduced motion are all in place.
 */

/** The sidebar entries as written in app-sidebar.tsx: title, url, permission, and whether the link leaves the app. */
function shellNavEntries(): array
{
    $source = file_get_contents(resource_path('js/components/app-sidebar.tsx'));
    preg_match_all("/\\{ title: '([^']+)', url: '([^']+)', icon: \\w+, permission: '([^']+)'([^}]*)\\}/", $source, $m, PREG_SET_ORDER);

    return array_map(fn ($row) => [
        'title' => $row[1],
        'url' => $row[2],
        'permission' => $row[3],
        'external' => str_contains($row[4], 'external: true'),
    ], $m);
}

/** The custom properties a theme block in app.css sets, by name. */
function shellThemeTokens(string $selector): array
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

/** WCAG relative luminance of an sRGB colour given as [r, g, b] in 0..255. */
function shellLuminance(array $rgb): float
{
    $c = array_map(function ($v) {
        $v /= 255;

        return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
    }, $rgb);

    return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
}

function shellHex(string $hex): array
{
    $hex = ltrim($hex, '#');

    return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
}

function shellContrast(array $a, array $b): float
{
    [$hi, $lo] = [max(shellLuminance($a), shellLuminance($b)), min(shellLuminance($a), shellLuminance($b))];

    return ($hi + 0.05) / ($lo + 0.05);
}

/** `over` laid on `under` at `alpha`, the way a glow or a glass tint sits on the ground. */
function shellBlend(array $under, array $over, float $alpha): array
{
    return array_map(fn ($u, $o) => $u * (1 - $alpha) + $o * $alpha, $under, $over);
}

it('keeps every sidebar entry pointing at a real screen behind the permission its route enforces', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $entries = shellNavEntries();

    // 25 entries: the 24 of before the regrouping, plus the cross-business review.
    expect($entries)->toHaveCount(25)
        ->and(array_unique(array_column($entries, 'url')))->toHaveCount(25);

    $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => in_array('GET', $r->methods(), true));

    foreach ($entries as $entry) {
        expect(Permission::where('name', $entry['permission'])->exists())->toBeTrue("unknown permission on {$entry['title']}");

        if ($entry['external']) {
            continue;
        }

        $route = $routes->first(fn ($r) => $r->uri() === ltrim($entry['url'], '/'));
        expect($route)->not->toBeNull("no GET route for {$entry['title']} ({$entry['url']})");

        $gates = collect($route->gatherMiddleware())->filter(fn ($m) => is_string($m) && str_starts_with($m, 'permission:'))
            ->flatMap(fn ($m) => explode('|', substr($m, strlen('permission:'))))
            ->all();
        expect($gates)->toContain($entry['permission']);
    }
});

it('links the cross-business review from the sidebar only for those who may review', function () {
    $review = collect(shellNavEntries())->firstWhere('url', '/customers/quarantine');

    expect($review)->not->toBeNull()
        ->and($review['title'])->toBe('Cross-business review')
        ->and($review['permission'])->toBe('hq.customers.quarantine.review');
});

it('lights one sidebar entry per page, the longest matching one', function () {
    $nav = file_get_contents(resource_path('js/components/nav-main.tsx'));

    expect($nav)->toContain('function activeUrl(')
        ->and($nav)->toContain('isActive={item.url === active}');
});

it('defines the same tokens in the light and the dark theme', function () {
    $light = shellThemeTokens(':root');
    $dark = shellThemeTokens('.dark');

    $required = [
        'background', 'foreground', 'card', 'card-foreground', 'popover', 'popover-foreground', 'primary', 'primary-foreground',
        'secondary', 'muted', 'muted-foreground', 'accent', 'destructive', 'border', 'input', 'ring',
        'sidebar-background', 'sidebar-foreground', 'sidebar-accent', 'sidebar-border',
        'hq-violet', 'hq-teal', 'hq-orange', 'hq-glass', 'hq-glass-border', 'hq-glass-highlight', 'hq-shadow-raised', 'hq-shadow-float', 'hq-ground',
    ];

    foreach ($required as $name) {
        expect($light)->toHaveKey($name)
            ->and($dark)->toHaveKey($name);
    }

    // Joy's ground (docs/135 (d)).
    expect($dark['background'])->toBe('#07080c');
});

it('keeps body and secondary text at 4.5:1 or better in both themes, on the brightest glow too', function () {
    foreach ([':root' => [108, 92, 231, 0.1], '.dark' => [108, 92, 231, 0.38]] as $selector => [$r, $g, $b, $glow]) {
        $t = shellThemeTokens($selector);
        $ground = shellHex($t['background']);
        $brightest = shellBlend($ground, [$r, $g, $b], $glow);

        foreach (['foreground', 'muted-foreground'] as $text) {
            expect(shellContrast(shellHex($t[$text]), $ground))->toBeGreaterThanOrEqual(4.5, "{$selector} {$text} on the ground")
                ->and(shellContrast(shellHex($t[$text]), $brightest))->toBeGreaterThanOrEqual(4.5, "{$selector} {$text} on the glow");
        }

        expect(shellContrast(shellHex($t['primary-foreground']), shellHex($t['primary'])))->toBeGreaterThanOrEqual(4.5, "{$selector} primary")
            ->and(shellContrast(shellHex($t['destructive-foreground']), shellHex($t['destructive'])))->toBeGreaterThanOrEqual(4.5, "{$selector} destructive");
    }
});

it('loads Geist and Noto Sans Bengali, puts Bangla first for a Bangla page, and keeps focus, touch size and reduced motion', function () {
    $css = file_get_contents(resource_path('css/app.css'));
    $blade = file_get_contents(resource_path('views/app.blade.php'));

    expect($blade)->toContain('geist:')->toContain('noto-sans-bengali:')->toContain('geist-mono:')
        ->and($css)->toContain("--font-sans: 'Geist', 'Noto Sans Bengali'")
        ->and($css)->toContain('html:lang(bn) body')
        ->and($css)->toContain(':focus-visible')
        ->and($css)->toContain('@custom-variant touch (@media (pointer: coarse));')
        ->and($css)->toContain('@media (prefers-reduced-motion: reduce)')
        ->and($css)->toContain('@media (prefers-reduced-transparency: reduce)');
});

it('offers light, dark and the device theme from the top bar, beside the language switch', function () {
    $header = file_get_contents(resource_path('js/components/app-sidebar-header.tsx'));
    $toggle = file_get_contents(resource_path('js/components/theme-toggle.tsx'));

    expect($header)->toContain('<ThemeToggle />')->toContain('<LanguageToggle />')
        ->and($toggle)->toContain("value: 'light'")->toContain("value: 'dark'")->toContain("value: 'system'")
        // The theme is a look, kept in this browser as before; it posts nothing.
        ->and($toggle)->not->toContain('router.')->not->toContain('fetch(')->not->toContain('axios');
});

it('renders the shell for a signed-in owner with the regrouped sections shared as before', function () {
    $owner = $this->hqUser('owner');

    $this->actingAsHq($owner)->get('/dashboard')->assertOk()
        ->assertInertia(fn ($page) => $page->component('dashboard')->has('auth.permissions'));
});
