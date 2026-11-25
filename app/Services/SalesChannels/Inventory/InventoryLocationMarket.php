<?php

namespace App\Services\SalesChannels\Inventory;

use App\Models\InventoryLocation;
use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Support\ChannelAccountCountry;
use App\Services\Storefront\StorefrontMarketRows;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which market and which selling company a place stock lives in belongs to (G1-05, E11-F19).
 *
 * A warehouse is in a country. Before the market column, the only way to ask which market a stock figure belonged to was through the
 * PRODUCT the balance was of, which answers for the units and never for the shelf -- so the staff country scope stopped at the stock
 * screens: a restricted employee of Kuwait could be shown the Saudi warehouse itself, its code, its name and its figures, because nothing
 * on the row said where it is.
 *
 * The RULE -- an unmarked row is the default storefront's, every other market reads only what names it, the market is compared byte for
 * byte, and none of it applies while storefronts are off or before the migration -- is `StorefrontMarketRows`, shared with the shipping
 * methods, the category costs and the redirects. A second copy of that reasoning is how two tables come to disagree.
 *
 * It is dormant: with storefronts off, or before the migration, this narrows nothing, stamps nothing, and every location list is the list
 * it has always been. That is what the first test of this class asserts.
 */
#[Singleton]
final class InventoryLocationMarket
{
    public const COLUMN = StorefrontMarketRows::COLUMN;

    public const COMPANY_COLUMN = 'legal_entity_id';

    private const TABLE = 'inventory_locations';

    public function __construct(
        private readonly StorefrontMarketRows $rows,
        private readonly ChannelAccountCountry $accountCountry,
    ) {
    }

    /** Whether a location can carry a market at all, and whether anything should read it. */
    public function applies(): bool
    {
        return $this->rows->applies(self::TABLE);
    }

    /**
     * Narrows a query of locations to the storefront given, or to the one the request is being served from.
     *
     * @param Builder<covariant \Illuminate\Database\Eloquent\Model> $query
     */
    public function narrow(Builder $query, ?string $country = null): Builder
    {
        return $this->rows->narrow($query, self::TABLE, $country);
    }

    /**
     * Narrows a listing to the countries a STAFF MEMBER works with: the locations that name one of them, and the unmarked ones too when
     * the employee holds the default market, because those are the default storefront's.
     *
     * @param Builder<covariant \Illuminate\Database\Eloquent\Model> $query
     * @param list<string> $countries
     */
    public function narrowToCountries(Builder $query, array $countries): Builder
    {
        return $this->rows->narrowToCountries($query, self::TABLE, $countries);
    }

    /**
     * Whether every one of these locations exists and is in one of these countries.
     *
     * A location that does not exist is not covered, so which ids are locations is not something a restricted employee can find out by
     * asking.
     *
     * @param list<int|string> $locationIds
     * @param list<string> $countries
     */
    public function allLocationsBelongTo(array $locationIds, array $countries): bool
    {
        return $this->rows->allRowsBelongTo(self::TABLE, $locationIds, $countries);
    }

    /** Whether one location in hand is served in the market given, or in the one the request is being served from. */
    public function isServedIn(?InventoryLocation $location, ?string $country = null): bool
    {
        return $this->rows->rowIsServedIn(self::TABLE, $location?->storefront_country, $country);
    }

    /**
     * The market a NEW location is given, or null while this does not apply.
     *
     * A location made for a marketplace account takes that account's country, which the account's own settings state
     * (`ChannelAccountCountry`): an Amazon account of the Emirati marketplace keeps its fulfilment centres in the Emirates, and filing
     * them as the request's market would put them wherever the operator happened to be looking. An account whose settings name no country
     * -- or two -- gives none, and the location stays unmarked, which reads as the default storefront's.
     */
    public function stampForAccount(SalesChannelAccount $account): ?string
    {
        if (!$this->applies()) {
            return null;
        }

        $country = $this->accountCountry->of($account);

        return $country === null ? null : $this->rows->stampFor(self::TABLE, $country);
    }

    public function forget(): void
    {
        $this->rows->forget();
    }
}
