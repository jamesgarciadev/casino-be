<?php

namespace App\GraphQL\Subscriptions;

use App\Models\Fixture;
use Illuminate\Http\Request;
use Nuwave\Lighthouse\Schema\Types\GraphQLSubscription;
use Nuwave\Lighthouse\Subscriptions\Subscriber;

/**
 * Pushes a match whenever casino-sim creates or updates it. Public data only, so anyone may subscribe.
 */
final class MatchUpdated extends GraphQLSubscription
{
    public function authorize(Subscriber $subscriber, Request $request): bool
    {
        return true;
    }

    /**
     * @param  Fixture  $root
     */
    public function filter(Subscriber $subscriber, mixed $root): bool
    {
        $game = $subscriber->args['game'] ?? null;

        return $game === null || $game === $root->game;
    }
}
