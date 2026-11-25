<?php

namespace App\Services\Storefront;

/**
 * The push topics that belong to ONE market (K-11, K-V4).
 *
 * A platform with one market needs one topic per audience, and that is what this platform has always had:
 * `maintenance_mode_start_vendor` reaches every seller. With six markets that is wrong in both directions -- a maintenance of Kuwait tells
 * the Saudi sellers to stop working, and a Saudi seller who also works in Kuwait is told nothing about the market they are looking at.
 *
 * So a market gets a topic of its own, named by appending its code to the platform-wide one. The name is built in ONE place because two sides
 * have to agree on it exactly: the server subscribes nothing and publishes here, the app subscribes and never publishes, and a mismatch is
 * silent -- the message goes nowhere and nobody is told.
 *
 * **The platform-wide topics are not replaced and not removed.** They still carry what is true of the whole platform, and a device that has
 * only ever subscribed to them keeps receiving it: an app build older than the market topics is not cut off by a server that has them.
 * Whether the unused `lavkoo_seller` topic should be deleted is the owner's Q-44, and nothing here answers it.
 *
 * A code that is not a two-letter market gets no topic at all, rather than a topic named after a typo that nothing would ever publish to.
 */
final class StorefrontPushTopics
{
    /** The platform-wide seller maintenance topic, which every seller's device subscribes to and which this does not replace. */
    public const SELLER_MAINTENANCE = 'maintenance_mode_start_vendor';

    /** The platform-wide customer maintenance topic. */
    public const CUSTOMER_MAINTENANCE = 'maintenance_mode_start_user_app';

    /** The seller maintenance topic of one market, or null while storefronts are off or the code is not a market. */
    public static function sellerMaintenance(?string $country): ?string
    {
        return self::ofMarket(base: self::SELLER_MAINTENANCE, country: $country);
    }

    /** The customer maintenance topic of one market, or null while storefronts are off or the code is not a market. */
    public static function customerMaintenance(?string $country): ?string
    {
        return self::ofMarket(base: self::CUSTOMER_MAINTENANCE, country: $country);
    }

    private static function ofMarket(string $base, ?string $country): ?string
    {
        if (!(bool) config('gcc.storefronts.enabled', false)) {
            return null;
        }

        $code = strtoupper(trim((string) $country));

        return preg_match('/^[A-Z]{2}$/', $code) === 1 ? $base . '_' . $code : null;
    }
}
