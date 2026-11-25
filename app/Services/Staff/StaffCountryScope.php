<?php

namespace App\Services\Staff;

use App\Models\Admin;
use App\Services\SalesChannels\Inventory\InventoryLocationMarket;
use App\Services\Storefront\CouponCountry;
use App\Services\Storefront\StorefrontCatalog;
use App\Services\Storefront\StorefrontCategoryShippingCosts;
use App\Services\Storefront\StorefrontMarketRows;
use App\Services\Storefront\StorefrontShippingMethods;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The countries the signed-in employee works with (GCC-14c, slices 1 to 3: the product screens, the order, POS and refund screens, and the shared layout, the dashboard
 * and the reports, of the admin panel).
 *
 * It is the only place that knows the switch (`gcc.staff.country_scopes`), the table of countries and how they become an employee's scope. The switch ships off and
 * acts as off until the table and `products.storefront_country` exist: nothing is read then, and every answer is the one the panel has always given.
 *
 * An employee with no row is unrestricted, so turning the switch on changes nothing until a country is assigned. With rows the employee is restricted to the
 * countries that are still configured; a stored value that names none grants nothing, and an employee whose values all grant nothing is restricted to nothing,
 * never widened. The master admin is never restricted.
 *
 * What a request is narrowed to lives on that request alone (never in the container, a static or the cache), so the next request of the same process, a queue
 * worker and a console command start unbound.
 */
final class StaffCountryScope
{
    public const SWITCH = 'gcc.staff.country_scopes';

    public const TABLE = 'admin_country_scopes';

    /** The country of a product no path gave one to: the default of the column. A bulk import without a country and a new product while countries are not tracked take it. */
    public const TABLE_DEFAULT_COUNTRY = 'SA';

    private const MASTER_ADMIN_ROLE_ID = 1;

    public const GRANTED_BY = 'granted_by';

    public const EXPIRES_AT = 'expires_at';

    /** Whether a scope row carries its grant trail. Asked once per process: every bind of a request asks it. */
    private static ?bool $rowsRecordTheirGrant = null;

    /** The orders a request lists: their lists, counters and exports. */
    public const SUBJECT_ORDERS = 'orders';

    /** The refund requests a request lists. */
    public const SUBJECT_REFUNDS = 'refunds';

    /** The order lines a request reads: their lists, sums and counts, each through the order it belongs to. */
    public const SUBJECT_ORDER_LINES = 'order_lines';

    /** The money records of orders a request reads (`order_transactions`), each through the order it belongs to. */
    public const SUBJECT_ORDER_TRANSACTIONS = 'order_transactions';

    /** The money records of refunds a request reads (`refund_transactions`), each through the order line it is about. */
    public const SUBJECT_REFUND_TRANSACTIONS = 'refund_transactions';

    /**
     * The wallet ledger rows a request reads (`wallet_transactions`), each by the market the ROW records -- not through an order, because a
     * movement may have none: a top-up by an administrator, a conversion of points, a bonus.
     */
    public const SUBJECT_WALLET_TRANSACTIONS = 'wallet_transactions';

    /** The loyalty ledger rows a request reads (`loyalty_point_transactions`), by the market the row records, for the same reason. */
    public const SUBJECT_LOYALTY_TRANSACTIONS = 'loyalty_point_transactions';

    /** The returns a request lists (`return_shipments`), each through the order it is a return of (slice 5). */
    public const SUBJECT_RETURNS = 'returns';

    /** The fulfilments a request lists (`order_fulfillments`), each through the order it ships (slice 5). */
    public const SUBJECT_FULFILLMENTS = 'fulfillments';

    private const REQUEST_ATTRIBUTE = 'staff_country_scope';

    private const SUBJECTS_ATTRIBUTE = 'staff_country_scope_subjects';

    /** Whether the switch is on. Nothing else is asked while it is off. */
    public function isOn(): bool
    {
        return filter_var(config(self::SWITCH, false), FILTER_VALIDATE_BOOLEAN);
    }

    /** Whether scopes are enforced: the switch is on and both the table of countries and the column of the products exist. */
    public function enabled(): bool
    {
        return $this->isOn() && Schema::hasTable(self::TABLE) && Schema::hasColumn('products', StorefrontCatalog::COLUMN);
    }

    /**
     * Resolves the employee of a request and binds the result to that request.
     *
     * @return list<string>|null the countries the request is narrowed to, or null when it is not narrowed
     */
    public function bind(Request $request, ?Admin $admin): ?array
    {
        $countries = $this->countriesOf(admin: $admin);
        $request->attributes->set(self::REQUEST_ATTRIBUTE, $countries);

        return $countries;
    }

    /**
     * The countries an employee is restricted to, resolved without binding anything to the request: for what reads shared data that every employee is served from, such as the
     * search of the header, which must not narrow the query that fills a cache everybody shares and narrows what it hands back instead.
     *
     * @return list<string>|null null when the employee is not restricted
     */
    public function countriesOf(?Admin $admin): ?array
    {
        return $admin !== null && !$this->isMasterAdmin(roleId: $admin->admin_role_id) && $this->enabled() ? $this->resolve(adminId: (int) $admin->getKey(), roleId: $admin->admin_role_id) : null;
    }

    /**
     * What the shared layout of every admin page reads of the orders (the counters of its sidebar and the latest pending orders of its header) is narrowed to the employee's
     * countries on the screens that no middleware bound: the layout is rendered after the screen, so it binds the request itself, once, when nothing has.
     */
    public function narrowLayout(Request $request, ?Admin $admin): void
    {
        if (!$request->attributes->has(self::REQUEST_ATTRIBUTE)) {
            $this->bind(request: $request, admin: $admin);
        }

        $this->narrowTo(request: $request, subjects: [self::SUBJECT_ORDERS, self::SUBJECT_REFUNDS]);
    }

    /**
     * What the rows of an employee make of their scope, whatever the switch says: null for the master admin and for an employee with no row, otherwise the stored
     * countries that are configured, which may be none.
     *
     * @return list<string>|null
     */
    public function resolve(int $adminId, ?int $roleId): ?array
    {
        if ($this->isMasterAdmin(roleId: $roleId)) {
            return null;
        }

        // Read TWICE on purpose (G1-14). An employee with NO row is unrestricted on this platform, so "ignore the expired rows" written as
        // one query would turn the last row's expiry into a privilege escalation on a timer: the employee would hold no country and be
        // read as unrestricted. Whether the employee HAS rows and which of them are still in force are two different questions, and an
        // employee whose every row has expired is restricted to NOTHING.
        $stored = $this->storedCountriesOf(adminId: $adminId);
        if ($stored === []) {
            return null;
        }

        $granted = array_values(array_unique(array_intersect($this->countriesStillInForceFor(adminId: $adminId), $this->configuredCountries())));
        sort($granted);

        return $granted;
    }

    /** Whether a scope row can record who granted it and when it ends, and whether anything should read or write those. */
    public function rowsRecordTheirGrant(): bool
    {
        return self::$rowsRecordTheirGrant ??= Schema::hasTable(self::TABLE)
            && Schema::hasColumn(self::TABLE, self::GRANTED_BY)
            && Schema::hasColumn(self::TABLE, self::EXPIRES_AT);
    }

    /**
     * The countries of an employee whose grant has not ended: every row of theirs before the expiry column exists, which is the same
     * answer, since a row that cannot record an end never ends.
     *
     * @return list<string>
     */
    public function countriesStillInForceFor(int $adminId): array
    {
        if (!$this->rowsRecordTheirGrant()) {
            return $this->storedCountriesOf(adminId: $adminId);
        }

        return DB::table(self::TABLE)
            ->where('admin_id', $adminId)
            ->where(static fn (QueryBuilder $row) => $row->whereNull(self::EXPIRES_AT)->orWhere(self::EXPIRES_AT, '>', now()))
            ->orderBy('country')
            ->pluck('country')
            ->map(static fn ($country): string => (string) $country)
            ->all();
    }

    /** Forgets what was read about the scope table's shape, for a test that migrates it mid-run. */
    public static function forgetTheSchema(): void
    {
        self::$rowsRecordTheirGrant = null;
    }

    private function isMasterAdmin(?int $roleId): bool
    {
        return $roleId === self::MASTER_ADMIN_ROLE_ID;
    }

    /**
     * Every value stored for an employee, damaged ones included.
     *
     * @return list<string>
     */
    public function storedCountriesOf(int $adminId): array
    {
        return DB::table(self::TABLE)->where('admin_id', $adminId)->orderBy('country')->pluck('country')->map(static fn ($country): string => (string) $country)->all();
    }

    /**
     * Every country the platform knows, open or not: a country's catalogue is prepared before its storefront opens.
     *
     * @return list<string>
     */
    public function configuredCountries(): array
    {
        return array_values(array_map('strval', array_keys((array) config('gcc.storefronts.countries'))));
    }

    /**
     * The countries the rows of an admin restrict them to, for what is not a screen of the admin panel and so has nothing bound to its request: the platform admin API reads it for
     * the admin who signs in to it, on every request. It is the rule of `bind()` without the binding, read from the rows as they are now and kept nowhere. The admin may have been
     * loaded without their role, which is then read.
     *
     * @return list<string>|null null when the admin is not restricted: the switch is off or not enforced, they are the master admin, or they hold no row
     */
    public function countriesOfAdmin(Admin $admin): ?array
    {
        if (!$this->isOn()) {
            return null;
        }

        $roleId = $this->roleOf(admin: $admin);

        return $roleId === self::MASTER_ADMIN_ROLE_ID || !$this->enabled() ? null : $this->resolve(adminId: (int) $admin->getKey(), roleId: $roleId);
    }

    private function roleOf(Admin $admin): ?int
    {
        return array_key_exists('admin_role_id', $admin->getAttributes()) ? $admin->admin_role_id : Admin::query()->whereKey($admin->getKey())->value('admin_role_id');
    }

    /** @return list<string>|null what the current request is narrowed to: null when it is not narrowed, an empty list when it may see nothing */
    public function boundCountries(): ?array
    {
        $bound = request()->attributes->get(self::REQUEST_ATTRIBUTE);

        return is_array($bound) ? $bound : null;
    }

    public function restricts(): bool
    {
        return $this->boundCountries() !== null;
    }

    /**
     * Narrows the orders, or the refund requests, that the rest of a bound request reads. Products are narrowed as soon as a request is bound; an order is not, because an
     * action that changes one runs on the real data (the count of a customer's delivered orders that decides a referral bonus, the number of orders that gives the next order
     * id, the uses of a coupon): only what a screen lists, and only the actions that say so, are narrowed. A request that is not bound is never narrowed.
     *
     * @param list<string> $subjects
     */
    public function narrowTo(Request $request, array $subjects): void
    {
        if ($this->restricts()) {
            $request->attributes->set(self::SUBJECTS_ATTRIBUTE, array_values(array_unique([...$this->narrowedSubjects(), ...$subjects])));
        }
    }

    /**
     * Narrows a read of the orders that is not made through the model (a sum or a count over the table), as the scope of the orders narrows the model, when the current request is
     * narrowed for them. A request that is not narrowed gets the query back as it was.
     */
    public function narrowRawOrders(QueryBuilder $query, string $orderIdColumn): QueryBuilder
    {
        $countries = $this->narrowedCountries(subject: self::SUBJECT_ORDERS);

        if ($countries !== null) {
            StaffOrderVisibility::constrainOrders(query: $query, orderIdColumn: $orderIdColumn, countries: $countries);
        }

        return $query;
    }

    public function narrows(string $subject): bool
    {
        return $this->narrowedCountries(subject: $subject) !== null;
    }

    /** @return list<string>|null the countries the queries of a subject are narrowed to in the current request: null when they are not narrowed, an empty list when none may be seen */
    public function narrowedCountries(string $subject): ?array
    {
        $bound = $this->boundCountries();

        return $bound !== null && in_array($subject, $this->narrowedSubjects(), true) ? $bound : null;
    }

    /** @return list<string> */
    private function narrowedSubjects(): array
    {
        $subjects = request()->attributes->get(self::SUBJECTS_ATTRIBUTE);

        return is_array($subjects) ? $subjects : [];
    }

    public function allows(string $country): bool
    {
        $bound = $this->boundCountries();

        return $bound === null || in_array($country, $bound, true);
    }

    /**
     * Keeps the countries the current request may work with, in the order they were given. A request that is not narrowed keeps them all.
     *
     * @param list<string> $countries
     * @return list<string>
     */
    public function narrow(array $countries): array
    {
        $bound = $this->boundCountries();

        return $bound === null ? $countries : array_values(array_intersect($countries, $bound));
    }

    /**
     * The country the rows of a bulk import are saved in for the current request, or null when the request is not narrowed. The sheet has no country column, so every row
     * takes one country: with countries tracked, the only country of the employee; while they are not, the default of the table, which has to be one of theirs. An employee
     * with several countries, or none, has no such country and may not import.
     */
    public function countryOfImportedRows(bool $countriesAreTracked): ?string
    {
        $bound = $this->boundCountries();

        return match (true) {
            $bound === null => null,
            $countriesAreTracked => count($bound) === 1 ? $bound[0] : null,
            default => in_array(self::TABLE_DEFAULT_COUNTRY, $bound, true) ? self::TABLE_DEFAULT_COUNTRY : null,
        };
    }

    /** @return string|null the language key of why a narrowed request may not import a sheet, or null when it may or the request is not narrowed */
    public function whyItMayNotImport(bool $countriesAreTracked): ?string
    {
        $bound = $this->boundCountries();
        if ($bound === null || $this->countryOfImportedRows(countriesAreTracked: $countriesAreTracked) !== null) {
            return null;
        }

        return $countriesAreTracked && count($bound) > 1
            ? 'the_import_sheet_has_no_country_and_your_account_works_with_more_than_one'
            : 'the_products_of_this_sheet_would_be_saved_in_a_country_your_account_does_not_work_with';
    }

    /**
     * Whether every one of these products exists and belongs to a country the current request may work with. A request that is not narrowed may work with every
     * product, existing or not: the screens answer for an unknown one as they always did.
     *
     * @param list<int> $productIds
     */
    public function coversEveryProduct(array $productIds): bool
    {
        $bound = $this->boundCountries();
        $ids = array_values(array_unique($productIds));
        if ($bound === null || $ids === []) {
            return true;
        }

        return DB::table('products')->whereIn('id', $ids)->whereIn(StorefrontCatalog::COLUMN, $bound)->count() === count($ids);
    }

    /**
     * Whether every one of these coupons belongs to a country the current request may work with. A request that is not narrowed may work
     * with every coupon, existing or not: the screens answer for an unknown one as they always did.
     *
     * The rule of which country a coupon belongs to is CouponCountry's and stays there -- a coupon with none is the default storefront's.
     *
     * @param list<int|string> $couponIds
     */
    public function coversEveryCoupon(array $couponIds): bool
    {
        $bound = $this->boundCountries();
        $ids = array_values(array_unique($couponIds));

        if ($bound === null || $ids === []) {
            return true;
        }

        return CouponCountry::allBelongTo(couponIds: $ids, countries: $bound);
    }

    /**
     * Whether every one of these orders is visible to the current request: it has a line and every line is a product of the employee's countries. An order that does not
     * exist is not. A request that is not narrowed may work with every order, existing or not: the screens answer for an unknown one as they always did.
     *
     * @param list<int> $orderIds
     */
    public function coversEveryOrder(array $orderIds): bool
    {
        $bound = $this->boundCountries();
        $ids = array_values(array_unique($orderIds));
        if ($bound === null || $ids === []) {
            return true;
        }

        $orders = DB::table('orders')->whereIn('orders.id', $ids);
        StaffOrderVisibility::constrainOrders(query: $orders, orderIdColumn: 'orders.id', countries: $bound);

        return $orders->count() === count($ids);
    }

    /**
     * Whether every one of these refund requests is about an order line whose product is one of the employee's.
     *
     * @param list<int> $refundIds
     */
    public function coversEveryRefund(array $refundIds): bool
    {
        $bound = $this->boundCountries();
        $ids = array_values(array_unique($refundIds));
        if ($bound === null || $ids === []) {
            return true;
        }

        $refunds = DB::table('refund_requests')->whereIn('refund_requests.id', $ids);
        StaffOrderVisibility::constrainRefunds(query: $refunds, orderLineIdColumn: 'refund_requests.order_details_id', countries: $bound);

        return $refunds->count() === count($ids);
    }

    /**
     * The market a shipping row this request creates belongs to (GCC-14c slice 6; H1-14).
     *
     * A restricted employee creates in the ONE market they work with. An employee of several has not said which, and an employee of none is
     * restricted to nothing; neither may create, because a row made with no market becomes the DEFAULT storefront's -- the one market whose
     * rows that employee is least likely to have meant and most likely to be unable to touch afterwards.
     *
     * A request that is not narrowed answers null and the row is written exactly as it always was: unmarked, which is the default
     * storefront's. That is every request while the staff country scopes are off.
     *
     * The same shape as `countryOfImportedRows()`, which settles this for a sheet of products, and for the same reason: one country or a
     * refusal, never a guess.
     */
    public function countryOfNewShippingRow(): ?string
    {
        $bound = $this->boundCountries();

        return $bound !== null && count($bound) === 1 ? $bound[0] : null;
    }

    /** @return string|null the language key of why a narrowed request may not create a shipping row, or null when it may */
    public function whyItMayNotCreateAShippingRow(): ?string
    {
        $bound = $this->boundCountries();

        if ($bound === null || count($bound) === 1) {
            return null;
        }

        return $bound === []
            ? 'your_account_works_with_no_country_so_it_cannot_create_a_shipping_rule'
            : 'your_account_works_with_more_than_one_country_so_say_which_one_this_shipping_rule_is_for';
    }

    /**
     * Whether every one of these shipping methods belongs to a country the current request may work with (GCC-14c slice 6; H1-14).
     *
     * The rule of which market a method belongs to is `StorefrontShippingMethods`' and stays there -- a method with none is the default
     * storefront's. A request that is not narrowed may work with every method, existing or not.
     *
     * @param list<int|string> $methodIds
     */
    public function coversEveryShippingMethod(array $methodIds): bool
    {
        $bound = $this->boundCountries();
        $ids = array_values(array_unique($methodIds));

        if ($bound === null || $ids === []) {
            return true;
        }

        return app(StorefrontShippingMethods::class)->allMethodsBelongTo(methodIds: $ids, countries: $bound);
    }

    /**
     * Whether every one of these inventory locations is in a country the current request may work with (GCC-14c slice 5 and 6; G1-05).
     *
     * A warehouse is in a country, and since `inventory_locations.storefront_country` it says so. Before that the only country a stock
     * screen could be asked about was the PRODUCT's, which answers for the units on the shelf and never for the shelf -- so a restricted
     * employee could be shown another market's warehouse itself, with its code, its name and its figures.
     *
     * The rule of which market a location is in is `InventoryLocationMarket`'s and stays there: a location with no market is the default
     * storefront's. A request that is not narrowed may work with every location, existing or not.
     *
     * @param list<int|string> $locationIds
     */
    public function coversEveryInventoryLocation(array $locationIds): bool
    {
        $bound = $this->boundCountries();
        $ids = array_values(array_unique($locationIds));

        if ($bound === null || $ids === []) {
            return true;
        }

        return app(InventoryLocationMarket::class)->allLocationsBelongTo(locationIds: $ids, countries: $bound);
    }

    /**
     * Whether every one of these themes belongs to a market the current request may work with (GCC-14c slice 4; F-01).
     *
     * A theme has a market of its own (`themes.storefront_country`, the `2026_12_04` migration), so unlike a deal or a banner it can be
     * judged directly. The rule of which market a theme belongs to is `StorefrontMarketRows`' and stays there: a theme that names no market
     * is the DEFAULT storefront's, which is every theme that exists today, and before the column exists every theme is that storefront's --
     * the same answer, not a special case. A theme that does not exist is not covered, so which ids are themes is not something an employee
     * can find out by asking.
     *
     * @param list<int|string> $themeIds
     */
    public function coversEveryTheme(array $themeIds): bool
    {
        $bound = $this->boundCountries();
        $ids = array_values(array_unique($themeIds));

        if ($bound === null || $ids === []) {
            return true;
        }

        return app(StorefrontMarketRows::class)->allRowsBelongTo(table: 'themes', ids: $ids, countries: $bound);
    }

    /**
     * Whether every one of these per-category shipping costs belongs to a country the current request may work with (GCC-14c slice 6; H1-14).
     *
     * @param list<int|string> $costIds
     */
    public function coversEveryCategoryShippingCost(array $costIds): bool
    {
        $bound = $this->boundCountries();
        $ids = array_values(array_unique($costIds));

        if ($bound === null || $ids === []) {
            return true;
        }

        return app(StorefrontCategoryShippingCosts::class)->allCostsBelongTo(costIds: $ids, countries: $bound);
    }

    /**
     * Whether the order of every one of these order lines is visible to the current request: a line is reached through its order.
     *
     * @param list<int> $lineIds
     */
    public function coversEveryOrderLine(array $lineIds): bool
    {
        $bound = $this->boundCountries();
        $ids = array_values(array_unique($lineIds));
        if ($bound === null || $ids === []) {
            return true;
        }

        $lines = DB::table('order_details')->whereIn('order_details.id', $ids)->whereExists(static function ($orders) use ($bound): void {
            $orders->selectRaw('1')->from('orders')->whereColumn('orders.id', 'order_details.order_id');
            StaffOrderVisibility::constrainOrders(query: $orders, orderIdColumn: 'orders.id', countries: $bound);
        });

        return $lines->count() === count($ids);
    }
}
