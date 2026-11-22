<?php

namespace App\Repositories;

use App\Contracts\Repositories\CustomerMarketWalletAccountRepositoryInterface;
use App\Enums\Wallet\MarketBalanceKind;
use App\Models\CustomerMarketWalletAccount;
use App\Repositories\MarketAccounts\MarketAccountRepository;
use Illuminate\Database\Eloquent\Model;

class CustomerMarketWalletAccountRepository extends MarketAccountRepository implements CustomerMarketWalletAccountRepositoryInterface
{
    public function __construct(private readonly CustomerMarketWalletAccount $walletAccount)
    {
    }

    protected function account(): Model
    {
        return $this->walletAccount;
    }

    protected function kind(): MarketBalanceKind
    {
        return MarketBalanceKind::Wallet;
    }
}
