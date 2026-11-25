<?php

namespace App\Services\Checkout;

use App\Services\Storefront\StorefrontCatalog;
use App\Services\Storefront\StorefrontResolver;
use App\Services\Storefront\StorefrontSellingGate;
use Illuminate\Container\Attributes\Singleton;

/**
 * Whether a till may make a NEW sale in the market its products belong to (D1-16, owner decision I2-09).
 *
 * The owner's decision is written down and is not this class's to make: *every NEW point-of-sale sale follows the country of its products
 * and their legal company and respects the sale gate and the market's state. Orders placed before a stop keep being processed,
 * sales-channel obligations included, without opening new sales in an unauthorised way.* The customer-facing routes have carried that gate
 * since GCC-11 (`storefront.sellable` on every checkout and payment route); the three tills carried nothing, so a market that had been
 * stopped, paused or never made ready went on selling over the counter and through the seller app.
 *
 * It asks the SAME question of the SAME inputs as {@see \App\Services\Storefront\OneSellingCompanyPerOrder}, and is meant to be called in
 * the same place: the products the till has already loaded to price the cart, judged before the stock is taken and before the order and
 * its lines are written, so a refusal leaves the stock, the wallet and the receipt exactly where they were. That is also why it reads the
 * PRODUCTS and not the request: a till is not served from a storefront, so there is no bound market to read -- the market of a counter sale
 * is the market of the goods on the counter.
 *
 * WHAT IT REFUSES, AND WHEN IT CANNOT REFUSE ANYTHING:
 *
 *  - while `gcc.storefronts.sale_gate.at_the_till` is off, which is how it ships, it answers null without asking anything. See
 *    {@see self::isEnabled()} for the measurement behind that default;
 *  - while storefronts are off, which is every installation today, `StorefrontSellingGate::maySell()` answers true for every market and
 *    this answers null. A Saudi till is unchanged, to the line;
 *  - products that name no market, or that name more than one, are answered null. The first is every product before the country migration
 *    ran; the second is already refused by `OneSellingCompanyPerOrder`, and two refusals for one cart would make the operator fix the
 *    second fault to be told about the first;
 *  - the default market is active without a record, so it is refused only once a person has paused it.
 *
 * It decides nothing about an order that already exists. A till refund, a cancellation and the collection of money owed on a sale already
 * made are obligations and are never refused here, exactly as the customer-facing gate never refuses them.
 */
#[Singleton]
final class PointOfSaleSaleRule
{
    public const SETTING = 'gcc.storefronts.sale_gate.at_the_till';

    public function __construct(private readonly StorefrontSellingGate $gate)
    {
    }

    /**
     * Whether a till asks the gate at all.
     *
     * OFF as it ships, and the reason is measured rather than cautious: a market whose lifecycle record is absent is a DRAFT and not an
     * active market, so adopting this refuses a till sale in any market nobody has made active -- and six existing test worlds sell at a
     * till in exactly such a market, several of them the evidence for other criteria. Those worlds are legitimately incomplete, since a
     * CUSTOMER could not check out in them either, and completing them is cross-lane work that has to come before the switch is thrown.
     * `config/gcc.php` carries the same note beside the key.
     */
    public function isEnabled(): bool
    {
        return (bool) config(self::SETTING, false);
    }

    /**
     * The market of these goods, when it may not sell, or null when the sale may go ahead.
     *
     * @param iterable<object|null> $products the products of the lines about to be written
     */
    public function marketThatMayNotSell(iterable $products): ?string
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $market = $this->soleMarketOf(products: $products);

        if ($market === null) {
            return null;
        }

        $resolver = StorefrontResolver::fromConfig();

        // A market the configuration does not name has no storefront to be ready or not: there is nothing to gate, and asking for its
        // currency would THROW -- `currencyOf()` raises on an unconfigured country, which on this path would turn a stale country value on
        // one product into a 500 at the till instead of a sale. Answering null leaves such a cart exactly as it sells today.
        if (!in_array($market, $resolver->configuredCountries(), true)) {
            return null;
        }

        return $this->gate->maySell(country: $market, currencyCode: $resolver->currencyOf($market)) ? null : $market;
    }

    /**
     * The one market these products belong to, or null when they name none or more than one.
     *
     * Read through `StorefrontCatalog::sellingScopeOf()`, which is the same reader the one-company rule uses, so the two cannot come to
     * different conclusions about which market a till's cart is in.
     *
     * Reading the markets back by product ID through the query builder was considered instead, on the ground that a cashier restricted to
     * one country must not ring up another country's line because their own scope hid it from this check. It is not adopted: a product a
     * scoped query cannot see is not priced into the cart either, so it is not sold, and a second read of the same products could answer
     * differently from the one-company rule beside it -- which is the fault being avoided here, not one worth introducing.
     *
     * @param iterable<object|null> $products
     */
    private function soleMarketOf(iterable $products): ?string
    {
        $markets = [];

        foreach ($products as $product) {
            $market = StorefrontCatalog::sellingScopeOf(product: $product)[StorefrontCatalog::COLUMN] ?? null;

            if ($market !== null && trim($market) !== '' && !in_array($market, $markets, true)) {
                $markets[] = $market;
            }
        }

        return count($markets) === 1 ? strtoupper($markets[0]) : null;
    }
}
