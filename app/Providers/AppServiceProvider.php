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
        // MikroTik (the default) means "per router": each router is driven over its API or by RADIUS
        $this->app->bind(\App\Services\Network\NetworkDriver::class, function ($app) {
            $class = config('isp.network_driver');
            return $app->make($class === \App\Services\Network\MikroTikDriver::class ? \App\Services\Network\RouterDriver::class : $class);
        });
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        // every new or changed password: at least 8 characters with letters and numbers
        \Illuminate\Validation\Rules\Password::defaults(fn () => \Illuminate\Validation\Rules\Password::min(8)->letters()->numbers());

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
