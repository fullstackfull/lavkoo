<?php

namespace App\Services\Marketplace;

use App\Models\Order;
use App\Models\Product;
use App\Models\Seller;
use App\Models\SellerStaff;
use App\Services\Storefront\OrderStorefront;
use App\Services\Storefront\StorefrontCatalog;
use App\Services\Storefront\StorefrontResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The markets a seller principal may work in, and the role that applies in each (K-09).
 *
 * The one place that knows the switch (`gcc.seller.market_scopes`), the grant table and how its rows become a scope. It ships off
 * and acts as off until the table exists: nothing is read then, and every seller endpoint answers what it always answered.
 *
 * **The grant is read from the rows and never from the request.** A market named in a header, a body or a query string is a
 * *request*: `refusalFor()` compares it with the rows and refuses what they do not hold. Widening a grant therefore means writing
 * a row, which no route does — so a caller who can set a header cannot reach a market they were not granted.
 *
 * A principal with no row is unrestricted, exactly as an employee with no country row is in StaffCountryScope (GCC-14c), so
 * turning the switch on changes nothing until a market is granted. A grant whose rows name no configured market grants nothing and
 * is never read as "no limit": `[]` is a scope that reaches nothing, `null` is no scope at all.
 *
 * **The shop's own grant is the ceiling of everyone who works in it.** "No row means unrestricted" is read of the shop, never of a
 * staff member inside a shop that is itself limited: a staff member with no row of their own is held to the shop's markets, and a
 * staff member with rows is held to the overlap of theirs and the shop's. Reading a staff member's rows alone would make a staff
 * member outrank the owner who created them — the owner refused a market their own grant does not name while their employee was
 * answered it, and passed the all-markets gate on staff management, payouts and bank details that the owner was refused.
 *
 * What a request resolved to lives on that request alone, never in the container, a static or the cache, so the next request of
 * the same worker starts unbound.
 */
final class SellerMarketScope
{
    public const SWITCH = 'gcc.seller.market_scopes';

    public const TABLE = 'seller_market_scopes';

    /** The row of the shop's own account: `staff_id` is 0 rather than NULL so the unique index holds on every engine. */
    public const OWNER_STAFF_ID = 0;

    /** Where the resolved grant lives on the request. */
    private const GRANT_ATTRIBUTE = 'seller_market_grant';

    /** Where the one market a request works in lives, when it names one. */
    private const WORKING_MARKET_ATTRIBUTE = 'seller_working_market';

    /**
     * Where the grant of a VENDOR WEB PANEL request lives, kept apart from the API's on purpose (M2-03).
     *
     * The API narrows query by query, in its controllers, and compares a narrowed query with an unnarrowed one to answer whether a record
     * names a product outside the grant ({@see anyProductOutsideTheGrant}). The panel narrows structurally, through a global scope on the
     * models, because its reads are spread over twenty-five controllers and a repository layer that no single call site owns. Those two
     * cannot share one attribute: a global scope reading the API's grant would narrow BOTH sides of that comparison, and the comparison
     * would then always answer "inside the grant" -- a guard that looks like it works and refuses nothing.
     */
    private const PANEL_GRANT_ATTRIBUTE = 'seller_market_panel_grant';

    /** The field a seller route reads a market from besides the header, as the order list already does. */
    public const REQUEST_FIELD = 'storefront';

    public function __construct(
        private readonly SellerPermissionService $permissions,
        private readonly OrderStorefront $orderMarkets,
    ) {
    }

    /** Whether the switch is on. Nothing else is asked while it is off. */
    public function isOn(): bool
    {
        return filter_var(config(self::SWITCH, false), FILTER_VALIDATE_BOOLEAN);
    }

    /** Whether grants are enforced: the switch is on and the grant table exists. */
    public function enabled(): bool
    {
        return $this->isOn() && Schema::hasTable(self::TABLE);
    }

    /**
     * What the rows of a principal make of their scope, whatever the switch says.
     *
     * An integration key is held to the markets of the shop that issued it, because a key is no person and cannot be granted a
     * market of its own. A staff member is held to their own rows **and never past the shop's**: with no row of their own they
     * take the shop's grant, and with rows of their own they take the overlap. So a staff member of a shop granted Saudi Arabia
     * alone can hold Saudi Arabia or nothing, never Kuwait, whatever rows their own id carries.
     *
     * @return list<string>|null the markets granted, or null when none are: no row is no limit
     */
    public function grantOf(SellerPrincipal $principal): ?array
    {
        $ceiling = $this->storedMarketsOfShop($principal);
        $stored = $principal->staffId() === null ? $ceiling : $this->heldUnderTheShop(
            staffMarkets: $this->storedMarketsOf($principal),
            shopMarkets: $ceiling,
        );

        if ($stored === null) {
            return null;
        }

        $granted = array_values(array_unique(array_intersect($stored, $this->configuredMarkets())));
        sort($granted);

        return $granted;
    }

    /**
     * What a staff member's own rows come to once the shop's grant is applied over them.
     *
     * Three cases, and the middle one is the hole this closes: an unlimited shop leaves its staff their own rows, so a shop that
     * holds every market may still give one person Dubai alone; a limited shop lends its own markets to a staff member who has
     * none, rather than letting the absence of a row read as "every market"; and a staff member who has rows keeps only those the
     * shop also holds, so a stale row naming a market the shop has since lost grants nothing.
     *
     * @param  list<string>  $staffMarkets
     * @param  list<string>|null  $shopMarkets
     * @return list<string>|null
     */
    private function heldUnderTheShop(array $staffMarkets, ?array $shopMarkets): ?array
    {
        if ($shopMarkets === null) {
            return $staffMarkets === [] ? null : $staffMarkets;
        }

        return $staffMarkets === [] ? $shopMarkets : array_values(array_intersect($staffMarkets, $shopMarkets));
    }

    /**
     * Every market stored for a principal, damaged values included.
     *
     * @return list<string>
     */
    private function storedMarketsOf(SellerPrincipal $principal): array
    {
        return $this->rowsOf($principal)->pluck('country')->map(static fn ($country): string => (string) $country)->all();
    }

    /**
     * The markets the shop's own account holds, or null when it holds no row at all.
     *
     * Null and `[]` are different answers and the difference is the whole of this: a shop with no row is unrestricted and lends
     * nothing, while a shop whose rows name only markets the platform no longer serves is limited to nothing and lends nothing.
     *
     * @return list<string>|null
     */
    private function storedMarketsOfShop(SellerPrincipal $principal): ?array
    {
        $stored = $this->rowsOfShop($principal->sellerId())
            ->pluck('country')
            ->map(static fn ($country): string => (string) $country)
            ->all();

        return $stored === [] ? null : $stored;
    }

    /**
     * What a staff member may do in one market: the role the grant names for that market, and the role on their staff record when
     * it names none — which is every staff member today, so a grant that names no role per market leaves the set byte-identical.
     *
     * The owner is never permission-checked and an integration key is held to its own scopes, so neither is narrowed here.
     *
     * @return list<string>
     */
    public function permissionsIn(SellerPrincipal $principal, string $market): array
    {
        if ($principal->staff === null) {
            return $principal->permissions;
        }

        $roleId = $this->rowsOf($principal)->firstWhere('country', $market)->seller_role_id ?? null;

        return $roleId === null
            ? $principal->permissions
            : $this->permissions->permissionsOfRole(sellerId: $principal->sellerId(), roleId: (int) $roleId);
    }

    /**
     * What a staff member may do across a whole grant, when a request names no one market: only what they may do in every market
     * of it. A staff member who may refund in Riyadh and only read in Dubai must not refund a list that holds both.
     *
     * @param  list<string>  $grant
     * @return list<string>
     */
    public function permissionsAcross(SellerPrincipal $principal, array $grant): array
    {
        if ($principal->staff === null || $grant === []) {
            return $principal->permissions;
        }

        $sets = array_map(fn (string $market): array => $this->permissionsIn(principal: $principal, market: $market), $grant);

        return array_values(array_intersect(...$sets));
    }

    /**
     * The market a request names: the `X-Storefront` header first, then the field a seller route already reads. Upper-cased and
     * trimmed, as the order filter does. Null when it names none, and null when what it names is not a two-letter code — naming
     * nothing is not naming a market, and a value that is no code is refused later rather than silently dropped.
     */
    public function requestedMarket(Request $request): ?string
    {
        $named = $request->headers->get(StorefrontResolver::HEADER) ?? $request->input(self::REQUEST_FIELD);

        if (!is_string($named)) {
            return null;
        }

        $market = strtoupper(trim($named));

        return $market === '' ? null : $market;
    }

    /**
     * Resolves a principal's grant and the market this request works in, and binds both to the request.
     *
     * @return array{0: list<string>|null, 1: string|null} the grant, and the one market the request works in
     */
    public function bind(Request $request, ?SellerPrincipal $principal): array
    {
        $grant = $principal !== null && $this->enabled() ? $this->grantOf($principal) : null;
        $working = $grant === null ? null : $this->workingMarketOf(request: $request, grant: $grant);

        $request->attributes->set(self::GRANT_ATTRIBUTE, $grant);
        $request->attributes->set(self::WORKING_MARKET_ATTRIBUTE, $working);

        return [$grant, $working];
    }

    /**
     * The one market a narrowed request works in: the one it names, and the only market of the grant when it names none. A grant of
     * several markets with nothing named works in no single market, so its lists span the grant and its writes are held to what
     * every market of it allows.
     *
     * @param  list<string>  $grant
     */
    private function workingMarketOf(Request $request, array $grant): ?string
    {
        $named = $this->requestedMarket($request);

        if ($named !== null) {
            return in_array($named, $grant, true) ? $named : null;
        }

        return count($grant) === 1 ? $grant[0] : null;
    }

    /**
     * What a new seller product is saved with: the market this request works in (K-04).
     *
     * Nothing while the request is not narrowed, so a seller with no grant — every seller while the switch is off — keeps the
     * table's own default and the insert is byte-identical. A narrowed request saves the market it works in, which the middleware
     * has already checked against the grant, so the column is never the table default when a market was granted.
     *
     * @return array<string, string>
     */
    public function newProductAttributes(): array
    {
        $market = $this->workingMarket() ?? $this->onlyMarketOfTheSignedInSeller();

        return $market === null || !Schema::hasColumn('products', StorefrontCatalog::COLUMN) ? [] : [StorefrontCatalog::COLUMN => $market];
    }

    /**
     * The one market a seller signed into the vendor web panel works in, or null when there is not exactly one (K-09).
     *
     * The web panel carries no grant: `seller_market` is declared on the seller API group, and the middleware resolves a
     * principal the API's token middleware put on the request, which a session request never has. So a seller granted Dubai alone
     * added a product through the panel and it landed on the column's own default — a market they do not even hold.
     *
     * Asked of the shop's own rows, because the panel signs in the account holder; a staff member of the panel is the same shop.
     * Null when the shop holds no grant, which is every seller while the switch is off, so the insert is byte-identical; null
     * again when it holds several, because then there is no one market and `whyItMayNotCreateInAMarket()` refuses rather than
     * choosing one.
     */
    private function onlyMarketOfTheSignedInSeller(): ?string
    {
        $grant = $this->grantOfTheSignedInSeller();

        return $grant !== null && count($grant) === 1 ? $grant[0] : null;
    }

    /**
     * The grant of the seller signed into the vendor web panel, or null when nobody is signed in there and when they hold none.
     *
     * @return list<string>|null
     */
    private function grantOfTheSignedInSeller(): ?array
    {
        $principal = $this->enabled() ? $this->principalOfThePanel() : null;

        return $principal === null ? null : $this->grantOf($principal);
    }

    /**
     * Who is acting in the vendor web panel, or null when nobody is signed in there (M2-03).
     *
     * The panel signs a staff member in AS THEIR EMPLOYER -- `auth('seller')` is the shop and `session('seller_staff_id')` is the person --
     * so the shop alone was all the grant could ever see from a session request, and a staff member of a limited shop was read as the shop.
     * Reading the person as well is what makes a staff member's own rows count in the panel exactly as they count on the API: with no row of
     * their own they take the shop's grant, and with rows of their own they take the overlap, never more ({@see heldUnderTheShop}). It can
     * therefore only ever narrow what the panel already showed.
     *
     * The staff row is read back and checked against the shop that is signed in, because a session is not a grant: a stale or tampered
     * `seller_staff_id` falls back to the shop's own account rather than lending its rows to another shop's employee.
     *
     * The permissions come from the staff record, as they do for an API staff principal, so nothing that reads this principal is handed an
     * empty set that would look like a role with no rights. What the panel may DO is still decided by SellerStaffAccessMiddleware; what this
     * answers is which markets it may do it in.
     */
    private function principalOfThePanel(): ?SellerPrincipal
    {
        $seller = auth('seller')->user();

        if (!$seller instanceof Seller) {
            return null;
        }

        $staff = $this->staffOfThePanelSession(sellerId: (int) $seller->getKey());

        return $staff === null
            ? SellerPrincipal::owner($seller)
            : SellerPrincipal::staff(seller: $seller, staff: $staff, permissions: $this->permissions->permissionsOf($staff));
    }

    /** The staff member the panel session names, when it names one that is still active and still belongs to the shop signed in. */
    private function staffOfThePanelSession(int $sellerId): ?SellerStaff
    {
        $staffId = session('seller_staff_id');

        if (!is_numeric($staffId)) {
            return null;
        }

        $staff = SellerStaff::query()->find((int) $staffId);

        return $staff instanceof SellerStaff
            && $staff->status === SellerStaff::STATUS_ACTIVE
            && (int) $staff->seller_id === $sellerId ? $staff : null;
    }

    /**
     * Resolves the grant of a vendor web panel request and binds it to that request (M2-03).
     *
     * The panel's reads were the hole this closes. `seller_market` is declared on the seller API group and its middleware resolves a
     * principal the API's token middleware put on the request, which a session request never has -- so every list, picker, report and
     * lookup-by-id of `app/Http/Controllers/Vendor/` answered across every market, while the panel's WRITES were already held to the
     * grant. A seller granted Dubai alone could not add a product outside Dubai and could read, edit, price and discount every product
     * the shop had in Riyadh.
     *
     * What is bound here narrows the panel through the global scopes of the models ({@see \App\Models\Scopes\SellerMarketProductScope},
     * {@see \App\Models\Scopes\SellerMarketOrderScope}) rather than through twenty-five controllers, so a screen cannot forget it and
     * one added tomorrow is covered. It lives on the request alone, so the next request of the same worker starts unbound.
     *
     * Null for a principal with no grant -- every seller while the switch is off -- and the panel is then the panel it always was.
     *
     * @return list<string>|null
     */
    public function bindPanel(Request $request): ?array
    {
        $grant = $this->enabled() ? $this->grantOfTheSignedInSeller() : null;

        $request->attributes->set(self::PANEL_GRANT_ATTRIBUTE, $grant);

        return $grant;
    }

    /**
     * What a vendor panel request is narrowed to: null when it is not narrowed, `[]` when it may see nothing.
     *
     * Read by the global scopes of the models and by nothing else. It is deliberately NOT {@see boundGrant}: see PANEL_GRANT_ATTRIBUTE.
     *
     * @return list<string>|null
     */
    public function panelGrant(): ?array
    {
        $bound = request()->attributes->get(self::PANEL_GRANT_ATTRIBUTE);

        return is_array($bound) ? $bound : null;
    }

    /**
     * Whether any product named lies in a market this request was not granted (K-09).
     *
     * For the records that carry no market of their own and name products instead — a bulk job's receipt is the clearest: its
     * input is a list of product ids and its failure list carries their names. Such a record belongs to the markets of the
     * products it names, so a token that may not see one of them may not see the record.
     *
     * A product that no longer exists is not outside anything: only a row that is there and whose market is not granted refuses,
     * so a job whose products were deleted stays readable to the token that queued it.
     *
     * @param  array<int, int|string>  $productIds
     */
    public function anyProductOutsideTheGrant(array $productIds): bool
    {
        $ids = array_values(array_unique(array_map('intval', $productIds)));

        if ($this->boundGrant() === null || $ids === []) {
            return false;
        }

        $present = Product::withoutGlobalScope('translate')->whereKey($ids)->pluck('id')->map('intval')->all();
        $granted = $this->narrowQuery(Product::withoutGlobalScope('translate')->whereKey($ids))->pluck('id')->map('intval')->all();

        return array_diff($present, $granted) !== [];
    }

    /**
     * Why a narrowed request may not create a product, as a language key, or null when it may.
     *
     * A grant of several markets with none named has no one market to save the product in, and the table default would put it in a
     * market the seller may not even hold — so the create is refused and says so, rather than landing somewhere nobody chose.
     * This mirrors how a bulk import of a restricted employee is refused (StaffCountryScope::whyItMayNotImport, GCC-14c).
     */
    public function whyItMayNotCreateInAMarket(): ?string
    {
        $bound = $this->boundGrant() ?? $this->grantOfTheSignedInSeller();

        return match (true) {
            $bound === null, $this->workingMarket() !== null, count((array) $bound) === 1 => null,
            $bound === [] => 'your_account_does_not_work_in_any_market',
            default => 'name_the_market_this_product_belongs_to_your_account_works_in_more_than_one',
        };
    }

    /** @return list<string>|null what this request is narrowed to: null when it is not narrowed, `[]` when it may see nothing */
    public function boundGrant(): ?array
    {
        $bound = request()->attributes->get(self::GRANT_ATTRIBUTE);

        return is_array($bound) ? $bound : null;
    }

    /** The one market this request works in, or null when it spans its grant or is not narrowed. */
    public function workingMarket(): ?string
    {
        $market = request()->attributes->get(self::WORKING_MARKET_ATTRIBUTE);

        return is_string($market) ? $market : null;
    }

    /**
     * Keeps the rows of a query whose market this request was granted (K-09).
     *
     * The one narrowing every list of this API uses — products, their reports, their exports, their bulk jobs and the rules that
     * change them all read the same column — so the surfaces cannot disagree about what a staff member may see. A query that is
     * not narrowed is returned untouched, so a seller with no grant reads exactly the list they always read.
     *
     * A grant of nothing keeps nothing: `[]` is a scope that reaches no market, never read as "no limit".
     */
    public function narrowQuery(Builder $query, string $column = StorefrontCatalog::COLUMN): Builder
    {
        $bound = $this->boundGrant();

        if ($bound === null) {
            return $query;
        }

        if ($bound === [] || !Schema::hasColumn($query->getModel()->getTable(), $column)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn($query->qualifyColumn($column), $bound);
    }

    /**
     * Keeps the orders of the markets this request was granted (K-09).
     *
     * The order list, the order report, their exports and every read of one order by its id go through this, so the detail of an
     * order cannot answer what the list that leads to it refuses. The rule for an order with no recorded market is the list's
     * rule and not a second one: OrderStorefront reads it as the default market's, as the whole platform does.
     */
    public function narrowOrders(Builder $orders): Builder
    {
        $bound = $this->boundGrant();

        return $bound === null ? $orders : $this->orderMarkets->narrowToGrant(orders: $orders, grant: $bound);
    }

    /**
     * Keeps the orders of the one market a narrowed request works in, inside the grant that bounds it (K-09).
     *
     * The grant is the bound and the working market is the choice inside it, which is how the order list has always read a named
     * market: a token holding two markets that names one is answered that one, and naming nothing spans the grant. Untouched
     * while the request is not narrowed, so the vendor panel and every seller before the switch read what they always read.
     */
    public function narrowOrdersToTheWorkingMarket(Builder $orders): Builder
    {
        $market = $this->workingMarket();
        $orders = $this->narrowOrders($orders);

        return $market === null || $this->boundGrant() === null ? $orders : $this->orderMarkets->narrow(orders: $orders, country: $market);
    }

    /**
     * Keeps the rows of the one market a narrowed request works in, inside the grant that bounds it (K-09).
     *
     * The same choice-inside-a-bound for a table that keeps the market on its own row, so a report of products answers the market
     * the token named exactly as a report of orders does.
     *
     * A table that has no market column is answered by the bound alone, which already keeps nothing of it. Naming the column
     * again would put `where products.storefront_country = 'KW'` into a statement whose table has no such column: MariaDB
     * refuses the whole statement with an unknown column, while SQLite reads the name as a string and returns the empty answer
     * the bound was going to give anyway. So the engines disagreed, and the one that disagreed is the one production runs.
     */
    public function narrowToTheWorkingMarket(Builder $query, string $column = StorefrontCatalog::COLUMN): Builder
    {
        $market = $this->workingMarket();
        $bound = $this->narrowQuery($query, $column);

        if ($market === null || $this->boundGrant() === null || !Schema::hasColumn($query->getModel()->getTable(), $column)) {
            return $bound;
        }

        return $bound->where($query->qualifyColumn($column), $market);
    }

    /**
     * Keeps the rows whose order this request was granted: the market of a record that hangs off an order is the order's (K-09).
     *
     * A return authorisation carries no market of its own — the sale it reverses does. Asked as a subquery rather than as a list
     * of ids, so the bound costs one query whatever the shop's size and cannot be outgrown by a long catalogue.
     */
    public function narrowByOrder(Builder $query, string $column = 'order_id'): Builder
    {
        $granted = $this->grantedOrderIds();

        return $granted === null ? $query : $query->whereIn($query->qualifyColumn($column), $granted);
    }

    /**
     * The ids of the orders this request was granted, as a subquery, or null when the request is not narrowed (K-09).
     *
     * The same bound `narrowByOrder()` applies, for a caller holding a plain query builder rather than an Eloquent one: the
     * seller's own reconciliation walks `order_details` joined to `orders` through the query builder, and the alternative would
     * be a second copy of the grant arithmetic there. Null rather than an always-true subquery, so an unbound request runs the
     * query it always ran.
     */
    public function grantedOrderIds(): ?Builder
    {
        return $this->boundGrant() === null ? null : $this->narrowOrders(Order::query())->select('orders.id');
    }

    /**
     * Whether this request may read the one order it names (K-09).
     *
     * The bound of the order list, asked of one id: a request not narrowed reaches every order it always reached, and a narrowed
     * one reaches an order only inside its grant. The caller answers not-found when this is false, as it already does for an
     * order of another shop — a reader learns that they have no such order, not that one exists in a market they may not see.
     */
    public function mayReadOrder(int|string $orderId): bool
    {
        return $this->boundGrant() === null || $this->narrowOrders(Order::query()->whereKey($orderId))->exists();
    }

    /**
     * Whether a narrowed principal holds every market the platform serves. The shop's whole account — its bank details, its staff
     * and its own deletion — is not the work of one market, so a principal who holds only some of them may not reach it (K-V2).
     * Only the platform owner sees every market, and they do not come through this API.
     */
    public function holdsEveryMarket(): bool
    {
        $bound = $this->boundGrant();

        return $bound === null || array_diff($this->configuredMarkets(), $bound) === [];
    }

    /**
     * Every market the platform knows, open or not: a market's catalogue is prepared before its storefront opens.
     *
     * @return list<string>
     */
    public function configuredMarkets(): array
    {
        return StorefrontResolver::fromConfig()->configuredCountries();
    }

    /**
     * The one market a shop is granted, or null when it is granted none or more than one (read by the platform monitor, L-09).
     *
     * A shop granted exactly one market belongs to that market, and anything recorded against it -- an SLA breach, a payout waiting for
     * approval -- is that market's business. A shop granted SEVERAL belongs to no single one, and a shop granted none is not narrowed at all,
     * so neither can be attributed: the monitor reports those apart rather than putting them under the first market it finds, which would be
     * a count somebody acts on. While the grants are switched off every shop answers null, which is the truth about a one-market platform.
     */
    public function soleMarketOfShop(int|string $sellerId): ?string
    {
        $markets = $this->rowsOfShop(sellerId: (int) $sellerId)->pluck('country')->all();

        return count($markets) === 1 ? (string) $markets[0] : null;
    }

    /** The rows of a principal. An owner and a key read the shop's own rows; a staff member reads theirs. */
    private function rowsOf(SellerPrincipal $principal): Collection
    {
        return $this->rows(sellerId: $principal->sellerId(), staffId: $principal->staffId() ?? self::OWNER_STAFF_ID);
    }

    /** The rows of the shop's own account, which bound everyone who works in it. */
    private function rowsOfShop(int $sellerId): Collection
    {
        return $this->rows(sellerId: $sellerId, staffId: self::OWNER_STAFF_ID);
    }

    private function rows(int $sellerId, int $staffId): Collection
    {
        if (!$this->enabled()) {
            return collect();
        }

        return DB::table(self::TABLE)
            ->where('seller_id', $sellerId)
            ->where('staff_id', $staffId)
            ->orderBy('country')
            ->get(['country', 'seller_role_id']);
    }
}
