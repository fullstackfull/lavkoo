<?php

namespace App\Services\Analytics;

/**
 * One thing a person did.
 *
 * Deliberately a value object rather than an array: every event in the system is built through
 * this constructor, so there is exactly one place where a name is normalised, a value is coerced
 * and properties are checked — and adding a new event anywhere in the codebase cannot invent a
 * seventh spelling of "add to cart".
 */
final class AnalyticsEvent
{
    // Page and navigation.
    public const PAGE_VIEWED = 'page_viewed';

    // Catalogue.
    public const PRODUCT_VIEWED = 'product_viewed';
    public const PRODUCT_LIST_VIEWED = 'product_list_viewed';
    public const CATEGORY_VIEWED = 'category_viewed';
    public const SHOP_VIEWED = 'shop_viewed';
    public const BRAND_VIEWED = 'brand_viewed';
    public const SEARCH_PERFORMED = 'search_performed';
    public const SEARCH_NO_RESULTS = 'search_no_results';

    // Cart and wishlist.
    public const CART_ADDED = 'cart_added';
    public const CART_REMOVED = 'cart_removed';
    public const CART_UPDATED = 'cart_updated';
    public const CART_VIEWED = 'cart_viewed';
    public const WISHLIST_ADDED = 'wishlist_added';
    public const COMPARE_ADDED = 'compare_added';

    // Checkout.
    public const CHECKOUT_STARTED = 'checkout_started';
    public const CHECKOUT_ADDRESS = 'checkout_address_set';
    public const CHECKOUT_SHIPPING = 'checkout_shipping_set';
    public const CHECKOUT_PAYMENT = 'checkout_payment_selected';
    public const COUPON_APPLIED = 'coupon_applied';
    public const COUPON_REJECTED = 'coupon_rejected';

    // Money.
    public const PAYMENT_STARTED = 'payment_started';
    public const PAYMENT_SUCCEEDED = 'payment_succeeded';
    public const PAYMENT_FAILED = 'payment_failed';
    public const ORDER_PLACED = 'order_placed';
    public const ORDER_CANCELLED = 'order_cancelled';
    public const ORDER_DELIVERED = 'order_delivered';
    public const REFUND_REQUESTED = 'refund_requested';

    // Account.
    public const SIGNED_UP = 'signed_up';
    public const SIGNED_IN = 'signed_in';
    public const REVIEW_SUBMITTED = 'review_submitted';

    // Campaign.
    public const CAMPAIGN_CLICKED = 'campaign_clicked';

    // Merchandising: what the merchant placed, and whether anyone took it.
    public const BANNER_CLICKED = 'banner_clicked';
    /**
     * A composed section actually reaching a shopper's screen.
     *
     * The builder answers "what did I arrange"; nothing answered "did anyone get that far". A
     * section below the fold that no one scrolls to looks identical, in every report there was, to
     * one at the top that everybody sees — and the difference is the whole reason to reorder a
     * page. Counted once per section per page view, on both clients.
     */
    public const SECTION_VIEWED = 'section_viewed';

    // Seller (marketplace suite). Recorded in the services, so the vendor panel
    // and the seller app produce the same rows.
    public const PAYOUT_REQUESTED = 'payout_requested';
    public const KYC_SUBMITTED = 'kyc_submitted';

    /** Which family each event belongs to, for the reports that group by area. */
    private const CATEGORIES = [
        self::PAGE_VIEWED => 'page',
        self::PRODUCT_VIEWED => 'catalogue',
        self::PRODUCT_LIST_VIEWED => 'catalogue',
        self::CATEGORY_VIEWED => 'catalogue',
        self::SHOP_VIEWED => 'catalogue',
        self::BRAND_VIEWED => 'catalogue',
        self::SEARCH_PERFORMED => 'search',
        self::SEARCH_NO_RESULTS => 'search',
        self::CART_ADDED => 'cart',
        self::CART_REMOVED => 'cart',
        self::CART_UPDATED => 'cart',
        self::CART_VIEWED => 'cart',
        self::WISHLIST_ADDED => 'cart',
        self::COMPARE_ADDED => 'cart',
        self::CHECKOUT_STARTED => 'checkout',
        self::CHECKOUT_ADDRESS => 'checkout',
        self::CHECKOUT_SHIPPING => 'checkout',
        self::CHECKOUT_PAYMENT => 'checkout',
        self::COUPON_APPLIED => 'checkout',
        self::COUPON_REJECTED => 'checkout',
        self::PAYMENT_STARTED => 'payment',
        self::PAYMENT_SUCCEEDED => 'payment',
        self::PAYMENT_FAILED => 'payment',
        self::ORDER_PLACED => 'order',
        self::ORDER_CANCELLED => 'order',
        self::ORDER_DELIVERED => 'order',
        self::REFUND_REQUESTED => 'order',
        self::SIGNED_UP => 'account',
        self::SIGNED_IN => 'account',
        self::REVIEW_SUBMITTED => 'account',
        self::CAMPAIGN_CLICKED => 'campaign',
        self::BANNER_CLICKED => 'merchandising',
        self::SECTION_VIEWED => 'merchandising',
        self::PAYOUT_REQUESTED => 'seller',
        self::KYC_SUBMITTED => 'seller',
    ];

    /**
     * The events a browser is allowed to send: ONLY those the server cannot see for itself.
     *
     * Two rules decide this list, and the second one is easy to get wrong.
     *
     * First, nothing money-related is ever accepted from a client. A page that could post
     * "order_placed" with a value of its choosing would make revenue analytics a suggestion box.
     *
     * Second — and this is the one that silently corrupts numbers rather than obviously breaking
     * them — an event the server ALREADY records must not be accepted from the browser too. A
     * product view is recorded by ProductDetailsController, a wishlist add by the Wishlist model
     * event, a checkout start by WebController. Accepting those here would count each of them
     * twice, and the deduplication would not catch it: the two paths disagree about the path
     * (a normalised route pattern against the browser's actual URL), so they hash differently.
     *
     * What is left is one thing: the product filter's pushState navigations, which return JSON and
     * are correctly not counted as pageviews.
     *
     * This list used to also carry cart_viewed and compare_added, on the belief that neither had a
     * server-side equivalent. Neither could ever fire — no element in the theme carries the
     * data-analytics attribute that would have sent them — so both were an allow-list entry for an
     * event that did not exist, and the cart step of every funnel was empty for a reason no screen
     * could show. Both are recorded on the server now, where they always belonged: the cart page is
     * a page load the server serves, and a compare add is a row the server writes.
     */
    private const CLIENT_ALLOWED = [
        self::PRODUCT_LIST_VIEWED,
        // A banner click is a link being followed, and a link the server cannot see: the page it
        // leads to is recorded as an ordinary pageview with nothing to say it came from a banner,
        // and in the app there is no navigation at all. Whoever draws the banner is the only
        // witness, so both clients report it — the web from its beacon, the app from its own
        // ingest — and neither can double-count the other because neither is the server.
        self::BANNER_CLICKED,
        // Only the client that drew the section knows whether it came into view. The server sees
        // the page and has no idea how far down it anyone scrolled.
        self::SECTION_VIEWED,
    ];

    public function __construct(
        public readonly string $name,
        public readonly ?string $entityType = null,
        public readonly ?string $entityId = null,
        public readonly ?int $vendorId = null,
        public readonly ?float $value = null,
        public readonly ?string $currency = null,
        public readonly ?int $quantity = null,
        public readonly array $properties = [],
        public readonly ?string $dedupeKey = null,
        public readonly ?string $path = null,
    ) {
    }

    public function category(): string
    {
        return self::CATEGORIES[$this->name] ?? 'other';
    }

    /**
     * Whether this event describes the shop's own business rather than a visitor's browsing.
     *
     * The distinction decides whether `is_internal` may exclude it. "Internal" means the merchant's
     * own eleven visits a day should not be counted as customer traffic — a real filter, and the
     * reason a small shop's conversion rate does not look like a rounding error. But a payout
     * request and a KYC submission are only ever raised while a seller is signed in, so every one
     * of them was written with is_internal = 1 and dropped by every rollup that reads the table:
     * two events in the catalogue, emitted correctly, and permanently uncountable.
     */
    public function isSellerDomain(): bool
    {
        return (self::CATEGORIES[$this->name] ?? null) === 'seller';
    }

    public static function isKnown(string $name): bool
    {
        return isset(self::CATEGORIES[$name]);
    }

    public static function isClientAllowed(string $name): bool
    {
        return in_array($name, self::CLIENT_ALLOWED, true);
    }

    /** @return array<int, string> */
    public static function names(): array
    {
        return array_keys(self::CATEGORIES);
    }
}
