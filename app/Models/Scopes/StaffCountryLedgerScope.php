<?php

namespace App\Models\Scopes;

use App\Services\Staff\StaffCountryScope;
use App\Services\Storefront\StorefrontMarketRows;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * A customer's wallet or loyalty ledger, narrowed to the markets of the signed-in employee (GCC-14c; A2-V1).
 *
 * The rule the panel used to follow was written into `StaffOrderRoutes`' own vocabulary: `unavailable`, "it shows what cannot be told apart
 * by country (a wallet, a total of every order)". That was TRUE, and it stopped being true when the ledgers gained a market of their own
 * (`wallet_transactions.storefront_country`, `loyalty_point_transactions.storefront_country`). Until then a restricted employee was shown a
 * customer's whole money history or nothing at all, and the panel chose nothing; now each row says which market it belongs to, so the
 * screens can show the employee their own markets' movements and no others.
 *
 * ONE class for both ledgers, with the subject as a constructor argument, because the narrowing is identical: unlike the order and refund
 * scopes, which reach their market through an order or an order line, a ledger row records its market itself.
 *
 * The rule of which market a row belongs to is `StorefrontMarketRows` and is not restated here: a row that names no market is the DEFAULT
 * storefront's, so an employee who holds the default market sees the rows written before the ledgers carried a market -- which is every row
 * of the one market this platform has ever had -- and an employee who holds another market does not.
 *
 * It does nothing for a request that is not NARROWED to this subject, which is every request while the staff country scopes are off, every
 * request of a master admin or an unrestricted employee, and every request of the storefront, the apps and the vendor panel. A customer
 * reading their own statement is never narrowed by this; that is `MarketBalanceReader::onlyTheMarketsRows()`' business and answers for the
 * market being served, not for an employee.
 */
final class StaffCountryLedgerScope implements Scope
{
    public function __construct(private readonly string $subject)
    {
    }

    public function apply(Builder $builder, Model $model): void
    {
        $countries = app(StaffCountryScope::class)->narrowedCountries(subject: $this->subject);

        if ($countries !== null) {
            app(StorefrontMarketRows::class)->narrowToCountries($builder, $model->getTable(), $countries);
        }
    }
}
