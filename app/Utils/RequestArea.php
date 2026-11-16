<?php

namespace App\Utils;

/**
 * Which kind of request is running, decided on the segments of its path and nowhere else.
 *
 * A substring test on the address (the scheme and host included) takes a store installed in /vendor-store, a product called seller-bag or
 * a page such as /vendor-shop for a panel. Request::is() is anchored, but it matches a regular expression with the u flag against the
 * decoded path, which fails for a path that is not valid UTF-8 (/admin/search/caf%E9) and then matches nothing. Splitting the decoded path
 * into segments does neither.
 */
class RequestArea
{
    /** The first segment of every panel. */
    public const PANEL_ROOTS = ['admin', 'seller', 'vendor'];

    /** The versions of the API under /api that the seller apps use. The customer API, /api/v1, is not among them. */
    public const SELLER_API_VERSIONS = ['v2', 'v3'];

    /** A public page that lives under the vendor panel's address and is drawn with the storefront layout. */
    private const VENDOR_REGISTRATION = ['vendor', 'auth', 'registration'];

    /** The panels and the seller APIs: what they show is for the people who run the store, so it is not limited to what a shopper may see. */
    public static function isPanel(): bool
    {
        $segments = request()->segments();

        if (array_slice(array: $segments, offset: 0, length: count(self::VENDOR_REGISTRATION)) === self::VENDOR_REGISTRATION) {
            return false;
        }

        $root = $segments[0] ?? null;

        return in_array(needle: $root, haystack: self::PANEL_ROOTS, strict: true)
            || ($root === 'api' && in_array(needle: $segments[1] ?? null, haystack: self::SELLER_API_VERSIONS, strict: true));
    }
}
