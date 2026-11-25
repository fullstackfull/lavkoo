<?php

namespace App\Services\Pos;

use App\Services\Storefront\StorefrontCatalog;
use App\Services\Storefront\StorefrontResolver;
use App\Services\Storefront\StorefrontSellingGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a till may ring up this sale, by the market of the products in the cart (C-16, I2-09).
 *
 * The web checkout has asked {@see StorefrontSellingGate} since GCC-11: a storefront that is paused, or that has not met what it needs to
 * open, takes no new order. The three tills did not ask anything -- the admin panel's, the vendor panel's and the seller app's -- so a
 * market could be stopped for every customer on the website and go on selling across the counter all afternoon. That is not a pause.
 *
 * **The market of a till sale is the market of its PRODUCTS, not of the request.** That is the owner's own decision (I2-09: a new
 * point-of-sale sale follows the country of its products and their legal company, and respects the sale gate and the market's state), and
 * it is also the only thing that can be read here: a panel is served from no storefront, so `StorefrontCatalog::country()` is null for it
 * -- correctly, because a panel sees every market. Asking the REQUEST's storefront would have read the default market for every till in
 * every country.
 *
 * A cart holding two markets' products is judged on BOTH, and the first market that may not sell refuses the sale. A single-market cart is
 * the normal case and the one a till actually rings up; this says what happens to the other one rather than leaving it to chance.
 *
 * It is dormant in the only sense that matters: while storefronts are off the gate refuses nothing, so every till behaves exactly as it
 * does today and the Saudi platform is untouched.
 *
 * Read with the QUERY BUILDER rather than through `App\Models\Product`, whose reads a restricted employee's scope narrows: a cashier
 * restricted to Kuwait must not be able to ring up a Saudi line just because their own scope hid it from this check.
 */
final class PosSaleGate
{
    public function __construct(private readonly StorefrontSellingGate $gate)
    {
    }

    /**
     * Why this till may not ring up these products, or null when every market in the cart is selling.
     *
     * @param list<int|string> $productIds
     */
    public function refusalFor(array $productIds): ?string
    {
        foreach ($this->marketsOf($productIds) as $market) {
            if (!$this->marketIsSelling($market)) {
                return $market;
            }
        }

        return null;
    }

    /** @param list<int|string> $productIds */
    public function maySell(array $productIds): bool
    {
        return $this->refusalFor($productIds) === null;
    }

    /**
     * The markets the products of this cart belong to. A product that names none is the DEFAULT storefront's, which is every product of a
     * single-market platform.
     *
     * @param list<int|string> $productIds
     * @return list<string>
     */
    public function marketsOf(array $productIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $productIds)));

        if ($ids === [] || !Schema::hasTable('products')) {
            return [];
        }

        $default = StorefrontResolver::fromConfig()->defaultCountry();

        if (!Schema::hasColumn('products', StorefrontCatalog::COLUMN)) {
            return [$default];
        }

        $markets = [];

        foreach (DB::table('products')->whereIn('id', $ids)->distinct()->pluck(StorefrontCatalog::COLUMN) as $named) {
            $market = trim((string) $named) === '' ? $default : strtoupper(trim((string) $named));

            if (!in_array($market, $markets, true)) {
                $markets[] = $market;
            }
        }

        return $markets;
    }

    private function marketIsSelling(string $market): bool
    {
        $resolver = StorefrontResolver::fromConfig();

        // A market the resolver does not configure has no currency to ask the gate about, and `currencyOf()` throws rather than guess
        // one. Such a market is not a stopped market: it is one nobody has set up, and refusing a sale for it would stop a till over a
        // configuration gap rather than over a decision somebody took.
        if (!in_array($market, $resolver->configuredCountries(), true)) {
            return true;
        }

        return $this->gate->maySell(country: $market, currencyCode: $resolver->currencyOf($market));
    }
}
