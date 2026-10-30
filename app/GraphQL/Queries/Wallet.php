<?php

namespace App\GraphQL\Queries;

use App\Models\Wallet as WalletModel;
use App\Services\WalletService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final readonly class Wallet
{
    public function __construct(private WalletService $wallets) {}

    public function __invoke(null $_, array $args, GraphQLContext $context): WalletModel
    {
        return $this->wallets->for($context->user());
    }
}
