<?php

namespace App\Providers;

use App\Models\Branch;
use App\Models\CompanyProfile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->bind(\App\Services\Network\NetworkDriver::class, fn ($app) => $app->make(config('isp.network_driver')));
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        // Guarded so artisan commands (migrate:fresh included) can boot the
        // app before company_profiles/branches exist yet, on a brand new
        // database.
        if (!Schema::hasTable('company_profiles') || !Schema::hasTable('branches')) {
            return;
        }

        // the whole app (now(), "today", stored times, the scheduler) runs in the company's timezone
        \App\Support\Region::apply();

        $data['company'] = CompanyProfile::first();
        $data['branches'] = Branch::latest()->get();
        view()->share($data);
    }
}
