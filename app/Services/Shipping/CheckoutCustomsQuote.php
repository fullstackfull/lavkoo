<?php

namespace App\Services\Shipping;

use App\Services\Storefront\StorefrontCatalog;
use App\Services\Storefront\StorefrontResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The customs duty quoted for a basket BEFORE payment (H1-09), for whichever surface is asking.
 *
 * The requirement is about what a customer is told: a cross-border order shows the duty, or that the buyer pays it on delivery, before
 * payment. One service answers it, so the website's checkout and the app are told the same figure in the same words -- the alternative is
 * two formulas and a customer shown one number on a phone and another in a browser.
 *
 * The value quoted is the value of the GOODS: the lines of the basket, less their discount, without shipping and without tax. Duty is
 * charged on the thing crossing the border, not on what it cost to get it there or on another country's VAT, and
 * {@see OrderCustomsQuote} records the same figure off the order's own lines so that what was shown is what is kept.
 *
 * Silent while duty is off, which is how the platform ships: `null` means there is nothing to say, and a surface that reads null draws no
 * line at all rather than a line saying nothing is owed.
 */
final class CheckoutCustomsQuote
{
    /**
     * @param list<int|string> $cartGroupIds the cart groups being checked out
     */
    public function forCart(array $cartGroupIds, ?string $destination): ?CustomsDutyQuote
    {
        if (!CustomsDuty::isOn()) {
            return null;
        }

        $quote = CustomsDuty::quoteFor(
            source: $this->sellingMarket($cartGroupIds),
            destination: $destination,
            value: $this->goodsValue($cartGroupIds),
        );

        // Nothing payable on a domestic basket is not news, and a shop selling inside one country must see no trace of this.
        return $quote->kind === CustomsDutyQuote::NONE && $quote->amount === 0.0 && !$this->crossesABorder($cartGroupIds, $destination)
            ? null
            : $quote;
    }

    /**
     * What the goods in these groups are worth: price less discount, times quantity, over the CHECKED lines only.
     *
     * Read through StorefrontCatalog like every other cart read, so a request narrowed to one market sees that market's lines and no
     * others.
     *
     * @param list<int|string> $cartGroupIds
     */
    private function goodsValue(array $cartGroupIds): float
    {
        if ($cartGroupIds === [] || !Schema::hasTable('carts')) {
            return 0.0;
        }

        $value = 0.0;

        foreach ($this->checkedLines($cartGroupIds, ['carts.price', 'carts.discount', 'carts.quantity']) as $line) {
            $value += ((float) $line->price - (float) $line->discount) * (int) $line->quantity;
        }

        return round(max($value, 0.0), 3);
    }

    /**
     * The market these lines are sold from. A basket whose lines carry no market answers the default storefront, which is what every
     * basket of a one-country platform is.
     *
     * @param list<int|string> $cartGroupIds
     */
    private function sellingMarket(array $cartGroupIds): ?string
    {
        $default = StorefrontResolver::fromConfig()->defaultCountry();

        if ($cartGroupIds === [] || !Schema::hasTable('carts') || !Schema::hasColumn('products', StorefrontCatalog::COLUMN)) {
            return $default;
        }

        foreach ($this->checkedLines($cartGroupIds, ['products.' . StorefrontCatalog::COLUMN . ' as source']) as $line) {
            $market = strtoupper(trim((string) $line->source));
            if ($market !== '') {
                return $market;
            }
        }

        return $default;
    }

    /** @param list<int|string> $cartGroupIds */
    private function crossesABorder(array $cartGroupIds, ?string $destination): bool
    {
        $to = strtoupper(trim((string) $destination));

        return $to !== '' && $this->sellingMarket($cartGroupIds) !== $to;
    }

    /**
     * @param list<int|string> $cartGroupIds
     * @param list<string> $columns
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function checkedLines(array $cartGroupIds, array $columns): \Illuminate\Support\Collection
    {
        $query = DB::table('carts')
            ->join('products', 'products.id', '=', 'carts.product_id')
            ->whereIn('carts.cart_group_id', $cartGroupIds)
            ->where('carts.is_checked', 1);

        return StorefrontCatalog::restrictJoinedProducts(query: $query)->get($columns);
    }
}
