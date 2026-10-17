<?php

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use PragmaRX\Google2FA\Google2FA;

/*
 | docs/135 W-UI5: the phone layout and the voice screen. Pure UI. The bottom bar is the sidebar's own entries
 | in another place; voice mode is drawn and INERT (Phase 7, needs a speech provider and a ruling): one GET behind
 | the console's permission, and no screen anywhere touches a microphone or a speech API.
 */

beforeEach(function () {
    (new RolesAndPermissionsSeeder)->run();
});

function phoneUser(array|string|null $grant = 'owner'): User
{
    $user = User::factory()->create();
    if (is_array($grant)) {
        $user->givePermissionTo($grant);
    } elseif (is_string($grant)) {
        $user->assignRole($grant);
    }
    $user->forceFill(['two_factor_secret' => app(Google2FA::class)->generateSecretKey(), 'two_factor_confirmed_at' => now()])->save();

    return $user->fresh();
}

it('shows the voice screen to a person who may see the console, and says voice is not available', function () {
    $this->actingAsHq(phoneUser())->get('/voice')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('voice')
            ->where('voice.available', false)
            ->where('voice.phase', 'Phase 7')
            // The speech providers ship empty (App\Voice\SpeechProviders); the screen reads that, nothing more.
            ->where('voice.speech_provider_registered', false));

    $this->actingAsHq(phoneUser(['hq.dashboard.view']))->get('/voice')->assertOk();
});

it('keeps the voice screen behind the console\'s permission, and away from guests', function () {
    $this->actingAsHq(phoneUser(['hq.approvals.view']))->get('/voice')->assertForbidden();
    $this->actingAsHq(phoneUser(null))->get('/voice')->assertForbidden();

    auth()->logout();
    $this->get('/voice')->assertRedirect();
});

it('gives voice one GET route, gated like the console, and no way to send it anything', function () {
    $voice = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_contains($r->uri(), 'voice'))->values();

    expect($voice)->toHaveCount(1)
        ->and($voice[0]->uri())->toBe('voice')
        ->and($voice[0]->methods())->toBe(['GET', 'HEAD'])
        ->and($voice[0]->gatherMiddleware())->toContain('permission:hq.dashboard.view')
        ->and($voice[0]->gatherMiddleware())->toContain('2fa.verified');

    foreach (['post', 'put', 'patch', 'delete'] as $method) {
        $this->actingAsHq(phoneUser())->call(strtoupper($method), '/voice')->assertStatus(405);
    }
});

it('renders the inert reason on the voice screen, with the microphone drawn disabled and nothing that sends', function () {
    $page = file_get_contents(resource_path('js/pages/voice.tsx'));

    expect($page)->toContain('Voice mode belongs to Phase 7 of the HQ plan. It needs a speech provider and a ChatGPT architecture ruling before it can be built.')
        ->and($page)->toContain('This page does not use your microphone. Nothing is listened to, recorded or uploaded, and it sends nothing anywhere.')
        ->and($page)->toContain('a spoken yes will put an approval card on the screen')
        // Mute and show work are drawn and disabled with the reason; end and back are links to the console.
        ->and(preg_match_all('/disabled\s+aria-disabled="true"/', $page))->toBe(2)
        ->and($page)->toContain('href="/dashboard"')
        // No form, no request of its own, no prefetch.
        ->and($page)->not->toMatch('/<form|useForm|router\.|fetch\(|axios|XMLHttpRequest|WebSocket|EventSource|sendBeacon|prefetch/')
        // The orb's motion is wrapped in reduced motion.
        ->and($page)->toContain('@media (prefers-reduced-motion: no-preference)');
});

it('has no microphone, recording or speech API anywhere in the screens', function () {
    $offenders = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('js'), FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if (! preg_match('/\.(ts|tsx|js|jsx)$/', $file->getFilename())) {
            continue;
        }
        $source = file_get_contents($file->getPathname());
        if (preg_match('/getUserMedia|mediaDevices|MediaRecorder|SpeechRecognition|speechSynthesis|SpeechSynthesis|AudioContext|AudioWorklet|getDisplayMedia/', $source, $m)) {
            $offenders[] = $file->getFilename().' ('.$m[0].')';
        }
    }

    expect($offenders)->toBe([]);
});

it('builds the phone bar from the sidebar\'s own entries, each behind the permission its route enforces', function () {
    $bar = file_get_contents(resource_path('js/components/phone-bar.tsx'));
    $sidebar = file_get_contents(resource_path('js/components/app-sidebar.tsx'));
    preg_match_all("/\\{ title: '([^']+)', url: '([^']+)', icon: \\w+, permission: '([^']+)'/", $bar, $m, PREG_SET_ORDER);

    // Today, Approvals and Inbox (docs/135 (c)); Menu opens the full navigation.
    expect(array_column($m, 2))->toBe(['/dashboard', '/approvals', '/inbox'])
        ->and($bar)->toContain('setOpenMobile(true)');

    $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => in_array('GET', $r->methods(), true));
    foreach ($m as [, $title, $url, $permission]) {
        expect($sidebar)->toContain("{ title: '{$title}', url: '{$url}', icon: ")
            ->and($sidebar)->toMatch('/'.preg_quote("url: '{$url}'", '/').", icon: \\w+, permission: '".preg_quote($permission, '/')."'/");

        $route = $routes->first(fn ($r) => $r->uri() === ltrim($url, '/'));
        expect($route)->not->toBeNull()
            ->and($route->gatherMiddleware())->toContain("permission:{$permission}");
    }

    // One component, mounted once, in the sidebar layout only.
    $layout = file_get_contents(resource_path('js/layouts/app/app-sidebar-layout.tsx'));
    expect(substr_count($layout, '<PhoneBar />'))->toBe(1);
});

it('pins the console composer above the phone bar on a phone only, leaves room for the cards, and links the microphone to the voice screen', function () {
    $composer = file_get_contents(resource_path('js/components/console/composer.tsx'));
    $dashboard = file_get_contents(resource_path('js/pages/dashboard.tsx'));

    expect($composer)->toContain('fixed inset-x-0 bottom-[calc(4.75rem+env(safe-area-inset-bottom))] z-30 px-3 md:hidden')
        ->and(substr_count($composer, 'href="/voice"'))->toBe(2)
        ->and($composer)->toContain('form="console-composer"')
        ->and($dashboard)->toContain('pb-20 md:p-5');
});
