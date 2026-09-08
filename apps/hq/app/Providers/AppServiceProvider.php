<?php

namespace App\Providers;

use App\Ai\CircuitBreaker;
use App\Ai\ModelRouter;
use App\Ai\Providers\ProviderRegistry;
use App\Commerce\Support\CommerceRegistry;
use App\Gateway\Credentials\CredentialVault;
use App\Listeners\RecordAuthenticationEvents;
use App\Support\Credentials\OAuthManager;
use App\Support\Features\Features;
use App\Tools\ToolRegistry;
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
        // The vault holds the credentials open for the CURRENT call. Every consumer must share
        // one instance or the scope the gateway opens is invisible to the connector that needs it.
        $this->app->singleton(CredentialVault::class);
        $this->app->singleton(Features::class);
        $this->app->singleton(OAuthManager::class);
        $this->app->singleton(CommerceRegistry::class);

        // M4. The provider registry mirrors the tool registry: config names driver CLASSES, the
        // container resolves them so each driver gets its dependencies, and each names itself.
        $this->app->singleton(ProviderRegistry::class, fn () => new ProviderRegistry(config('hq.ai.providers', [])));
        // The breaker's state must be shared, and the router must be the single point of selection
        // or cost accounting becomes a sample rather than a ledger (ADR-019).
        $this->app->singleton(CircuitBreaker::class);
        $this->app->singleton(ModelRouter::class);
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
