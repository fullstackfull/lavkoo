<?php

namespace App\Services\Catalog;

use App\Services\Storefront\ProductCountry;

/**
 * What a product form pre-fills when it is being used to CREATE a product out of another product's page (B-02, B-07).
 *
 * The product gallery's "use this product info" opens the EDIT form of an existing product with `product-gallery=1`, which turns its action
 * into the ADD route: so the page is pre-filled from a source product and submitted as a new one. That is a convenience on a one-market
 * platform. On six markets it is a defect, because the same form now carries a country SELECT -- the partial
 * `admin-views.product.partials._storefront-country` renders a chooser rather than a read-only label precisely when that flag is set -- so an
 * administrator can take a Saudi product's page, choose Kuwait and save.
 *
 * What was then saved is the Saudi figures in a Kuwaiti row. An amount typed in that form is stored AS TYPED in the chosen country's currency
 * (GCC-05 slice 4), so a product priced at 100 riyals became a product priced at 100 dinars -- about twelve times its price -- and its stock
 * figure was the Saudi warehouse's quantity in a market that holds none of it.
 *
 * So while countries are tracked, a form that is creating a product carries what the product IS and none of its figures: no unit price, no
 * discount, no shipping cost, no stock, and no variant price or quantity. That is the rule {@see ProductCopyToCountry} already follows for the
 * deliberate copy-to-country action, and the two paths now agree -- a price is in one country's currency and a stock figure is in one
 * country's warehouse, so neither crosses a border by being pre-filled.
 *
 * While countries are NOT tracked -- storefronts off, or before the migration -- the gallery pre-fills exactly what it always did. There is
 * one market, a figure cannot belong to another one, and the convenience is the whole point of the feature.
 */
final class ProductFormPrefill
{
    /** The flag the gallery link puts on the edit URL to turn the page into an add form. */
    public const FROM_THE_GALLERY = 'product-gallery';

    /**
     * Whether this form may pre-fill the source product's own figures.
     *
     * Two conditions, and both are needed: the form is creating a product rather than editing one, and a figure can belong to a country at
     * all. Either missing and the form is the form it has always been.
     */
    public function carriesTheSourcesFigures(): bool
    {
        return !request()?->boolean(self::FROM_THE_GALLERY) || !ProductCountry::isTracked();
    }

    /** An amount of money as the form should show it: converted for display as before, or nothing to carry across a border. */
    public function amount(mixed $storedAmount): float|int|string
    {
        return $this->carriesTheSourcesFigures() ? usdToDefaultCurrency($storedAmount) : 0;
    }

    /** A figure that is not money but still belongs to one country: a stock quantity, or a discount the copy must not inherit. */
    public function figure(mixed $storedFigure): float|int|string
    {
        return $this->carriesTheSourcesFigures() ? $storedFigure : 0;
    }
}
