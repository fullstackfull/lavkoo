<?php

namespace App\Repositories;

use App\Contracts\Repositories\CustomerMarketLoyaltyAccountRepositoryInterface;
use App\Models\CustomerMarketLoyaltyAccount;
use App\Repositories\MarketAccounts\MarketAccountRepository;
use Illuminate\Database\Eloquent\Model;

class CustomerMarketLoyaltyAccountRepository extends MarketAccountRepository implements CustomerMarketLoyaltyAccountRepositoryInterface
{
    public function __construct(private readonly CustomerMarketLoyaltyAccount $loyaltyAccount)
    {
    }

    protected function account(): Model
    {
        return $this->loyaltyAccount;
    }

    protected function amountColumn(): string
    {
        return 'points';
    }
}
