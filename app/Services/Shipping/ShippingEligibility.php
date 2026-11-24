<?php

namespace App\Services\Shipping;

use App\Services\Fulfillment\Support\ProviderRouteSupport;
use App\Services\Storefront\StorefrontCatalog;
use App\Services\Storefront\StorefrontResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The one service that answers whether a basket may be shipped to a destination, and whether doing so crosses a border (H1-06).
 *
 * Before this, each surface asked its own question: the website and the API each checked the platform's global country list, the dispatch
 * preflight checked an address and a phone, and nothing anywhere compared the destination with what a product is permitted to do or what a
 * carrier is contracted to carry. Three surfaces with three answers is how a customer is refused at one and accepted at another.
 *
 * It composes the two facts that decide it, and it FAILS CLOSED on both:
 *
 *  - {@see ProductDestinationPermission} -- may this product go there at all. No record means no.
 *  - {@see ProviderRouteSupport} -- does the carrier of this line serve that route. An undeclared route is not served.
 *
 * **With the switch off it answers `local` and allowed, always**, so every surface behaves exactly as it does today and the Saudi platform
 * is untouched. That is not a shortcut: a platform serving one country has no cross-border question to answer, and the switch is what says
 * a second country is open.
 *
 * A till sale has no destination other than the counter it is sold at, which is inside its own market: `decide` is asked about a delivery
 * address, and a POS sale has none, so the answer for one is always local. That is the reading of I2-09's rule that a till sale follows the
 * country of its products.
 */
final class ShippingEligibility
{
    public static function isOn(): bool
    {
        return (bool) config('gcc.shipping.cross_border.enabled');
    }

    /**
     * The decision for these checked cart groups and this destination country.
     *
     * @param list<int|string> $cartGroupIds the cart groups that will become orders
     */
    public function decide(array $cartGroupIds, ?string $destination): EligibilityDecision
    {
        if (!self::isOn()) {
            return EligibilityDecision::local();
        }

        $to = self::country($destination);

        if ($to === null) {
            return EligibilityDecision::refused(kind: EligibilityDecision::LOCAL, reasons: ['shipping_destination_unknown']);
        }

        $lines = $this->linesOf($cartGroupIds);

        if ($lines === []) {
            return EligibilityDecision::local();
        }

        $reasons = [];
        $crossesABorder = false;

        foreach ($lines as $line) {
            $source = self::country($line->source) ?? StorefrontResolver::fromConfig()->defaultCountry();

            if ($source === $to) {
                continue;
            }

            $crossesABorder = true;

            $refusal = ProductDestinationPermission::refusalFor(productId: $line->product_id, source: $source, destination: $to);
            if ($refusal !== null) {
                $reasons[] = $refusal;
            }

            if ($line->provider !== null && !ProviderRouteSupport::serves((string) $line->provider, $source, $to)) {
                $reasons[] = 'shipping_no_carrier_serves_this_route';
            }
        }

        $kind = $crossesABorder ? EligibilityDecision::CROSS_BORDER : EligibilityDecision::LOCAL;

        return $reasons === [] ? new EligibilityDecision(kind: $kind, allowed: true) : EligibilityDecision::refused(kind: $kind, reasons: $reasons);
    }

    /**
     * The checked lines of these cart groups: the product, the market it belongs to, and the carrier that would take it.
     *
     * Read through StorefrontCatalog like every other cart read, so a request narrowed to one market sees that market's lines and no
     * others. A shop whose products carry no market yet answers the default market for every line, which is what it is.
     *
     * @param list<int|string> $cartGroupIds
     * @return list<object{product_id: int, source: ?string, provider: ?string}>
     */
    private function linesOf(array $cartGroupIds): array
    {
        if ($cartGroupIds === [] || !Schema::hasTable('carts')) {
            return [];
        }

        $hasMarket = Schema::hasColumn('products', StorefrontCatalog::COLUMN);
        $hasProvider = Schema::hasColumn('products', 'fulfillment_provider');

        $query = DB::table('carts')
            ->join('products', 'products.id', '=', 'carts.product_id')
            ->whereIn('carts.cart_group_id', $cartGroupIds)
            ->where('carts.is_checked', 1);

        $columns = ['carts.product_id as product_id'];
        $columns[] = $hasMarket ? 'products.' . StorefrontCatalog::COLUMN . ' as source' : DB::raw('NULL as source');
        $columns[] = $hasProvider ? 'products.fulfillment_provider as provider' : DB::raw('NULL as provider');

        return StorefrontCatalog::restrictJoinedProducts(query: $query)->get($columns)->all();
    }

    private static function country(?string $code): ?string
    {
        $country = strtoupper(trim((string) $code));

        return $country === '' ? null : $country;
    }
}
