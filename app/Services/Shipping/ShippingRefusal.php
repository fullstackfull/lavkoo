<?php

namespace App\Services\Shipping;

use ReflectionClass;

/**
 * Every reason this platform refuses to ship something, in one place (H1-03, H1-04, H1-06).
 *
 * The engine answers in translation keys rather than sentences, which is what lets the website, the three REST paths, the POS and both
 * apps show one decision in the customer's own language. Scattered as literals, that vocabulary has no edges: nobody can say what the
 * complete list is, a new reason reaches a customer as its own key because nobody wrote the Arabic, and two spellings of the same refusal
 * drift apart.
 *
 * So the reasons are named here, the classes that refuse use these names, and
 * {@see \Tests\Feature\Storefront\ShippingRefusalTextTest} reads {@see all()} to check that each one reads in Arabic and in English. A
 * reason added without its text fails that test on the day it is added.
 */
final class ShippingRefusal
{
    /** Nobody said where this is going, or the country is not one this platform knows. */
    public const DESTINATION_UNKNOWN = 'shipping_destination_unknown';

    /** H1-03: no permission record for this product and destination at all, which is a refusal and not a gap. */
    public const NOT_PERMITTED_ABROAD = 'shipping_product_not_permitted_abroad';

    /** H1-03: a record exists and withholds one of the three permissions a border asks about. */
    public const MAY_NOT_SHIP = 'shipping_product_may_not_ship';
    public const MAY_NOT_BE_EXPORTED = 'shipping_product_may_not_be_exported';
    public const MAY_NOT_BE_IMPORTED = 'shipping_product_may_not_be_imported';

    /** H1-02: no carrier declares the route, and an undeclared route is not served. */
    public const NO_CARRIER_SERVES_THIS_ROUTE = 'shipping_no_carrier_serves_this_route';

    /** H1-04: a regulated product with no licence on file for that country. */
    public const REGULATED_NEEDS_CLEARANCE = 'shipping_regulated_product_needs_a_recorded_clearance';

    /** H1-04: nobody classified this product, so it cannot be judged against the owner's lists. */
    public const REGULATED_CLASSIFICATION_MISSING = 'shipping_regulated_classification_missing';

    /** H1-04: a reference is on file, recorded by somebody who was not entitled to record it. */
    public const CLEARANCE_NOT_PERMITTED = 'shipping_clearance_was_not_recorded_by_an_authorised_person';

    /** H1-09: nobody has written a duty rule for this route, and a duty that cannot be determined is not a duty of zero. */
    public const DUTY_NOT_QUOTED = 'shipping_duty_could_not_be_determined';

    /**
     * The whole vocabulary, read from the constants above so the list cannot fall behind them.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return array_values(array_map('strval', (new ReflectionClass(self::class))->getConstants()));
    }
}
