<?php

namespace App\GraphQL\Directives;

use Laravel\Sanctum\PersonalAccessToken;
use Nuwave\Lighthouse\Exceptions\AuthorizationException;
use Nuwave\Lighthouse\Execution\ResolveInfo;
use Nuwave\Lighthouse\Schema\Directives\BaseDirective;
use Nuwave\Lighthouse\Schema\Values\FieldValue;
use Nuwave\Lighthouse\Support\Contracts\FieldMiddleware;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class TokenCanDirective extends BaseDirective implements FieldMiddleware
{
    public static function definition(): string
    {
        return /** @lang GraphQL */ <<<'GRAPHQL'
"""
Require the current Sanctum personal access token to carry the given ability.
Place it after @guard so the user is resolved first.
"""
directive @tokenCan(
  "The token ability, e.g. `admin` or `customer`."
  ability: String!
) repeatable on FIELD_DEFINITION
GRAPHQL;
    }

    public function handleField(FieldValue $fieldValue): void
    {
        $ability = $this->directiveArgValue('ability');

        $fieldValue->wrapResolver(fn (callable $resolver): \Closure => function (mixed $root, array $args, GraphQLContext $context, ResolveInfo $resolveInfo) use ($resolver, $ability) {
            $user = $context->user();
            $token = $user?->currentAccessToken();

            // Transient (session) tokens always pass tokenCan(), so only accept real personal access tokens.
            if (! $token instanceof PersonalAccessToken || ! $token->can($ability)) {
                throw new AuthorizationException("This action requires a token with the [{$ability}] ability.");
            }

            return $resolver($root, $args, $context, $resolveInfo);
        });
    }
}
