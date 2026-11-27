<?php

namespace App\Services\Storefront;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which products a storefront may show (GCC-02/03). Every product belongs to one country, so its price, stock and availability are that
 * country's own: nothing is shared with, or converted from, another country's copy of the product.
 *
 * It narrows only a storefront request while storefronts are on. A storefront request is one the middleware bound to a storefront, or one
 * it has not reached yet (the providers boot before any middleware) that would be bound to the storefront it asks for. The admin and
 * vendor panels, the seller APIs, queues and console commands work across every country, and while storefronts are off nothing is
 * narrowed and nothing about the resolver is read. A database whose migration has not run serves the default storefront as before and
 * shows no product to any other.
 */
final class StorefrontCatalog
{
    public const COLUMN = 'storefront_country';

    /** The company that sells a product, once products carry one (D1-03). No migration adds it yet, so the company half of a selling scope is dormant. */
    public const ENTITY_COLUMN = 'legal_entity_id';

    private const TABLE = 'products';

    /**
     * The paths that work across every country on purpose, and so are never narrowed to one (A1-11).
     *
     * Three kinds, and the second and the third were missing until a request to them was looked at:
     *
     *  - **The panels and the seller APIs.** An operator and a seller work on every country they are allowed to, in one screen.
     *  - **Webhooks and payment callbacks.** A provider or a gateway calls back about work of whichever market the work belongs to, and it
     *    names the record rather than the market: the fulfilment webhook names a provider and a shipment, the MyFatoorah and payment-account
     *    webhooks name a payment. Narrowed to the default market, such a call would read the catalogue of Saudi Arabia while settling a
     *    Kuwaiti shipment -- it could fail to find the very rows it was called about. The market of the work comes from the record
     *    ({@see \App\Services\Storefront\PaymentStorefront}, {@see \App\Services\Storefront\OrderNotificationContext}), never from the
     *    address the provider happened to call.
     *  - **The platform admin API** (GCC-20), which is the owner's view of every market at once.
     *
     * Everything else under `api/` is storefront traffic and is narrowed to the market it asks for, which is what `api/v1` is.
     */
    private const CROSS_COUNTRY_PATHS = [
        'admin', 'admin/*', 'vendor', 'vendor/*', 'seller', 'seller/*',
        'api/v2', 'api/v2/*', 'api/v3', 'api/v3/*',
        'api/fulfillment-webhooks', 'api/fulfillment-webhooks/*',
        'api/myfatoorah', 'api/myfatoorah/*',
        'api/payments/account/*',
        'api/delivery-syria', 'api/delivery-syria/*',
        'api/platform-admin', 'api/platform-admin/*',
    ];

    /** How long a "no" is remembered, so a worker started before the migration notices it without a restart. A "yes" lasts for the process. */
    private const NEGATIVE_ANSWER_SECONDS = 30;

    /** How many cart groups one country lookup asks for: every engine has a cap on the placeholders of a single statement. */
    private const CART_GROUPS_PER_QUERY = 500;

    /** @var array{value: bool, at: int}|null */
    private static ?array $columnAnswer = null;

    public static function restrict(Builder $query): Builder
    {
        return self::narrowTo(query: $query, column: $query->qualifyColumn(self::COLUMN));
    }

    /** For a plain query that joins the products table, as the checkout guard does: it reads the cart without going through the Product model. */
    public static function restrictJoinedProducts(QueryBuilder $query): QueryBuilder
    {
        return self::narrowTo(query: $query, column: self::TABLE . '.' . self::COLUMN);
    }

    /** For a plain query over a table that holds product ids, such as the search index: it is narrowed before any cap on the number of rows. */
    public static function restrictProductIds(QueryBuilder $query, string $column): QueryBuilder
    {
        if (!self::narrows()) {
            return $query;
        }

        return $query->whereIn($column, fn (QueryBuilder $products) => self::narrowTo(query: $products->select('id')->from(self::TABLE), column: self::COLUMN));
    }

    /**
     * A cart only holds, shows and changes the rows of the storefront it is used from, so a customer who shops two countries has two separate
     * carts. A row whose product no longer exists belongs to no country, so it stays reachable: it can be removed, and a checkout cleans it up.
     */
    public static function restrictCartRows(Builder $cartRows): Builder
    {
        if (!self::narrows()) {
            return $cartRows;
        }

        return $cartRows->where(fn (Builder $rows) => $rows
            ->whereHas('allProducts', fn (Builder $products) => self::restrict(query: $products))
            ->orWhereDoesntHave('allProducts'));
    }

    /**
     * Runs an operation that is cross-storefront by nature, such as moving a guest's cart into an account at login, once in every
     * storefront: the rows of each country go to the cart of that country, and none is left behind because the request was served from
     * another one. Storefronts that are switched off, and a request that is not a storefront request, run it once as it always ran.
     */
    public static function inEveryStorefront(Closure $operation): void
    {
        if (!self::narrows()) {
            $operation();

            return;
        }

        $resolver = StorefrontResolver::fromConfig();
        foreach ($resolver->configuredCountries() as $country) {
            $resolver->contextOfRecordedCountry($country)?->serve(callback: $operation);
        }
    }

    /** Whether the storefront of the request may sell this product: the cart asks before it takes a product, whatever route reached it. */
    public static function offers(?Model $product): bool
    {
        $country = self::country();
        if ($country === null || $product === null) {
            return true;
        }

        if (self::columnReady()) {
            return $product->getAttribute(self::COLUMN) === $country;
        }

        return self::isDefaultCountry($country);
    }

    /**
     * Who sells this product: the country it belongs to, and the company that sells it once products carry one. The two halves are returned
     * apart, keyed by their column, because they are compared apart — a country against a country and a company against a company. Two
     * products may be invoiced on one order while no half they both name disagrees, whichever request is asking and whether or not
     * storefronts are switched on. An order asks this of its rows one last time before it is written (OneSellingCompanyPerOrder).
     *
     * Returning the halves joined would make a row that names one of them disagree with a row that names neither, and the two are not
     * comparable: a row says nothing about a half it leaves null. That is the state the company backfill is in from its first row to its
     * last — one product carrying a company, the next not yet reached — so joining them would refuse every lawful order for the length of
     * the migration.
     *
     * Both halves are read the same way, off the row the caller already loaded, so the company half comes on by itself the day a migration
     * adds the column and costs nothing until then — no schema is read and no query is made, which is what keeps it off the Saudi checkout's
     * query count. A product loaded without these columns therefore says nothing about who sells it, which is also the answer before the
     * country migration has run: the database the platform has today.
     *
     * The row is whatever the caller already holds: a Product the checkout or the point of sale loaded, or the plain products row the
     * channel materializer reads with the query builder. Both are asked for the column by name and neither is asked for a column it does
     * not carry, so a row loaded without these columns says nothing rather than failing.
     *
     * @return array<string, string> the halves this row names, keyed by column, read as stored; empty when it names none
     */
    public static function sellingScopeOf(?object $product): array
    {
        if ($product === null) {
            return [];
        }

        $scope = [];
        foreach ([self::COLUMN, self::ENTITY_COLUMN] as $column) {
            $value = $product->{$column} ?? null;

            if ($value !== null) {
                // As a string whichever engine and whichever reader produced the row: a company id arrives as an int from one driver and
                // as a string from another, and two spellings of one id are one company.
                $scope[$column] = (string) $value;
            }
        }

        return $scope;
    }

    /**
     * The key a cache entry built from the catalog is written under. A storefront request writes under its storefront's key, which is the
     * plain key for the Saudi storefront. A request that works across every country (the admin and vendor panels, the seller APIs, a queue,
     * a console command) gets a key of its own, so it never fills the Saudi storefront's entry with other countries' products. While
     * storefronts are off every key is the plain key and nothing about the resolver is read.
     */
    public static function cacheKey(string $key): string
    {
        if (!config('gcc.storefronts.enabled')) {
            return $key;
        }

        return self::context()?->scopedCacheKey($key) ?? self::unboundKey($key);
    }

    /**
     * Every key the entry can be under: the plain key, and while storefronts are on, each storefront's and the unbound one.
     *
     * **The gate is deliberate, and it is NOT the same decision as {@see StorefrontContext::keysForEveryStorefront}, which has none.** A
     * theme publish reaches every market's copy whether storefronts are on or off; a catalogue change reaches only the plain key while they
     * are off, so that a storefront configuration which is missing or mistyped cannot reach the caches of a platform that is not using
     * storefronts (`StorefrontProductCachesTest::test_while_storefronts_are_off_a_missing_or_broken_storefront_configuration_does_not_reach_the_caches`).
     *
     * What that leaves is a known, named trade-off rather than an oversight: the copies written while the flag was on are not forgotten by
     * a catalogue change made while it is off, so they would be served again after the next flip. Step 6 of
     * `docs/gcc/STOREFRONT_FLAG_RUNBOOK.md` is the answer -- `php artisan cache:clear` on every flip, in either direction. Both halves of
     * the asymmetry are pinned by `StorefrontCacheKeyOwnershipTest`, so removing this gate fails a test that explains why it is here.
     *
     * @return list<string>
     */
    public static function cacheKeysForEveryStorefront(string $key): array
    {
        if (!config('gcc.storefronts.enabled')) {
            return [$key];
        }

        return [...StorefrontContext::keysForEveryStorefront($key), self::unboundKey($key)];
    }

    /**
     * The country of a cart group, for a link that must open the storefront the cart was made in: a link opens one storefront, so the first
     * row of the group names it, as a lookup of one group always did, and a first row that belongs to no country still names none. Null
     * while storefronts are off.
     */
    public static function countryOfCartGroup(string $cartGroupId): ?string
    {
        $firstRow = self::countriesOfCartGroups(cartGroupIds: [$cartGroupId])[$cartGroupId][0] ?? '';

        return $firstRow === '' ? null : $firstRow;
    }

    /**
     * The countries the products of each cart group belong to, in one query for all of them and in the order of the group's rows. A legacy
     * cart group can hold the rows of two countries (CartManager::cartGroupsSharedWithOtherStorefronts), so a caller that has to judge the
     * storefronts of many carts, such as the marketing stop, sees every country a group names and asks once instead of once per cart. A row
     * whose product belongs to no country is the empty string, which is what such a first row always answered. Empty while storefronts are off.
     *
     * @param list<string> $cartGroupIds
     * @return array<string, list<string>> cart group id => the countries its rows name, the first row's first
     */
    public static function countriesOfCartGroups(array $cartGroupIds): array
    {
        $ids = array_values(array_unique(array_map('strval', $cartGroupIds)));

        if ($ids === [] || !config('gcc.storefronts.enabled') || !self::columnReady()) {
            return [];
        }

        $countries = [];

        foreach (array_chunk($ids, self::CART_GROUPS_PER_QUERY) as $chunk) {
            $rows = DB::table('carts')
                ->join(self::TABLE, self::TABLE . '.id', '=', 'carts.product_id')
                ->whereIn('carts.cart_group_id', $chunk)
                ->orderBy('carts.id')
                ->get(['carts.cart_group_id', self::TABLE . '.' . self::COLUMN]);

            foreach ($rows as $row) {
                $cartGroupId = (string) $row->cart_group_id;
                $country = (string) ($row->{self::COLUMN} ?? '');
                $countries[$cartGroupId] ??= [];

                if (!in_array($country, $countries[$cartGroupId], true)) {
                    $countries[$cartGroupId][] = $country;
                }
            }
        }

        return $countries;
    }

    /** Whether the admin tools see a product's country: storefronts are on and the products migration has run. */
    public static function tracksProductCountry(): bool
    {
        return (bool) config('gcc.storefronts.enabled') && self::columnReady();
    }

    public static function flush(): void
    {
        self::$columnAnswer = null;
    }

    /** The country this request is narrowed to, or null when nothing is narrowed: storefronts off, a panel, a seller API, a queue or a console command. */
    public static function country(): ?string
    {
        return self::context()?->country;
    }

    /** Whether a query over products is narrowed at all for this request: not while storefronts are off, for a panel, a seller API or a queue, nor for the default storefront before the migration has run. */
    private static function narrows(): bool
    {
        $country = self::country();

        return $country !== null && (self::columnReady() || !self::isDefaultCountry($country));
    }

    private static function narrowTo(Builder|QueryBuilder $query, string $column): Builder|QueryBuilder
    {
        $country = self::country();
        if ($country === null) {
            return $query;
        }

        if (self::columnReady()) {
            return $query->where($column, $country);
        }

        return self::isDefaultCountry($country) ? $query : $query->whereRaw('1 = 0');
    }

    private static function context(): ?StorefrontContext
    {
        if (!config('gcc.storefronts.enabled')) {
            return self::contextOfTheMarketLeftBehind();
        }

        return app()->bound(StorefrontContext::class) ? StorefrontContext::current() : self::contextOfUnboundRequest();
    }

    /**
     * What a storefront request is served once the storefront switch has been turned OFF AGAIN, with another market's products already in
     * the table (M1-18): the DEFAULT market's catalogue, and not every market's.
     *
     * Off used to mean "narrow nothing", which is the right answer for the platform of today -- one market, one country's products, no
     * column to narrow by. It is the wrong answer AFTER the switch has been used: a second market's rows are in `products`, nothing is
     * narrowed any more, and a visitor of the one remaining storefront is shown them. So turning the switch off was not a way of stopping
     * a market; it was a way of merging six into one, and the runbook had to say "deactivate the country instead, never turn the flag off".
     * A switch somebody is told not to use is not a switch.
     *
     * Off now means ONE MARKET, which is what a kill switch for this feature means: the catalogue falls back to the market the platform
     * started as, and the rows of the markets that were added stay out of it until the switch is turned on again. Nothing is deleted and
     * nothing is hidden from anybody who is meant to see it -- the panels, the seller APIs, the webhooks, the platform admin API and the
     * queues are cross-country by design and are answered exactly as before, by the same two rules this class already applies to them.
     *
     * It ships off (`gcc.storefronts.off_is_one_market`), and off is today's behaviour in every detail. It is also inert until there is
     * something to narrow: before the migration there is no column, so there is no second market's row to keep out and no query changes.
     *
     * The country is the default market's, whichever market the request asked for, so this branch can only ever narrow and never widen.
     */
    private static function contextOfTheMarketLeftBehind(): ?StorefrontContext
    {
        if (!config('gcc.storefronts.off_is_one_market') || !self::columnReady() || !self::isAStorefrontRequest()) {
            return null;
        }

        $resolver = StorefrontResolver::fromConfig();

        return $resolver->contextOfRecordedCountry($resolver->defaultCountry());
    }

    private static function contextOfUnboundRequest(): ?StorefrontContext
    {
        if (!self::isAStorefrontRequest()) {
            return null;
        }

        return StorefrontResolver::fromConfig()->contextForRequest(request: request());
    }

    /**
     * Whether this request is storefront traffic at all. One copy of the rule, because it decides both what a request with no bound
     * context is narrowed to and what the market left behind narrows ({@see CROSS_COUNTRY_PATHS}): two copies would let one of the two
     * answers drift, and the drift would be a panel narrowed to one market or a storefront not narrowed at all.
     */
    private static function isAStorefrontRequest(): bool
    {
        return !app()->runningInConsole() && !request()->is(...self::CROSS_COUNTRY_PATHS);
    }

    private static function unboundKey(string $key): string
    {
        return "storefront:*:{$key}";
    }

    private static function isDefaultCountry(string $country): bool
    {
        return $country === StorefrontResolver::fromConfig()->defaultCountry();
    }

    public static function columnReady(): bool
    {
        $answer = self::$columnAnswer;
        if ($answer !== null && ($answer['value'] || time() - $answer['at'] < self::NEGATIVE_ANSWER_SECONDS)) {
            return $answer['value'];
        }

        $ready = Schema::hasColumn(self::TABLE, self::COLUMN);
        self::$columnAnswer = ['value' => $ready, 'at' => time()];

        return $ready;
    }
}
