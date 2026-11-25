<?php

namespace App\Services\Shipping;

use App\Services\Fulfillment\ProviderCityMappingService;
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
 *  - {@see RegulatedShippingGate} -- is this a regulated or unclassified product crossing a border without a recorded clearance. A
 *    carrier that covers the route never overrides it, which is why it is asked separately and not behind the carrier check.
 *  - {@see CustomsDuty} -- can the duty on this route be determined at all. Only while duty is `strict`: a route nobody has written a
 *    rule for is refused rather than charged zero, because a zero is an answer and nobody may give it without a rule.
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
    public function decide(array $cartGroupIds, ?string $destination, ?int $cityId = null): EligibilityDecision
    {
        if (!self::isOn()) {
            return EligibilityDecision::local();
        }

        return $this->decideFor(lines: $this->linesOf($cartGroupIds), destination: $destination, cityId: $cityId);
    }

    /**
     * The decision itself, from lines and a destination, whichever of the two entry points read them.
     *
     * @param list<object> $lines
     */
    private function decideFor(array $lines, ?string $destination, ?int $cityId = null): EligibilityDecision
    {
        $to = self::country($destination);

        if ($to === null) {
            return EligibilityDecision::refused(kind: EligibilityDecision::LOCAL, reasons: [ShippingRefusal::DESTINATION_UNKNOWN]);
        }

        if ($lines === []) {
            return EligibilityDecision::local();
        }

        $reasons = [];
        $crossesABorder = false;
        $gate = new RegulatedShippingGate();

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

            $regulated = $gate->refusalFor(productId: $line->product_id, source: $source, destination: $to);
            if ($regulated !== null) {
                $reasons[] = $regulated;
            }

            if ($line->provider !== null && !ProviderRouteSupport::serves((string) $line->provider, $source, $to)) {
                $reasons[] = ShippingRefusal::NO_CARRIER_SERVES_THIS_ROUTE;
            }

            if (!$this->cityIsServed(provider: $line->provider, cityId: $cityId)) {
                $reasons[] = ShippingRefusal::NO_CARRIER_SERVES_THIS_CITY;
            }

            // H1-09's own rule, asked before payment: a route whose duty cannot be determined refuses the order and never charges zero.
            // It is a question about the ROUTE and not about the basket's total, which is why it can be asked here at all -- the amount is
            // computed where the order's value is known. Only `strict` refuses; `declare` records the quote and lets the order through.
            if (CustomsDuty::mode() === CustomsDuty::MODE_STRICT && !CustomsDuty::canQuote($source, $to)) {
                $reasons[] = ShippingRefusal::DUTY_NOT_QUOTED;
            }
        }

        $kind = $crossesABorder ? EligibilityDecision::CROSS_BORDER : EligibilityDecision::LOCAL;

        return $reasons === [] ? new EligibilityDecision(kind: $kind, allowed: true) : EligibilityDecision::refused(kind: $kind, reasons: $reasons);
    }

    /**
     * Whether the carrier of this line can actually deliver to the destination CITY (H1-05).
     *
     * The dispatcher has always known this -- it holds a shipment with `hold_city_unmapped` when the provider has no approved mapping for
     * the city -- but it knew it after the customer had paid. A city a carrier cannot reach is a fact available at checkout, and refusing
     * there is the difference between a customer choosing another address and a customer waiting for a parcel that was never going to move.
     *
     * It answers "served" wherever it cannot judge, which is the only honest answer in those cases and keeps every shop unchanged: a line
     * with no carrier, an address with no city id -- which is every free-text address until H1-10 gives the other markets structure -- and
     * a shop whose address and mapping tables have not been migrated.
     */
    private function cityIsServed(?string $provider, ?int $cityId): bool
    {
        if ($provider === null || $cityId === null) {
            return true;
        }

        $mappings = app(ProviderCityMappingService::class);

        return !$mappings->available() || $mappings->approvedFor($provider, $cityId) !== null;
    }

    /**
     * The decision for an ORDER, from the order's own facts: the markets of the products on its lines, and the country of the address it
     * carries.
     *
     * The same question the checkout asked, asked again of what the order actually holds. It is what records the decision on the order, and
     * what the dispatch side asks when it re-checks a shipment that has been waiting: a permission withdrawn, or a carrier that stopped
     * serving a route, must stop a parcel that has not left yet.
     */
    public function decideForOrder(int|string|null $orderId): EligibilityDecision
    {
        if (!self::isOn() || $orderId === null) {
            return EligibilityDecision::local();
        }

        return $this->decideFor(
            lines: $this->orderLinesOf($orderId),
            destination: self::destinationOfOrder($orderId),
            cityId: self::cityOfOrder($orderId),
        );
    }

    /**
     * The lines of an order: the product, the market it belongs to, and the carrier that would take it. Read from the order's own rows,
     * never from a cart, because a cart is gone by the time a shipment is dispatched.
     *
     * @return list<object>
     */
    private function orderLinesOf(int|string $orderId): array
    {
        if (!Schema::hasTable('order_details')) {
            return [];
        }

        $hasMarket = Schema::hasColumn('products', StorefrontCatalog::COLUMN);
        $hasProvider = Schema::hasColumn('products', 'fulfillment_provider');

        $columns = ['order_details.product_id as product_id'];
        $columns[] = $hasMarket ? 'products.' . StorefrontCatalog::COLUMN . ' as source' : DB::raw('NULL as source');
        $columns[] = $hasProvider ? 'products.fulfillment_provider as provider' : DB::raw('NULL as provider');

        return DB::table('order_details')
            ->join('products', 'products.id', '=', 'order_details.product_id')
            ->where('order_details.order_id', $orderId)
            ->get($columns)
            ->all();
    }

    /** The country the order is going to, out of the address copy the order keeps, which is the address as it was that day. */
    private static function destinationOfOrder(int|string $orderId): ?string
    {
        $address = self::addressOfOrder($orderId);

        return $address === null ? null : self::country($address['country'] ?? null);
    }

    /** The city of that same copy, where it has one. A free-text address has none, and then the carrier's coverage cannot be judged. */
    private static function cityOfOrder(int|string $orderId): ?int
    {
        $cityId = self::addressOfOrder($orderId)['city_id'] ?? null;

        return is_numeric($cityId) && (int) $cityId > 0 ? (int) $cityId : null;
    }

    /** @return array<string, mixed>|null the address as it was on the day the order was placed */
    private static function addressOfOrder(int|string $orderId): ?array
    {
        $stored = DB::table('orders')->where('id', $orderId)->value('shipping_address_data');
        $address = is_string($stored) ? json_decode($stored, associative: true) : null;

        return is_array($address) ? $address : null;
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
