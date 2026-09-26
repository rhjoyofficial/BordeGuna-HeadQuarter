<?php

use App\Models\AuditLog;
use App\Tools\ToolRegistry;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/*
 | docs/90 A.6 (stream V2): the operator's language preference is the person's, saved on their profile,
 | audited, shared to every page, and reachable by no model. These tests hold the column, the write path
 | (the existing profile route, with `locale` alone), the audit row, the shared prop, the hierarchy the
 | client applies, and that no tool can touch it.
 */

it('adds one nullable locale column to users, and a new person has no preference', function () {
    expect(Schema::hasColumn('users', 'locale'))->toBeTrue()
        ->and($this->hqUser()->refresh()->locale)->toBeNull();
});

it('saves the preference through the profile route with locale alone, and audits it as the person', function () {
    $user = $this->hqUser();

    $this->actingAsHq($user)->patch('/settings/profile', ['locale' => 'bn'])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/settings/profile');

    expect($user->refresh()->locale)->toBe('bn')
        ->and($user->name)->not->toBe('')
        ->and($user->email_verified_at)->not->toBeNull();

    $audit = AuditLog::query()->where('action', 'user.locale_changed')->latest('id')->first();
    expect($audit)->not->toBeNull()
        ->and($audit->actor_type)->toBe('human')
        ->and((int) $audit->actor_id)->toBe($user->id)
        ->and($audit->target_id)->toBe((string) $user->id)
        ->and($audit->before)->toBe(['locale' => null])
        ->and($audit->after)->toBe(['locale' => 'bn']);
});

it('leaves the preference alone when the profile is updated without it, and writes no audit row then', function () {
    $user = $this->hqUser(attributes: ['locale' => 'bn']);

    $this->actingAsHq($user)->patch('/settings/profile', ['name' => 'Renamed', 'email' => $user->email])
        ->assertSessionHasNoErrors();

    expect($user->refresh()->locale)->toBe('bn')
        ->and($user->name)->toBe('Renamed')
        ->and(AuditLog::query()->where('action', 'user.locale_changed')->count())->toBe(0);

    // Saving the same language again is not a change and is not audited either.
    $this->actingAsHq($user)->patch('/settings/profile', ['locale' => 'bn'])->assertSessionHasNoErrors();
    expect(AuditLog::query()->where('action', 'user.locale_changed')->count())->toBe(0);
});

it('refuses a language HQ does not have', function () {
    $user = $this->hqUser();

    $this->actingAsHq($user)->from('/settings/profile')->patch('/settings/profile', ['locale' => 'fr'])
        ->assertSessionHasErrors('locale');

    expect($user->refresh()->locale)->toBeNull();
});

it('shares the saved preference to every page as auth.user.locale, null when none is saved', function () {
    $this->actingAsHq($this->hqUser())->get('/dashboard')
        ->assertInertia(fn ($page) => $page->where('auth.user.locale', null));

    $this->actingAsHq($this->hqUser(attributes: ['locale' => 'bn']))->get('/dashboard')
        ->assertInertia(fn ($page) => $page->where('auth.user.locale', 'bn'));
});

it('applies the hierarchy on the client: the saved preference, then the browser language, then English', function () {
    $i18n = file_get_contents(resource_path('js/lib/i18n.ts'));
    $body = substr($i18n, strpos($i18n, 'export function resolveLocale('));
    $body = substr($body, 0, strpos($body, "\n}\n"));

    $saved = strpos($body, 'isLocale(saved)');
    $browser = strpos($body, "startsWith('bn')");
    $default = strrpos($body, "return 'en'");

    expect($saved)->not->toBeFalse()->and($browser)->not->toBeFalse()->and($default)->not->toBeFalse()
        ->and($saved < $browser && $browser < $default)->toBeTrue()
        // Nothing durable lives in the browser any more: no local storage for the language.
        ->and($i18n)->not->toContain('localStorage')
        // The app resolves the language once at boot from the first page's shared props.
        ->and(file_get_contents(resource_path('js/app.tsx')))->toContain('initializeLocale(auth?.user?.locale)');
});

it('lets the switch save the preference through the profile route and nothing else, and adds no locale route', function () {
    $toggle = file_get_contents(resource_path('js/components/language-toggle.tsx'));

    expect($toggle)->toContain("router.patch(route('profile.update'), { locale: l }")
        ->and($toggle)->not->toContain('fetch(')->not->toContain('axios')
        ->and(collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_contains($r->uri(), 'locale'))->all())->toBe([]);
});

it('gives no tool a way to read or change the preference', function () {
    $registry = app(ToolRegistry::class);
    foreach ($registry->keys() as $key) {
        $schema = json_encode($registry->get($key)->inputSchema());
        expect($schema)->not->toContain('locale', "[{$key}] names a locale argument");
    }

    // No tool, agent or AI class touches the column: only the profile settings request writes it.
    $offenders = [];
    foreach (['app/Tools', 'app/Agents', 'app/Ai', 'app/Gateway'] as $dir) {
        foreach (File::allFiles(base_path($dir)) as $file) {
            if (str_contains($file->getContents(), '->locale') || str_contains($file->getContents(), "'locale'")) {
                $offenders[] = $file->getRelativePathname();
            }
        }
    }
    expect($offenders)->toBe([]);
});
