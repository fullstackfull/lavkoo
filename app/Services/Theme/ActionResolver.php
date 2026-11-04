<?php

namespace App\Services\Theme;

use App\Models\Brand;
use App\Models\Category;

/**
 * What a tap does, said once, in terms both clients understand.
 *
 * The builder stores a destination the only way a merchant can express one: a storefront URL,
 * usually pasted or produced by the link picker. That works on the web, where a URL IS navigation,
 * and not at all in the app, where `https://shop.example/product/aspirin-100mg` opens a browser on
 * top of the app instead of the product screen the shopper asked for.
 *
 * So the URL is parsed once, here, into a typed action — `{type: product, slug: aspirin-100mg}` —
 * and each renderer turns that into its own navigation. The merchant's link keeps working on the
 * web unchanged, because the original URL travels alongside the action as `url`.
 *
 * Nothing here executes anything. An action is data: a type from a closed list and a couple of
 * scalar parameters. A link that matches no known shape stays an ordinary external `url`, and an
 * empty one becomes `none` rather than a dead tap target.
 */
class ActionResolver
{
    public const NONE       = 'none';
    public const PRODUCT    = 'product';
    public const CATEGORY   = 'category';
    public const BRAND      = 'brand';
    public const VENDOR     = 'vendor';
    public const CAMPAIGN   = 'campaign';
    public const SEARCH     = 'search';
    public const CART       = 'cart';
    public const WISHLIST   = 'wishlist';
    public const COLLECTION = 'collection';
    /** A page the merchant composed in the App Builder, named by its slug. */
    public const PAGE       = 'page';
    public const URL        = 'url';

    public const TYPES = [
        self::NONE, self::PRODUCT, self::CATEGORY, self::BRAND, self::VENDOR,
        self::CAMPAIGN, self::SEARCH, self::CART, self::WISHLIST, self::COLLECTION, self::PAGE,
        self::URL,
    ];

    /** @var array<string, array{id: ?int, slug: ?string, label: ?string}>  subjects already looked up */
    private array $subjects = [];

    /**
     * Storefront list pages that are a named product collection rather than a filtered catalogue.
     *
     * The key is the first path segment; the value is the collection name both clients resolve —
     * the app already has a screen per collection, so this is the whole mapping it needs.
     */
    public const COLLECTIONS = [
        'featured-products'        => 'featured',
        'latest-products'          => 'latest',
        'best-selling-products'    => 'best_selling',
        'top-rated-products'       => 'top_rated',
        'discounted-products'      => 'discounted',
        'clearance-sale-products'  => 'clearance',
        'featured-deal-products'   => 'featured_deal',
        'most-favorite-products'   => 'most_favorite',
        'products'                 => 'all',
        'categories'               => 'all_categories',
        'brands'                   => 'all_brands',
        'vendors'                  => 'all_vendors',
        'search-shop'              => 'all_vendors',
    ];

    /**
     * The typed action behind a stored link.
     *
     * @return array{type: string, url: ?string, slug?: string, id?: int, query?: string, collection?: string}
     */
    public function resolve(?string $link): array
    {
        $link = is_string($link) ? trim($link) : '';
        if ($link === '' || $link === '#') {
            return ['type' => self::NONE, 'url' => null];
        }

        // A link the merchant pointed at another site is exactly that, on both clients. Checked
        // before path parsing so `https://other-shop.test/product/x` cannot be mistaken for ours.
        if ($this->isExternal($link)) {
            return ['type' => self::URL, 'url' => $link];
        }

        $parts = parse_url($link);
        $path = trim((string) ($parts['path'] ?? ''), '/');
        parse_str((string) ($parts['query'] ?? ''), $query);

        $segments = $path === '' ? [] : explode('/', $path);
        $head = $segments[0] ?? '';
        $tail = $segments[1] ?? null;

        return match (true) {
            $head === 'product'     && $tail !== null => $this->slugged(self::PRODUCT, $tail, $link),
            // Category and brand carry their id as well: the app's list screen opens on an id and
            // has no slug index, so a slug-only action would open an empty list.
            $head === 'category'    && $tail !== null
                => $this->subject(self::CATEGORY, id: null, slug: rawurldecode($tail), link: $link),
            $head === 'brand'       && $tail !== null
                => $this->subject(self::BRAND, id: null, slug: rawurldecode($tail), link: $link),
            $head === 'vendor-shop' && $tail !== null => $this->slugged(self::VENDOR, $tail, $link),

            $head === 'flash-deals' && $tail !== null
                => ['type' => self::CAMPAIGN, 'id' => (int) $tail, 'campaign' => 'flash_deal', 'url' => $link],

            $head === 'searched-products'
                => ['type' => self::SEARCH, 'query' => (string) ($query['name'] ?? $query['search'] ?? ''), 'url' => $link],

            // A composed page. The prefix is what keeps it out of the product and category
            // namespaces, so a page called "aspirin" can never shadow the product.
            $head === 'p' && $tail !== null
                => ['type' => self::PAGE, 'slug' => rawurldecode($tail), 'url' => $link],

            $head === 'shop-cart' => ['type' => self::CART, 'url' => $link],
            $head === 'wishlists' => ['type' => self::WISHLIST, 'url' => $link],

            isset(self::COLLECTIONS[$head])
                => $this->collection($head, $query, $link),

            default => ['type' => self::URL, 'url' => $link],
        };
    }

    /**
     * Add a resolved `action` beside every link a payload carries, without disturbing the link.
     *
     * Additive on purpose: the web renderer keeps reading `link` and is unaffected, while a client
     * that understands actions reads `action` instead. Both describe the same destination because
     * both came from the same string.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function annotate(array $payload, string $linkKey = 'link', string $actionKey = 'action'): array
    {
        if (array_key_exists($linkKey, $payload)) {
            $payload[$actionKey] = $this->resolve(is_string($payload[$linkKey]) ? $payload[$linkKey] : null);
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array{type: string, collection?: string, url: string, id?: int, label?: string}
     */
    private function collection(string $head, array $query, string $link): array
    {
        // A catalogue filtered to ONE category or brand is not a collection — it is that category
        // or that brand, which is a screen the app already has. This is how the admin's banner form
        // stores a category link (`/products?category_id=44&data_from=category`), so reading it as
        // the generic "all products" collection is what made a banner pointing at a category open
        // the entire catalogue on the phone while the web opened the category.
        foreach (['category' => self::CATEGORY, 'brand' => self::BRAND] as $filter => $type) {
            $id = $query[$filter . '_id'] ?? null;

            if ($head === 'products' && is_numeric($id) && (int) $id > 0) {
                return $this->subject($type, id: (int) $id, slug: null, link: $link);
            }
        }

        $action = ['type' => self::COLLECTION, 'collection' => self::COLLECTIONS[$head], 'url' => $link];

        // Any other filtered list keeps its filter, so a client that can apply one still may.
        foreach (['category_id', 'brand_id', 'id'] as $key) {
            if (isset($query[$key]) && is_numeric($query[$key])) {
                $action['id'] = (int) $query[$key];
                break;
            }
        }

        return $action;
    }

    /**
     * A category or brand action carrying everything a client needs to open it: id, slug and name.
     *
     * The web navigates by URL and needs none of this. The app's list screen opens on an id and
     * titles itself with a name, and a link gives it only one of the two — a slug from
     * `/category/vitamins`, an id from `/products?category_id=44`. Filling in the other here is
     * what makes both spellings of the same destination behave identically on the phone.
     *
     * Looked up once per subject per request (a home page can carry the same category on a banner,
     * a showcase and a rail) and never allowed to fail: a lookup that cannot run costs the extra
     * fields, never the action.
     *
     * @return array{type: string, url: string, id?: int, slug?: string, label?: string}
     */
    private function subject(string $type, ?int $id, ?string $slug, string $link): array
    {
        $key = $type . ':' . ($id ?? $slug);

        $resolved = $this->subjects[$key] ??= $this->lookUp($type, $id, $slug);

        return array_filter([
            'type'  => $type,
            'id'    => $id ?? $resolved['id'],
            'slug'  => $slug ?? $resolved['slug'],
            'label' => $resolved['label'],
            'url'   => $link,
        ], static fn ($value) => $value !== null);
    }

    /**
     * @return array{id: ?int, slug: ?string, label: ?string}
     */
    private function lookUp(string $type, ?int $id, ?string $slug): array
    {
        $empty = ['id' => null, 'slug' => null, 'label' => null];

        try {
            $query = $type === self::CATEGORY ? Category::query() : Brand::query();
            $model = $id !== null
                ? $query->find($id)
                : $query->where('slug', $slug)->first();

            if ($model === null) {
                return $empty;
            }

            return [
                'id' => (int) $model->id,
                'slug' => is_string($model->slug) && $model->slug !== '' ? $model->slug : null,
                'label' => is_string($model->name) && $model->name !== '' ? $model->name : null,
            ];
        } catch (\Throwable) {
            return $empty;
        }
    }

    /** @return array{type: string, slug: string, url: string} */
    private function slugged(string $type, string $slug, string $link): array
    {
        return ['type' => $type, 'slug' => rawurldecode($slug), 'url' => $link];
    }

    /**
     * Whether a link leaves this storefront.
     *
     * A relative link is always ours. An absolute one is ours only when its host matches the app
     * URL — comparing hosts rather than whole prefixes so `http` vs `https` and a trailing slash
     * do not turn an internal link into an external one.
     */
    private function isExternal(string $link): bool
    {
        $host = parse_url($link, PHP_URL_HOST);
        if ($host === null || $host === false) {
            return false;
        }

        $ownHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        return !is_string($ownHost) || strcasecmp($host, $ownHost) !== 0;
    }
}
