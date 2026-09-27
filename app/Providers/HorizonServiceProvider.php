<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
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

    // The Horizon dashboard (/horizon, Redis queues only): Superadmin/admin or the "queueMonitor" access.
    protected function gate(): void
    {
        // checkAccess() reads the signed-in user, so it only answers for that user
        Gate::define('viewHorizon', fn ($user = null) => $user instanceof \App\Models\User
            && (in_array($user->role, ['Superadmin', 'admin'], true) || ($user->is(auth()->user()) && checkAccess('queueMonitor'))));
    }
}
