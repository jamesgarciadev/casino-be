<?php

namespace App\Providers;

use App\Enums\BetStatus;
use App\Enums\MatchState;
use App\Enums\Side;
use App\GraphQL\TolerantContextSerializer;
use App\GraphQL\Types\BackedEnumType;
use App\Models\Activity;
use App\Policies\ActivityPolicy;
use App\Services\PandaScore\PandaScoreClient;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Nuwave\Lighthouse\Schema\TypeRegistry;
use Nuwave\Lighthouse\Support\Contracts\SerializesContext;

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

        // Bound in boot() so it replaces Lighthouse's own binding regardless of provider order.
        $this->app->bind(SerializesContext::class, TolerantContextSerializer::class);

        $this->callAfterResolving(TypeRegistry::class, function (TypeRegistry $types) {
            $types->register(new BackedEnumType(MatchState::class, 'Where a match is in its lifecycle. Bets are accepted until FINISHED.'));
            $types->register(new BackedEnumType(Side::class, 'Team A or team B of a match.'));
            $types->register(new BackedEnumType(BetStatus::class, 'PENDING until the match finishes, then WON or LOST.'));
        });
    }
}
