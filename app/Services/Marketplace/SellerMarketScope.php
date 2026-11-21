<?php

namespace App\Services\Marketplace;

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

    /** The field a seller route reads a market from besides the header, as the order list already does. */
    public const REQUEST_FIELD = 'storefront';

    public function __construct(private readonly SellerPermissionService $permissions)
    {
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
     * market of its own; a staff member to their own rows.
     *
     * @return list<string>|null the markets granted, or null when none are: no row is no limit
     */
    public function grantOf(SellerPrincipal $principal): ?array
    {
        $stored = $this->storedMarketsOf($principal);

        if ($stored === []) {
            return null;
        }

        $granted = array_values(array_unique(array_intersect($stored, $this->configuredMarkets())));
        sort($granted);

        return $granted;
    }

    /**
     * Every market stored for a principal, damaged values included.
     *
     * @return list<string>
     */
    public function storedMarketsOf(SellerPrincipal $principal): array
    {
        return $this->rowsOf($principal)->pluck('country')->map(static fn ($country): string => (string) $country)->all();
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
        $market = $this->workingMarket();

        return $market === null || !Schema::hasColumn('products', StorefrontCatalog::COLUMN) ? [] : [StorefrontCatalog::COLUMN => $market];
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
        $bound = $this->boundGrant();

        return match (true) {
            $bound === null, $this->workingMarket() !== null => null,
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

    public function restricts(): bool
    {
        return $this->boundGrant() !== null;
    }

    public function allows(string $market): bool
    {
        $bound = $this->boundGrant();

        return $bound === null || in_array($market, $bound, true);
    }

    /**
     * The markets a narrowed request may read, in the order they were given. A request that is not narrowed reads them all.
     *
     * @param  list<string>  $markets
     * @return list<string>
     */
    public function narrow(array $markets): array
    {
        $bound = $this->boundGrant();

        return $bound === null ? $markets : array_values(array_intersect($markets, $bound));
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

    /** The rows of a principal, read once per request. An owner and a key read the shop's own rows; a staff member reads theirs. */
    private function rowsOf(SellerPrincipal $principal): Collection
    {
        if (!$this->enabled()) {
            return collect();
        }

        return DB::table(self::TABLE)
            ->where('seller_id', $principal->sellerId())
            ->where('staff_id', $principal->staffId() ?? self::OWNER_STAFF_ID)
            ->orderBy('country')
            ->get(['country', 'seller_role_id']);
    }
}
