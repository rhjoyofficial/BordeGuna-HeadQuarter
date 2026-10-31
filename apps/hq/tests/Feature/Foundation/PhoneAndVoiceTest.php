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
    // The screen is one GET; docs/144 adds two POSTs behind their own permission, tested below.
    $voice = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => $r->uri() === 'voice')->values();
    $posts = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'voice/'))->values();
    expect($posts->map(fn ($r) => $r->uri())->sort()->values()->all())->toBe(['voice/speak', 'voice/transcribe'])
        ->and($posts->every(fn ($r) => $r->methods() === ['POST'] && in_array('permission:hq.voice.use', $r->gatherMiddleware(), true)))->toBeTrue();

    expect($voice)->toHaveCount(1)
        ->and($voice[0]->uri())->toBe('voice')
        ->and($voice[0]->methods())->toBe(['GET', 'HEAD'])
        ->and($voice[0]->gatherMiddleware())->toContain('permission:hq.dashboard.view')
        ->and($voice[0]->gatherMiddleware())->toContain('2fa.verified');

    foreach (['post', 'put', 'patch', 'delete'] as $method) {
        $this->actingAsHq(phoneUser())->call(strtoupper($method), '/voice')->assertStatus(405);
    }
});

it('says why voice is off when it is not connected, and keeps the microphone disabled and the page itself silent (docs/144)', function () {
    $page = file_get_contents(resource_path('js/pages/voice.tsx'));

    expect($page)->toContain('Not connected yet: voice is switched off.')
        ->and($page)->toContain('The microphone is asked for only when you click to talk. This page does not listen or record on its own.')
        // The disabled microphone is drawn when voice is not connected, with the reason as its title.
        ->and(preg_match_all('/disabled\s+aria-disabled="true"/', $page))->toBe(1)
        ->and($page)->toContain('href="/dashboard"')
        // The only requests are the two the person's clicks make (transcribe, read aloud); no prefetch, socket or beacon.
        ->and($page)->not->toMatch('/axios|XMLHttpRequest|WebSocket|EventSource|sendBeacon|prefetch/')
        ->and(preg_match_all('/postForm/', $page))->toBe(3) // import + transcribe + speak
        // A spoken request adds no authority: it goes to Ask HQ as text.
        ->and($page)->toContain('A spoken request adds no authority')
        ->and($page)->toContain('@media (prefers-reduced-motion: no-preference)');
});

it('touches the microphone and speech APIs only inside the click handler of the voice screen (docs/144)', function () {
    $pattern = '/getUserMedia|mediaDevices|MediaRecorder|SpeechRecognition|speechSynthesis|SpeechSynthesis|AudioContext|AudioWorklet|getDisplayMedia/';
    $offenders = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('js'), FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if (! preg_match('/\.(ts|tsx|js|jsx)$/', $file->getFilename()) || str_replace(chr(92), '/', $file->getPathname()) === str_replace(chr(92), '/', resource_path('js/pages/voice.tsx'))) {
            continue;
        }
        if (preg_match($pattern, file_get_contents($file->getPathname()), $m)) {
            $offenders[] = $file->getFilename().' ('.$m[0].')';
        }
    }
    expect($offenders)->toBe([]);

    // In the voice screen: the microphone is requested exactly once, inside `startRecording`, which only the talk button's
    // onClick calls; there is no effect hook at all, so nothing can request it when the page loads.
    $voice = file_get_contents(resource_path('js/pages/voice.tsx'));
    expect(substr_count($voice, 'getUserMedia'))->toBe(1)
        ->and($voice)->not->toContain('useEffect')
        ->and($voice)->toContain('const startRecording = async () => {')
        ->and($voice)->toContain('onClick={() => void startRecording()}')
        ->and(substr_count($voice, 'startRecording'))->toBe(3)
        ->and($voice)->not->toMatch('/speechSynthesis|SpeechRecognition|AudioContext/');
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
