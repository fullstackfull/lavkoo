<?php

namespace App\Services\Storefront;

use Illuminate\Contracts\Session\Session;

/**
 * A visitor's market-dependent session state, filed under the storefront it was chosen in (A1-12).
 *
 * One browser session serves six markets. A coupon is issued for one market's currency and one market's catalogue, a currency is the
 * market's own, and a saved address is in one country — so the session keys that hold them say something only about the market the
 * visitor chose them in. Today there is one set of keys and one cookie at path `/`, so a coupon taken in Kuwait is still in the
 * session when the visitor walks into the Saudi storefront, and the Saudi checkout reads it.
 *
 * Filing rather than forgetting. On a move, each market's values are PUT AWAY under its own key and the new market's own values are
 * brought out (or the keys are left empty when it has none). A visitor who goes SA, KW and back to SA finds their Saudi basket as
 * they left it, which forgetting would not give them, and the market they are in never sees another market's choice either way.
 *
 * Nothing happens while storefronts are switched off: no key is written, nothing is filed, and the session behaves exactly as it
 * does today. That is asserted first in StorefrontSessionIsolationTest.
 *
 * WHAT IS NOT FILED is a decision, not an oversight, and `PLATFORM_WIDE` records the reason for each one. `guest_id` is the sharpest:
 * GCC-13 gives a visitor one identity across every market, and the guest cart of two countries merges into each country's cart at
 * login (StorefrontCartEdgesTest) only BECAUSE one guest id spans both. Filing it per storefront would mint a guest per market and
 * take that behaviour away.
 */
final class StorefrontSession
{
    /** Where the storefront a session's bare keys currently belong to is remembered. */
    public const FILED_UNDER = 'gcc_storefront_session_country';

    /** Where one market's put-away values live: `gcc_storefront_session.KW.coupon_code`. */
    public const FILE = 'gcc_storefront_session';

    /**
     * The keys that say something about ONE market only, grouped by the choice they belong to. A group moves together: a coupon code
     * without its discount, type, bearer and seller is a half-applied coupon, which is worse than none.
     *
     * @var array<string, list<string>>
     */
    public const PER_STOREFRONT = [
        // The applied coupon. OrderManager and the web checkout read all five, and `getTotalCouponAmount` prices against the market's own total.
        'coupon' => ['coupon_code', 'coupon_discount', 'coupon_type', 'coupon_bearer', 'coupon_seller_id'],
        // The currency the visitor is shown. Each market has its own (StorefrontContext::$currency), and a rate of one market's currency buys nothing in another.
        'currency' => ['currency_code', 'currency_symbol', 'currency_exchange_rate'],
        // Where the order is going and who is billed. An address is in one country; the saved list the id points into is the customer's, but the choice is the market's.
        'address' => ['address_id', 'billing_address_id'],
        // The cart being checked out: its group, the way it ships and the note on it. The cart rows are already per storefront (StorefrontCartScope), so these must be too.
        'cart' => ['cart_group_id', 'shipping_method_id', 'order_note'],
    ];

    /**
     * Keys this service deliberately leaves alone, and why. A reader who thinks one of these belongs in PER_STOREFRONT should read
     * the reason first; StorefrontSessionIsolationTest holds the guest-id one to a test.
     *
     * @var array<string, string>
     */
    public const PLATFORM_WIDE = [
        'guest_id' => 'GCC-13: one identity across every market. The two-country guest cart merges into each country\'s cart at login only because one guest id spans both.',
        'wish_list' => 'A list of product ids. The products are each one market\'s, so the LIST is filtered by what it is shown beside, not by where it is kept; moving it would also change the panel and theme readers.',
        'compare_list' => 'As the wish list.',
        'language' => 'A visitor reads Arabic or English in every market. MarketLanguage decides what a market OFFERS; the choice is the person\'s.',
        'local' => 'The locale, as above.',
        'customer_fcm_topic' => 'The push topic is derived per market where it is used (StorefrontPushTopics, A1-17); the session holds the device, not a market.',
    ];

    public function __construct(private readonly Session $session)
    {
    }

    /** Every key that belongs to one market. @return list<string> */
    public static function keys(): array
    {
        return array_merge(...array_values(self::PER_STOREFRONT));
    }

    /**
     * Brings the given storefront's own market-dependent state into the session, after putting away whatever market's state is in it.
     *
     * Does nothing at all while storefronts are off, and nothing on a request that stays in the storefront the session is already
     * filed under — the common case, which must cost a read and no write.
     *
     * @return bool whether anything was moved
     */
    public function serve(string $country): bool
    {
        if (!StorefrontResolver::fromConfig()->isEnabled()) {
            return false;
        }

        $filedUnder = (string) $this->session->get(self::FILED_UNDER, '');

        if ($filedUnder === $country) {
            return false;
        }

        if ($filedUnder === '') {
            // NOTHING HAS EVER BEEN FILED, so the market-dependent keys in hand are the ones this visitor arrived with and they belong
            // to the market being served right now. Adopt them: write down which market they are filed under and bring nothing out.
            //
            // The alternative is what this did at first, and it was a Saudi behaviour change: `bringOut()` FORGETS every key the
            // filed bucket does not carry, and a first bucket is empty, so the first website request of a visitor who already held a
            // currency pick threw that pick away -- and the default market's cash order then stored no currency where it had always
            // stored the shopper's own. Three test classes said so (`StorefrontCodOrderCurrencyTest`,
            // `StorefrontPurchaseCurrencyTest`, `StorefrontPaymentMethodCheckoutTest`), and they were right.
            //
            // A1-12's rule is untouched by this. What must not cross a border is a choice made in ANOTHER market, and that still
            // cannot: the next change of market puts this market's keys away and brings the other's out, so a Kuwaiti pick is only
            // ever in Kuwait's file. What is adopted here was never another market's -- it is what the visitor was already carrying
            // before this platform had markets at all.
            $this->session->put(self::FILED_UNDER, $country);

            return false;
        }

        $this->putAway($filedUnder);
        $this->bringOut($country);
        $this->session->put(self::FILED_UNDER, $country);

        return true;
    }

    /** What is filed for one market, as it was put away. @return array<string, mixed> */
    public function filedFor(string $country): array
    {
        $filed = $this->session->get(self::FILE . '.' . $country, []);

        return is_array($filed) ? $filed : [];
    }

    private function putAway(string $country): void
    {
        $values = [];

        foreach (self::keys() as $key) {
            if ($this->session->has($key)) {
                $values[$key] = $this->session->get($key);
            }
        }

        $values === []
            ? $this->session->forget(self::FILE . '.' . $country)
            : $this->session->put(self::FILE . '.' . $country, $values);
    }

    private function bringOut(string $country): void
    {
        $filed = $this->filedFor($country);

        foreach (self::keys() as $key) {
            array_key_exists($key, $filed) ? $this->session->put($key, $filed[$key]) : $this->session->forget($key);
        }

        $this->session->forget(self::FILE . '.' . $country);
    }
}
