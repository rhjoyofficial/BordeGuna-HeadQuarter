<?php

namespace App\Providers;

use App\Listeners\RecordAuthenticationEvents;
use App\Scheduling\Tools\ToolRegistry;
use App\Support\Credentials\OAuthManager;
use App\Support\Features\Features;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ToolRegistry::class, fn () => new ToolRegistry(config('hq.tools', [])));
        $this->app->singleton(Features::class);
        $this->app->singleton(OAuthManager::class);
    }

    public function boot(): void
    {
        Date::use(CarbonImmutable::class);
        // Strict on N+1 and silently-discarded attributes; NOT on missing attributes, because a
        // freshly created model legitimately lacks database defaults until refreshed.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        Event::listen(Login::class, [RecordAuthenticationEvents::class, 'login']);
        Event::listen(Logout::class, [RecordAuthenticationEvents::class, 'logout']);
        Event::listen(Failed::class, [RecordAuthenticationEvents::class, 'failed']);

        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }
    }
}
