<?php

namespace App\Models\Scopes;

use App\Services\Staff\StaffCountryScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * A ledger payout, narrowed to the markets of the signed-in employee (GCC-14c; G-14, second criterion).
 *
 * A payout names a SELLER and no product and no order, so neither `StaffCountryProductRowScope` nor `StaffCountryOrderScope` can reach it:
 * the market of a payout is the market of the shop whose money it moves, which is `SellerMarketScope::soleMarketOfShop()`'s rule and is read
 * through `StaffCountryScope::sellerIdsOfPayoutsOfTheRequest()` rather than restated here.
 *
 * It narrows only a request the payouts SCREEN narrowed (`SUBJECT_PAYOUTS`), and that is deliberate rather than cautious: the five actions
 * of that screen move money, so each of them has to run on the real row -- `PayoutService` releases a reservation, writes a ledger entry and
 * marks a transfer failed -- and whether an employee may act at all is the guard's answer and not the model's. A payout outside the
 * employee's markets is refused by `StaffMarketplaceRoutes`; it is not quietly turned into a row that cannot be found.
 *
 * So it does nothing for the seller API that requests and cancels a payout, nothing for the queue, and nothing for a master admin or an
 * unrestricted employee -- no country is bound in any of those.
 */
final class StaffCountryPayoutScope implements Scope
{
    public function __construct(private readonly string $sellerIdColumn = 'seller_id')
    {
    }

    public function apply(Builder $builder, Model $model): void
    {
        $sellerIds = app(StaffCountryScope::class)->sellerIdsOfPayoutsOfTheRequest();

        if ($sellerIds === null) {
            return;
        }

        $sellerIds === []
            ? $builder->whereRaw('1 = 0')
            : $builder->whereIn($model->qualifyColumn($this->sellerIdColumn), $sellerIds);
    }
}
