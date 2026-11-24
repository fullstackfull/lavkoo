<?php

namespace App\Observers;

use App\Enums\Wallet\MarketBalanceKind;
use App\Models\LoyaltyPointTransaction;
use App\Models\WalletTransaction;
use App\Services\Wallet\MarketShadowWrite;
use Illuminate\Database\Eloquent\Model;

/**
 * Mirrors each ledger row into the market account it belongs to as it is written (E-07, shadow mode).
 *
 * It runs inside the transaction of the writer that saved the row, which is where the criterion wants it: the one balance and the account
 * move together or not at all. It never throws: a mirror is not allowed to fail a balance write that works, so a failure is written down
 * by the service and the row stands.
 */
class MarketShadowWriteObserver
{
    public function __construct(private readonly MarketShadowWrite $shadow)
    {
    }

    public function created(Model $row): void
    {
        $kind = match (true) {
            $row instanceof WalletTransaction => MarketBalanceKind::Wallet,
            $row instanceof LoyaltyPointTransaction => MarketBalanceKind::Loyalty,
            default => null,
        };

        if ($kind === null) {
            return;
        }

        try {
            $this->shadow->mirror(kind: $kind, row: $row);
        } catch (\Throwable $failure) {
            report($failure);
        }
    }
}
