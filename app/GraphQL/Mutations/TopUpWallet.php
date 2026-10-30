<?php

namespace App\GraphQL\Mutations;

use App\Models\Wallet;
use App\Services\WalletService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final readonly class TopUpWallet
{
    public function __construct(private WalletService $wallets) {}

    public function __invoke(null $_, array $args, GraphQLContext $context): Wallet
    {
        return $this->wallets->topUp($context->user());
    }
}
