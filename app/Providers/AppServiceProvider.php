<?php

namespace App\Providers;

use App\Models\Activity;
use App\Policies\ActivityPolicy;
use App\Services\PandaScore\PandaScoreClient;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PandaScoreClient::class, fn () => new PandaScoreClient(
            config('services.pandascore.token'),
            config('services.pandascore.base_url'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Activity::class, ActivityPolicy::class);
    }
}
