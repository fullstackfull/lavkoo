<?php

namespace App\Contracts\Repositories;

use App\Contracts\Repositories\MarketAccounts\MarketAccountRepositoryInterface;

/** The loyalty points a customer holds in one market, earned and redeemed at that market's rates (GCC-15, E-02). */
interface CustomerMarketLoyaltyAccountRepositoryInterface extends MarketAccountRepositoryInterface
{
}
