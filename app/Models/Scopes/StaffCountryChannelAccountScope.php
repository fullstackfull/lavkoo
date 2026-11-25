<?php

namespace App\Models\Scopes;

use App\Services\Staff\StaffCountryScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * A row that belongs to a SALES-CHANNEL ACCOUNT -- a channel listing, a channel order, a line of one -- narrowed to the markets of the
 * signed-in employee (GCC-14c, slice 6).
 *
 * The product is the wrong key for these tables, and that is the whole reason this scope exists beside `StaffCountryProductRowScope`.
 * `sales_channel_product_mappings.product_id` is NULLABLE and is null for exactly the rows the screen is FOR: an unlinked listing the channel
 * sent us, waiting for somebody to say which of our products it is. Narrowing by the product would hide every one of them from every
 * restricted employee, which would not be a tighter guard -- it would be the mapping screen silently refusing to do its job. The account is
 * the key the row always has, and an account sells in one market (`ChannelAccountCountry`).
 *
 * An account whose settings name no country belongs to no market, so its rows are hidden from a restricted employee: an employee restricted
 * to nothing, and a platform whose accounts all name no country, see no row rather than every row.
 *
 * `through` is for a row that reaches its account through another table: a line of a channel order names `mapping_id`, and the mapping names
 * the account. One `whereExists` rather than a second scope, so there is one rule about which market a channel row belongs to.
 *
 * It does nothing for a request that is not narrowed, which is every request while the staff country scopes are off, every request of a
 * master admin or an unrestricted employee, and every request of the storefront, the apps and the queue -- nothing binds a country there, so
 * the synchronisation that writes these rows is untouched by this.
 */
final class StaffCountryChannelAccountScope implements Scope
{
    /**
     * @param string $accountIdColumn the column that names the account, on this row or on the row `through` names
     * @param array{0: string, 1: string}|null $through the table this row reaches its account through, and the column of THIS row that names it
     */
    public function __construct(private readonly string $accountIdColumn = 'account_id', private readonly ?array $through = null)
    {
    }

    public function apply(Builder $builder, Model $model): void
    {
        $accountIds = app(StaffCountryScope::class)->channelAccountIdsOfTheRequest();

        if ($accountIds === null) {
            return;
        }

        if ($accountIds === []) {
            $builder->whereRaw('1 = 0');

            return;
        }

        if ($this->through === null) {
            $builder->whereIn($model->qualifyColumn($this->accountIdColumn), $accountIds);

            return;
        }

        [$table, $localKey] = $this->through;
        $localKeyColumn = $model->qualifyColumn($localKey);
        $accountIdColumn = $this->accountIdColumn;

        $builder->whereExists(static fn (QueryBuilder $owner): QueryBuilder => $owner->selectRaw('1')
            ->from($table, 'row_account_owner')
            ->whereColumn('row_account_owner.id', $localKeyColumn)
            ->whereIn('row_account_owner.' . $accountIdColumn, $accountIds));
    }
}
