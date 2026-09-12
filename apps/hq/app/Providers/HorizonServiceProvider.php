<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();
    }

    /**
     * Register the Horizon gate.
     *
     * Who may open the queue dashboard outside `local`. The starter kit ships this as an empty
     * list of e-mail addresses, which meant that the sidebar offered "Queues (Horizon)" to anyone
     * holding `hq.horizon.view` and production answered every click with a bare 403 (found on the
     * 12 Sep 2026 render check). The same permission that shows the link now opens the page, so
     * the two can never disagree again.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', fn ($user = null) => (bool) $user?->can('hq.horizon.view'));
    }
}
