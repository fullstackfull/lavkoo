<?php

namespace App\Services\Theme;

use App\Models\Product;
use App\Models\Shop;

/**
 * A destination, chosen rather than typed.
 *
 * The builder stores every link as a storefront URL, and until now a merchant produced that URL by
 * hand: copy the address bar, paste it into a text box, hope. It is the single most fragile input
 * in the whole builder, and it is the one that decides where a banner goes. A trailing slash, a
 * query parameter in the wrong order, a link copied from an admin page rather than a shop page —
 * each produces a link that works on the web and opens the wrong screen, or a browser, on a phone.
 *
 * This is the other direction from {@see ActionResolver}. That one reads a URL and says what it
 * means; this takes what the merchant meant — this category, that brand, the best sellers — and
 * writes the URL that means it. The stored value stays a URL, so every renderer, the storefront and
 * the app's typed actions keep working with no migration and no new column.
 *
 * The contract between the two is a round trip: whatever this composes, the resolver must read back
 * as the same destination. A test holds that for every kind, because the pair silently disagreeing
 * would put the builder back where it started, with a nicer control.
 */
class LinkComposer
{
    /** Destination kinds a merchant picks a subject for. */
    public const SUBJECT_KINDS = ['product', 'category', 'brand', 'vendor', 'campaign'];

    /**
     * Named storefront list pages, in the order a merchant thinks of them.
     *
     * Keys are what the control stores as the chosen collection; values are the storefront path.
     * Deliberately the same set {@see ActionResolver::COLLECTIONS} reads back, because a collection
     * this can write and that cannot name would resolve to a plain external URL on a phone.
     */
    public const COLLECTIONS = [
        'all'            => '/products',
        'featured'       => '/featured-products',
        'latest'         => '/latest-products',
        'best_selling'   => '/best-selling-products',
        'top_rated'      => '/top-rated-products',
        'discounted'     => '/discounted-products',
        'clearance'      => '/clearance-sale-products',
        'featured_deal'  => '/featured-deal-products',
        'most_favorite'  => '/most-favorite-products',
        'all_categories' => '/categories',
        'all_brands'     => '/brands',
        'all_vendors'    => '/vendors',
    ];

    /** Destinations that are one fixed screen with nothing to choose. */
    public const FIXED = [
        'cart'     => '/shop-cart',
        'wishlist' => '/wishlists',
    ];

    public function __construct(private readonly ActionResolver $actions)
    {
    }

    /**
     * The storefront URL for a chosen destination, or null when it names nothing.
     *
     * @param  string  $kind       one of SUBJECT_KINDS, 'collection', 'search', 'cart', 'wishlist', 'url', 'none'
     * @param  mixed   $reference  the subject's id, the collection name, the search term, or a raw URL
     */
    public function compose(string $kind, mixed $reference = null): ?string
    {
        $path = match ($kind) {
            'product'  => $this->subjectPath(Product::class, $reference, '/product/'),
            'vendor'   => $this->subjectPath(Shop::class, $reference, '/vendor-shop/'),

            // A filtered catalogue rather than a slug page: this is the shape the admin's own banner
            // form stores, and the shape the app reads back as the category itself rather than as
            // the whole catalogue.
            'category' => $this->filtered('category', $reference),
            'brand'    => $this->filtered('brand', $reference),

            'campaign' => $this->identified('/flash-deals/', $reference),

            'collection' => self::COLLECTIONS[is_string($reference) ? $reference : ''] ?? null,

            // A page the merchant composed. Stored by slug rather than by id: the slug is what
            // both clients ask for, and a page restored from an export keeps its slug and not its
            // row number.
            'page' => is_string($reference) && trim($reference) !== ''
                ? '/p/' . trim($reference)
                : null,
            'cart', 'wishlist' => self::FIXED[$kind],

            'search' => is_string($reference) && trim($reference) !== ''
                ? '/searched-products?' . http_build_query(['name' => trim($reference)])
                : null,

            // Anything the merchant typed themselves, kept exactly as typed — an external link is a
            // real destination, and rewriting it would break the one case nothing else covers.
            'url' => is_string($reference) && trim($reference) !== '' ? trim($reference) : null,

            default => null,
        };

        if ($path === null) {
            return null;
        }

        return str_starts_with($path, 'http') ? $path : url($path);
    }

    /**
     * What a stored link means, in the terms the control offers.
     *
     * Read through {@see ActionResolver} rather than parsed again here: the control must show the
     * destination the SHOPPER will get, and a second parser would eventually disagree with the
     * first about some URL nobody thought to test.
     *
     * @return array{kind: string, reference: mixed, label: ?string}
     */
    public function describe(?string $link): array
    {
        if (!is_string($link) || trim($link) === '') {
            return ['kind' => 'none', 'reference' => null, 'label' => null];
        }

        $action = $this->actions->resolve($link);

        return match ($action['type']) {
            ActionResolver::CATEGORY, ActionResolver::BRAND => [
                'kind'      => $action['type'],
                'reference' => $action['id'] ?? null,
                'label'     => $action['label'] ?? $action['slug'] ?? null,
            ],
            ActionResolver::PRODUCT => $this->bySlug(Product::class, 'product', $action['slug'] ?? null),
            ActionResolver::VENDOR  => $this->bySlug(Shop::class, 'vendor', $action['slug'] ?? null),
            ActionResolver::CAMPAIGN => [
                'kind' => 'campaign', 'reference' => $action['id'] ?? null, 'label' => null,
            ],
            ActionResolver::COLLECTION => [
                'kind' => 'collection', 'reference' => $action['collection'] ?? null, 'label' => null,
            ],
            ActionResolver::PAGE => [
                'kind' => 'page', 'reference' => $action['slug'] ?? null, 'label' => $action['slug'] ?? null,
            ],
            ActionResolver::SEARCH => [
                'kind' => 'search', 'reference' => $action['query'] ?? '', 'label' => null,
            ],
            ActionResolver::CART, ActionResolver::WISHLIST => [
                'kind' => $action['type'], 'reference' => null, 'label' => null,
            ],
            ActionResolver::NONE => ['kind' => 'none', 'reference' => null, 'label' => null],

            // A link this build cannot name is still a link. Showing it as a URL the merchant can
            // read and edit beats showing them an empty control that silently discards it on save.
            default => ['kind' => 'url', 'reference' => $link, 'label' => null],
        };
    }

    // ---------------------------------------------------------------------------------------

    /** `/products?category_id=44&data_from=category` — the filtered list, not the slug page. */
    private function filtered(string $subject, mixed $reference): ?string
    {
        $id = is_numeric($reference) ? (int) $reference : 0;

        if ($id < 1) {
            return null;
        }

        return '/products?' . http_build_query([$subject . '_id' => $id, 'data_from' => $subject]);
    }

    private function identified(string $prefix, mixed $reference): ?string
    {
        $id = is_numeric($reference) ? (int) $reference : 0;

        return $id > 0 ? $prefix . $id : null;
    }

    /**
     * A slug page for a record chosen by id.
     *
     * The picker hands back an id because that is what identifies a row; the storefront route is
     * spelled with a slug. A record whose slug is missing has no page to link to, and null is the
     * honest answer — better than a URL that 404s.
     *
     * @param  class-string  $model
     */
    private function subjectPath(string $model, mixed $reference, string $prefix): ?string
    {
        $id = is_numeric($reference) ? (int) $reference : 0;

        if ($id < 1) {
            return null;
        }

        try {
            // One column, by primary key. Global scopes are dropped on purpose: a product's scope
            // eager-loads translations and reviews, and none of that has anything to do with
            // spelling its address.
            $slug = $model::query()->withoutGlobalScopes()->whereKey($id)->value('slug');
        } catch (\Throwable) {
            return null;
        }

        return is_string($slug) && $slug !== '' ? $prefix . $slug : null;
    }

    /**
     * @param  class-string  $model
     * @return array{kind: string, reference: mixed, label: ?string}
     */
    private function bySlug(string $model, string $kind, ?string $slug): array
    {
        if ($slug === null || $slug === '') {
            return ['kind' => $kind, 'reference' => null, 'label' => null];
        }

        try {
            $row = $model::query()->where('slug', $slug)->first(['id', 'name', 'slug']);
        } catch (\Throwable) {
            $row = null;
        }

        return [
            'kind'      => $kind,
            'reference' => $row?->id,
            // The slug stands in as the label for a record that has since been deleted, so the
            // control shows what the link pointed at rather than an empty selection.
            'label'     => $row?->name ?? $slug,
        ];
    }
}
