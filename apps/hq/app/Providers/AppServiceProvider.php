<?php

namespace App\Providers;

use App\Ads\Contribution\CostPriceSource;
use App\Ads\Contribution\DeliveredSalesSource;
use App\Ads\Contribution\FactsOrdersDeliveredSales;
use App\Ads\Contribution\UnavailableCostPrices;
use App\Ai\CircuitBreaker;
use App\Ai\ModelRouter;
use App\Ai\Providers\ProviderRegistry;
use App\Auth\ActiveUserProvider;
use App\Briefing\BriefingNarrator;
use App\Briefing\NoNarrator;
use App\Commerce\Support\CommerceRegistry;
use App\Commerce\Support\ConnectorCallLogger;
use App\Evaluation\DeterministicGrader;
use App\Evaluation\EvaluationRunner;
use App\Evaluation\RubricGrader;
use App\Gateway\Credentials\CredentialVault;
use App\Listeners\RecordAuthenticationEvents;
use App\Media\LocalStorageDriver;
use App\Media\MediaAssetService;
use App\Platform\CapabilityResolver;
use App\Platform\Contracts\StorageDriver;
use App\Platform\PlatformRegistry;
use App\Support\Audit\Auditor;
use App\Support\Credentials\OAuthManager;
use App\Support\Features\Features;
use App\Tools\ToolRegistry;
use App\Voice\SpeechProviders;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Facades\Auth;
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

        // ── Phase 2.5 shared foundation ──────────────────────────────────────────────────────
        //
        // ⚠ The platform registry ships EMPTY, and that is the correct state: no provider is
        // implemented yet. `CapabilityResolver` fails closed on an unknown platform, so "we have no
        // code for this" and "this account may do anything" can never be the same answer.
        $this->app->singleton(PlatformRegistry::class, fn () => new PlatformRegistry);
        $this->app->singleton(CapabilityResolver::class);

        // Phase 7 (docs/88 A point 6): the speech providers ship EMPTY for the same reason. A voice note
        // is kept without a transcript until a person registers a provider at gate P7-G2.
        $this->app->singleton(SpeechProviders::class, fn () => new SpeechProviders);

        // Phase 5 read side (docs/88 A point 4): the contribution report's two sources. Delivered orders
        // from the shops' own facts; cost prices from NOWHERE yet (the ERP holds them, the HQ contract
        // does not carry them), so every contribution is blank with its reason rather than guessed.
        // ⛔ Nothing here registers the Meta ads provider: the registry above stays empty.
        $this->app->bind(DeliveredSalesSource::class, FactsOrdersDeliveredSales::class);
        $this->app->bind(CostPriceSource::class, UnavailableCostPrices::class);

        // Local disk is the only driver today. Everything above it names a driver and an opaque
        // key, so swapping this binding is the whole migration to object storage.
        $this->app->bind(StorageDriver::class, fn () => new LocalStorageDriver);
        $this->app->singleton(MediaAssetService::class);

        // Deterministic graders decide tool calls and refusals; the rubric grader exists and
        // refuses while ai.runtime is off, which in this milestone means always.
        // docs/80 C.1: HQ ships with no briefing narrator. The composer resolves this binding only when
        // `briefing.narration` and `ai.runtime` are both on, and what it then gets can reach no model.
        $this->app->bind(BriefingNarrator::class, NoNarrator::class);

        $this->app->singleton(EvaluationRunner::class, fn ($app) => (new EvaluationRunner($app->make(Auditor::class)))
            ->withGrader(new DeterministicGrader)
            ->withGrader($app->make(RubricGrader::class)));
    }

    public function boot(): void
    {
        Date::use(CarbonImmutable::class);
        // Strict on N+1 and silently-discarded attributes; NOT on missing attributes, because a
        // freshly created model legitimately lacks database defaults until refreshed.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        // docs/81 P0-1: a disabled account is nobody to the guard, the remember cookie and the password broker.
        Auth::provider('active-eloquent', fn ($app, array $config) => new ActiveUserProvider($app['hash'], $config['model']));

        Event::listen(Login::class, [RecordAuthenticationEvents::class, 'login']);
        Event::listen(Logout::class, [RecordAuthenticationEvents::class, 'logout']);
        Event::listen(Failed::class, [RecordAuthenticationEvents::class, 'failed']);

        // docs/78 E: the external system's answer is the last link of a trace. The connector logger
        // notes the status of each response received while it is recording a call.
        Event::listen(ResponseReceived::class, fn (ResponseReceived $event) => ConnectorCallLogger::observe($event->response->status()));

        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }
    }
}
