<?php

namespace App\Services\Theme;

use App\Models\Banner;
use App\Services\BannerService;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * Supplies the data a themed home section renders.
 *
 * Why a service and not queries inside the blade: the storefront went down once because a section
 * blade filtered `categories.status`, a column this schema does not have. A view that queries is a
 * view that can 500 the shop. Every read here is wrapped, returns an empty collection on failure,
 * and the caller renders nothing instead of throwing.
 *
 * It also normalizes the two sources of "a banner" — theme-builder blocks and rows created in
 * Promotion -> Banners — into ONE card shape, so a merchant can add a banner in either place and
 * the same section renderers display it.
 */
class SectionDataResolver
{
    /** How many products a bundle draws — shared with the app's hint so both show one set. */
    public const BUNDLE_LIMIT = 12;

    /**
     * One brand with its products — the brand half of category_showcase.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>|null
     */
    public function brandShowcase(array $settings): ?array
    {
        $brandId = (int) ($settings['brand_id'] ?? 0);

        if ($brandId < 1) {
            return null;
        }

        $brand = $this->safely(fn () => Brand::query()->where('status', 1)->where('id', $brandId)->get())->first();

        if ($brand === null) {
            return null;
        }

        $products = $this->productsFrom(
            ContentSource::scoped('brand', $brandId, $settings['limit'] ?? null),
        );

        return $products->isEmpty() ? null : ['brand' => $brand, 'products' => $products];
    }

    /**
     * What customers actually searched for, from the analytics rollup.
     *
     * Read from analytics_daily rather than the raw events: the rollup has already excluded bots
     * and staff, which is the difference between "what customers want" and "what a crawler asked
     * for". Returns nothing at all when analytics is not installed or has not rolled up yet — the
     * section then does not render, and the builder says why.
     *
     * @return Collection<int, object>
     */
    public function trendingSearches(int $days = 30, int $limit = 10): Collection
    {
        $days = max(1, min(365, $days));
        $limit = $this->bounded($limit, 24);

        return $this->safely(function () use ($days, $limit) {
            $connection = \Illuminate\Support\Facades\DB::connection(config('analytics.connection'));

            if (!\Illuminate\Support\Facades\Schema::connection(config('analytics.connection'))->hasTable('analytics_daily')) {
                return collect();
            }

            return collect($connection->table('analytics_daily')
                ->where('dimension', 'search_term')
                ->where('date', '>=', now()->subDays($days)->toDateString())
                ->groupBy('dimension_key')
                ->selectRaw('dimension_key AS term, SUM(events) AS searches')
                ->orderByDesc('searches')
                ->limit($limit)
                ->get());
        });
    }

    /**
     * The products THIS visitor looked at, and nobody else's.
     *
     * Scoped to the visitor's own id from their own first-party cookie: this section shows a person
     * their own history, so reading anyone else's would be both wrong and a privacy failure. A
     * visitor with no cookie has no history, which is the correct answer rather than a fallback to
     * somebody else's.
     *
     * @return Collection<int, Product>
     */
    public function recentlyViewed(int $limit = 8): Collection
    {
        $limit = $this->bounded($limit, 24);

        return $this->safely(function () use ($limit) {
            $visitorId = request()->cookie(\App\Services\Telemetry\TelemetryRecorder::VISITOR_COOKIE);

            if (!is_string($visitorId) || $visitorId === '') {
                return collect();
            }

            $connection = \Illuminate\Support\Facades\DB::connection(config('analytics.connection'));

            if (!\Illuminate\Support\Facades\Schema::connection(config('analytics.connection'))->hasTable('analytics_events')) {
                return collect();
            }

            $ids = $connection->table('analytics_events')
                ->where('visitor_id', $visitorId)
                ->where('name', \App\Services\Analytics\AnalyticsEvent::PRODUCT_VIEWED)
                ->where('entity_type', 'product')
                ->orderByDesc('id')
                ->limit($limit * 3)
                ->pluck('entity_id')
                ->map(static fn ($id) => (int) $id)
                ->filter()
                ->unique()
                ->take($limit)
                ->all();

            return $ids === [] ? collect() : $this->pickedProducts($ids, $limit);
        });
    }

    /**
     * Categories for the category grid: the ones the merchant hand-picked, in the order they
     * picked them, or the top-level categories by priority when they picked none.
     */
    public function categories(int $limit, string|array|null $picked = null): Collection
    {
        $ids = ContentSource::pickedIds($picked);

        if ($ids !== []) {
            return $this->safely(fn () => Category::whereIn('id', array_slice($ids, 0, $this->bounded($limit, 24)))
                ->get(['id', 'name', 'slug', 'icon'])
                ->sortBy(fn ($category) => array_search($category->id, $ids, true))
                ->values());
        }

        return $this->safely(fn () => Category::where('position', 0)
            ->orderBy('priority')
            ->take($this->bounded($limit, 24))
            ->get(['id', 'name', 'slug', 'icon']));
    }

    /** Products for a product slider, per the section's `source` setting. */
    public function products(array $settings): Collection
    {
        return $this->productsFrom(ContentSource::fromSettings($settings, defaultLimit: 8));
    }

    /**
     * The products one source resolves to, run against the database.
     *
     * The sibling of {@see ThemeSourceMap::endpointFor()}: same source object, same choice, one
     * answered with a query for the web and the other with an endpoint for the app. Keeping the
     * two on one input is what stops a rail from showing different products on a phone.
     *
     * @return Collection<int, \App\Models\Product>
     */
    public function productsFrom(ContentSource $source): Collection
    {
        // Bounded once, where the source is read. Clamping again here is how the web and the app
        // ended up with two different ceilings for the same rail.
        $limit = $source->limit;

        if ($source->isManual()) {
            return $this->pickedProducts($source->ids, $limit);
        }

        if ($source->kind === 'collection') {
            // A merchant-defined dynamic collection. Empty — rules matching nothing, disabled,
            // deleted, or the engine switched off — stays empty: SectionReadiness already hides a
            // product section with nothing to show, and featured products under this section's
            // own heading would be the wrong content wearing the right title. A configurable
            // fallback source arrives with merchandising (Phase 3.2).
            return app(\App\Services\Commerce\CollectionResolver::class)
                ->resolve($source->id, $limit);
        }

        return $this->safely(function () use ($limit, $source) {
            // The card shows the brand under the image; loading it here keeps a rail of ten
            // products at one query instead of eleven.
            $query = Product::active()->with('brand:id,name,slug');

            $query = match ($source->kind) {
                'best_selling' => $query->withCount('orderDetails')->orderByDesc('order_details_count'),
                'new_arrival'  => $query->latest('id'),
                'top_rated'    => $query->withAvg('reviews', 'rating')->orderByDesc('reviews_avg_rating'),
                'category'     => $this->scopedToCategory($query, (int) $source->id)->latest('id'),
                'brand'        => $query->where('brand_id', (int) $source->id)->latest('id'),
                default        => $query->where('featured', 1)->latest('id'),
            };

            return $query->take($limit)->get();
        });
    }

    /**
     * Product ids the signed-in customer has wishlisted, so every card on the page can draw a
     * filled or empty heart from ONE query instead of one per card.
     */
    public function wishlistedProductIds(): array
    {
        $customerId = auth('customer')->id();
        if (!$customerId) {
            return [];
        }

        return $this->safely(fn () => \App\Models\Wishlist::where('customer_id', $customerId)->pluck('product_id'))
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** Exactly these products, in the order the merchant picked them. */
    public function pickedProducts(string|array|null $picked, int $limit): Collection
    {
        $ids = ContentSource::pickedIds($picked);
        if ($ids === []) {
            return collect();
        }

        return $this->safely(fn () => Product::active()
            ->with('brand:id,name,slug')
            ->whereIn('id', array_slice($ids, 0, $this->bounded($limit, 24)))
            ->get()
            ->sortBy(fn ($product) => array_search($product->id, $ids, true))
            ->values());
    }

    /**
     * A category and everything filed under it.
     *
     * Products carry their category at three levels; a merchant who picks "Skincare" means the
     * serums filed under its children too, so all three columns are matched against the category
     * and its descendants.
     */
    private function scopedToCategory(\Illuminate\Database\Eloquent\Builder $query, int $categoryId): \Illuminate\Database\Eloquent\Builder
    {
        if ($categoryId <= 0) {
            return $query;
        }

        $ids = $this->categoryWithDescendants($categoryId);

        return $query->where(fn ($scoped) => $scoped
            ->whereIn('category_id', $ids)
            ->orWhereIn('sub_category_id', $ids)
            ->orWhereIn('sub_sub_category_id', $ids));
    }

    /** @return array<int, int> the category id plus its child and grandchild ids */
    public function categoryWithDescendants(int $categoryId): array
    {
        $children = Category::where('parent_id', $categoryId)->pluck('id')->all();
        $grandChildren = $children === [] ? [] : Category::whereIn('parent_id', $children)->pluck('id')->all();

        return array_values(array_unique(array_merge([$categoryId], $children, $grandChildren)));
    }

    /**
     * Shops for a vendor section: the ones the merchant hand-picked, in their order, or the
     * highest-rated shops when they picked none.
     *
     * The in-house shop counts as a vendor here exactly as it does on the storefront's own
     * /vendors page: it has no seller record, so Shop::active() alone would silently drop the
     * marketplace's own store from a section called "our vendors".
     *
     * Ratings come from ShopService — the same source that page uses — so a shop card in the
     * theme never disagrees with the shop's own page.
     */
    public function vendors(int $limit, string|array|null $picked = null): Collection
    {
        $ids = ContentSource::pickedIds($picked);

        return $this->safely(function () use ($limit, $ids) {
            $shops = \App\Models\Shop::query()
                ->where(fn ($query) => $query
                    ->where('author_type', 'admin')
                    ->orWhereHas('seller', fn ($seller) => $seller->where('status', 'approved')))
                ->when($ids !== [], fn ($query) => $query->whereIn('id', $ids))
                ->with(['seller' => fn ($query) => $query->withCount('orders')
                    ->with(['product.reviews' => fn ($reviews) => $reviews->active()])])
                ->take($ids !== [] ? count($ids) : $this->bounded($limit, 24))
                ->get()
                ->map(fn ($shop) => $this->withShopStats($shop));

            return $ids !== []
                ? $shops->sortBy(fn ($shop) => array_search($shop->id, $ids, true))->values()
                : $shops->sortByDesc('average_rating')->values();
        });
    }

    /**
     * One shop presented as its own block: its cover, logo, rating and products — the vendor
     * equivalent of the category showcase, so a merchant can feature a seller on the home page.
     */
    public function vendorShowcase(array $settings): ?array
    {
        $shopId = (int) ($settings['shop_id'] ?? 0);
        if ($shopId <= 0) {
            return null;
        }

        $shop = $this->vendors(1, (string) $shopId)->first();

        return $shop
            ? ['shop' => $shop, 'products' => $this->shopProducts($shop, (int) ($settings['limit'] ?? 10))]
            : null;
    }

    /**
     * What a shop is selling right now.
     *
     * A vendor's products are filed under `added_by = seller` with the seller's id, the in-house
     * shop's under `added_by = admin` — the split 6Valley uses everywhere, and the reason a naive
     * "products of this shop" query returns nothing for the marketplace's own store.
     */
    public function shopProducts($shop, int $limit): Collection
    {
        return $this->safely(fn () => Product::active()
            ->with('brand:id,name,slug')
            ->where('added_by', $shop->author_type === 'admin' ? 'admin' : 'seller')
            ->when($shop->author_type !== 'admin', fn ($query) => $query->where('user_id', $shop->seller_id))
            ->latest('id')
            ->take($this->bounded($limit, 24))
            ->get());
    }

    /** Rating, review and product counts for a shop card, from the storefront's own service. */
    private function withShopStats($shop)
    {
        $shop->products_count = $this->shopProducts($shop, 24)->count();

        if ($shop->author_type === 'admin' || !$shop->seller) {
            $reviews = \App\Models\Review::active()
                ->whereIn('product_id', Product::active()->where('added_by', 'admin')->pluck('id'));
            $shop->average_rating = $reviews->avg('rating') ?? 0;
            $shop->review_count = $reviews->count();
            $shop->is_vacation_mode_now = (bool) checkVendorAbility(type: 'inhouse', status: 'vacation_status');

            return $shop;
        }

        return \App\Services\ShopService::calculateReviews($shop);
    }

    public function brands(int $limit): Collection
    {
        return $this->safely(fn () => Brand::where('status', 1)
            ->orderBy('name')
            ->take($this->bounded($limit, 24))
            ->get(['id', 'name', 'slug', 'image']));
    }

    /**
     * Banners created in the dashboard (Promotion -> Banners), as render-ready cards.
     *
     * Matches the storefront's own filter — published, and belonging to the active folder theme —
     * so what the merchant sees listed there is what the theme shows. When nothing matches the
     * active theme (banners added before a theme switch) the theme filter is dropped rather than
     * rendering an empty section, since an orphaned banner is still a banner the merchant created.
     */
    public function dashboardBanners(string $bannerType, int $limit): array
    {
        $rows = $this->safely(function () use ($bannerType, $limit) {
            $base = fn () => Banner::with(['storage'])
                ->where('published', 1)
                ->where('banner_type', $bannerType)
                // Placement types find their page through their resource; one pointing
                // elsewhere renders an image that leads nowhere.
                ->when(
                    BannerService::REQUIRED_RESOURCE_TYPES[$bannerType] ?? null,
                    fn ($query, $resourceType) => $query->where('resource_type', $resourceType),
                );

            // Same ordering as BannerPlacementService — priority first, then creation
            // order — so a section shows the banners in the arrangement the merchant
            // sees in their built-in slot.
            $ordered = fn ($query) => $query->orderBy('priority')->orderBy('id');

            $scoped = $ordered((clone $base())->where('theme', theme_root_path()))
                ->take($this->bounded($limit, 24))->get();

            return $scoped->isNotEmpty()
                ? $scoped
                : $ordered($base())->take($this->bounded($limit, 24))->get();
        });

        return $rows->map(fn (Banner $banner) => [
            // Which banner this card came from, so a click on it is counted against the row the
            // merchant edits rather than against "a card in a section somewhere".
            'banner_id'   => (int) $banner->id,
            // photo_full_url is the storage descriptor array, not a url. The section
            // partials echo `image` straight into src, so resolve it here — echoing the
            // array fatals the whole home page the moment a theme with a banner section
            // is published.
            'image'       => getStorageImages(path: $banner->photo_full_url, type: 'banner'),
            // The merchant's phone-shaped image, where they uploaded one. Without this key the
            // apps drew the wide web image on a phone — the mobile image was set in Banner Setup
            // and honoured everywhere except the sections that read banners from the dashboard.
            'image_mobile' => $banner->mobile_photo
                ? getStorageImages(path: $banner->mobile_photo_full_url, type: 'banner')
                : null,
            'title'       => $banner->title,
            'subtitle'    => $banner->sub_title,
            'link'        => $banner->url,
            'button_text' => $banner->button_text,
            'background'  => $banner->background_color,
            'badge'       => null,
            // Keys the slide-shaped renderers read; a dashboard banner has no per-slide styling,
            // so these carry the renderer defaults instead of being absent and fataling the view.
            'eyebrow'     => null,
            'text_color'  => null,
            'align'       => 'start',
            'overlay'     => null,
            // A grid banner already carries how wide it wants to sit; a mosaic honours it so the
            // arrangement is the same whether the banners render in their built-in slot or here.
            'span'        => ($banner->layout ?? 'full') === 'full' ? 'wide' : 'small',
        ])->all();
    }

    /**
     * Theme-builder blocks as the same card shape as dashboardBanners(), so both feed the same
     * renderers and a section can be switched between sources without touching the markup.
     */
    /**
     * The blocks of a block-driven section that carry the content that section is FOR.
     *
     * A story with no cover, a branch with no name, a comparison missing one of its two photos
     * draws nothing; keeping the empty ones out lets the renderer skip a section that would
     * otherwise open a padded band with nothing in it.
     *
     * @param  array<int, string>  $required  keys the block must all have filled
     * @param  array<int, string>  $either    keys the block must have at least one of
     */
    public function blocksWithContent(array $blocks, array $required = [], array $either = []): array
    {
        return array_values(array_filter($blocks, function ($block) use ($required, $either) {
            $settings = $block['settings'] ?? [];

            foreach ($required as $key) {
                if (empty($settings[$key])) {
                    return false;
                }
            }

            if (!$either) {
                return true;
            }

            foreach ($either as $key) {
                if (!empty($settings[$key])) {
                    return true;
                }
            }

            return false;
        }));
    }

    public function blockCards(array $blocks, bool $withTargets = false): array
    {
        $cards = [];

        // Banner-backed blocks render their linked Promotion -> Banners row LIVE: the banner's
        // image/link/text win over the block's own copies, so an edit in Banner Setup shows on the
        // storefront without touching the theme. An unpublished linked banner hides its card, the
        // same way unpublishing hides a banner from its built-in slot.
        $overrides = app(ThemeBannerLink::class)->cardOverrides(
            array_map(fn ($block) => (int) (($block['settings']['banner_id'] ?? 0)), $blocks)
        );

        foreach ($blocks as $block) {
            $settings = $block['settings'] ?? [];

            $linkedId = (int) ($settings['banner_id'] ?? 0);
            $linked = $overrides[$linkedId] ?? null;
            if ($linkedId > 0 && ($linked === null || !$linked['published'])) {
                // Deleted or unpublished in Banner Setup -> gone from the theme too.
                continue;
            }
            if ($linked !== null) {
                foreach (['image', 'image_mobile', 'link', 'title', 'subtitle', 'button_text', 'background'] as $key) {
                    if (!empty($linked[$key])) {
                        $settings[$key] = $linked[$key];
                    }
                }
            }

            $cards[] = [
                // The Banner Setup row behind this block, where it has one. Carried through so a
                // click on it is counted against that row rather than being anonymous; a block
                // whose content was typed into the builder has no row and stays null.
                'banner_id'    => $linkedId > 0 ? $linkedId : null,
                'image'        => $settings['image'] ?? null,
                'image_mobile' => $settings['image_mobile'] ?? null,
                'eyebrow'      => $settings['eyebrow'] ?? null,
                'title'        => $settings['title'] ?? null,
                'subtitle'     => $settings['subtitle'] ?? ($settings['body'] ?? null),
                'link'         => $settings['link'] ?? null,
                'button_text'  => $settings['button_text'] ?? null,
                'badge'        => $settings['badge'] ?? null,
                'span'         => $settings['span'] ?? 'small',
                'align'        => $settings['align'] ?? 'start',
                'media_side'   => $settings['media_side'] ?? 'start',
                'text_color'   => $settings['text_color'] ?? null,
                'background'   => $settings['background'] ?? null,
                'overlay'      => $settings['overlay'] ?? null,
                'icon'         => $settings['icon'] ?? null,
                // Every frame of the tile, lead image first. One entry means a still tile; more
                // mean it crossfades through them in place. The linked banner's image, when there
                // is one, replaces only the LEAD frame — the extra frames are the block's own.
                'images'       => array_values(array_filter([
                    $settings['image'] ?? null,
                    $settings['image_2'] ?? null,
                    $settings['image_3'] ?? null,
                ], fn ($frame) => is_string($frame) && $frame !== '')),
                // The Banner Setup form's own resource picker, carried raw; resolveTargets() below
                // turns it (or, failing it, the link URL) into the card's structured target.
                '_resource'    => $linked !== null
                    ? ['type' => $linked['resource_type'] ?? null, 'id' => $linked['resource_id'] ?? null]
                    : null,
            ];
        }

        // The web render never reads targets, so it never pays for the lookups behind them.
        $cards = $withTargets ? $this->resolveTargets($cards) : $cards;

        foreach ($cards as &$card) {
            unset($card['_resource']);
        }

        return $cards;
    }

    /**
     * What each card OPENS, structurally — so a client never reverse-engineers a URL.
     *
     * "This tile is linked to something, but to what?" was unanswerable from the API: a card
     * carried only a `link` path. The banner row knows (its resource picker stores type + id), and
     * when the tile was composed with a plain link instead, the storefront's own URL shapes are
     * unambiguous — /product/{slug}, /category/{slug}, /brand/{slug}, /vendor-shop/{slug}. Anything
     * else is honestly a `url`, and a card with no link at all says `none` rather than nothing.
     *
     * Names and slugs resolve in ONE query per entity type across the whole section, not one per
     * tile — a mosaic of ten tiles must not cost ten lookups.
     *
     * @param  array<int, array<string, mixed>>  $cards
     * @return array<int, array<string, mixed>>
     */
    private function resolveTargets(array $cards): array
    {
        $slugShapes = ['product' => 'product', 'category' => 'category', 'brand' => 'brand', 'shop' => 'vendor-shop'];
        $wantedIds = [];
        $wantedSlugs = [];

        foreach ($cards as $index => $card) {
            $resource = $card['_resource'] ?? null;

            if (is_array($resource) && in_array($resource['type'], array_keys($slugShapes), true) && (int) ($resource['id'] ?? 0) > 0) {
                $cards[$index]['target'] = ['kind' => $resource['type'], 'id' => (int) $resource['id']];
                $wantedIds[$resource['type']][] = (int) $resource['id'];
                continue;
            }

            $link = trim((string) ($card['link'] ?? ''));
            if ($link === '') {
                $cards[$index]['target'] = ['kind' => 'none'];
                continue;
            }

            $segments = array_values(array_filter(explode('/', (string) (parse_url($link, PHP_URL_PATH) ?: ''))));
            $kind = array_search($segments[0] ?? '', $slugShapes, true);

            if ($kind !== false && isset($segments[1])) {
                $slug = rawurldecode($segments[1]);
                $cards[$index]['target'] = ['kind' => $kind, 'slug' => $slug];
                $wantedSlugs[$kind][] = $slug;
                continue;
            }

            $cards[$index]['target'] = ['kind' => 'url', 'url' => $link];
        }

        $found = $this->lookupTargets($wantedIds, $wantedSlugs);

        foreach ($cards as $index => $card) {
            $target = $card['target'];
            $key = isset($target['id']) ? 'id:' . $target['id'] : (isset($target['slug']) ? 'slug:' . $target['slug'] : null);
            $row = $key !== null ? ($found[$target['kind']][$key] ?? null) : null;

            if ($row !== null) {
                $cards[$index]['target'] += ['id' => $row['id'], 'slug' => $row['slug'], 'name' => $row['name']];
            } elseif ($key !== null) {
                // A link to something that no longer exists (or was never real) must not present
                // itself as a resolvable target — the app would build a screen around a ghost.
                $cards[$index]['target'] = ['kind' => 'url', 'url' => $card['link'] ?? null];
            }
        }

        return $cards;
    }

    /**
     * One query per entity type, keyed both ways so id- and slug-shaped targets share it.
     *
     * @param  array<string, array<int, int>>  $ids
     * @param  array<string, array<int, string>>  $slugs
     * @return array<string, array<string, array{id: int, slug: ?string, name: ?string}>>
     */
    private function lookupTargets(array $ids, array $slugs): array
    {
        $models = [
            'product' => Product::class,
            'category' => Category::class,
            'brand' => Brand::class,
            'shop' => \App\Models\Shop::class,
        ];
        $found = [];

        foreach ($models as $kind => $model) {
            $wantIds = array_unique($ids[$kind] ?? []);
            $wantSlugs = array_unique($slugs[$kind] ?? []);
            if ($wantIds === [] && $wantSlugs === []) {
                continue;
            }

            // An unpublished product is a ghost the app would 404 on; the url fallback above is
            // the honest answer for it. The other types keep their own visibility rules at open.
            $rows = $this->safely(fn () => ($kind === 'product' ? Product::active() : $model::query())
                ->where(fn ($query) => $query
                    ->when($wantIds !== [], fn ($inner) => $inner->whereIn('id', $wantIds))
                    ->when($wantSlugs !== [], fn ($inner) => $inner->orWhereIn('slug', $wantSlugs)))
                ->get(['id', 'slug', 'name']));

            foreach ($rows as $row) {
                $entry = ['id' => (int) $row->id, 'slug' => $row->slug, 'name' => $row->name];
                $found[$kind]['id:' . $row->id] = $entry;
                if ($row->slug !== null) {
                    $found[$kind]['slug:' . $row->slug] = $entry;
                }
            }
        }

        return $found;
    }

    /**
     * The running flash deal, for the countdown strip: real title and REAL end date, so the
     * storefront counts down to the moment the deal actually ends (never a hardcoded timer).
     */
    public function flashDeal(?int $dealId = null, array $exclude = []): ?array
    {
        try {
            // A hand-picked deal renders whatever its ACTIVE flag says: the dashboard allows only
            // one active flash deal at a time (activating one deactivates the rest), so requiring
            // "active" would collapse every flash-deal section onto that single deal — which is
            // exactly what picking a deal is meant to avoid. An ENDED deal is still refused: a
            // countdown that reads zero is worse than no section.
            //
            // With nothing picked the section follows whichever deal is running, skipping the ones
            // already shown higher up the page so two automatic sections never repeat each other.
            $deal = \App\Models\FlashDeal::where('deal_type', 'flash_deal')
                ->when($dealId, fn ($query) => $query->where('id', $dealId))
                ->when(!$dealId, fn ($query) => $query
                    ->where('status', 1)
                    ->whereDate('start_date', '<=', date('Y-m-d'))
                    ->whereNotIn('id', array_filter(array_map('intval', $exclude))))
                ->whereDate('end_date', '>=', date('Y-m-d'))
                ->withCount('products')
                ->orderByDesc('id')
                ->first();

            if (!$deal) {
                return null;
            }

            return [
                'id'             => $deal->id,
                'title'          => $deal->title,
                // end_date is cast to a Carbon date (midnight): the deal runs to the END of that
                // day, so the countdown targets 23:59:59. Concatenating the Carbon into a string
                // produced "…00:00:00 23:59:59", which strtotime rejects — hence the explicit copy.
                'end_timestamp'  => $deal->end_date
                    ? $deal->end_date->copy()->endOfDay()->getTimestamp()
                    : null,
                'products_count' => (int) ($deal->products_count ?? 0),
                'url'            => \Illuminate\Support\Facades\Route::has('flash-deals')
                    ? route('flash-deals', ['id' => $deal->id])
                    : null,
            ];
        } catch (\Throwable $exception) {
            report($exception);
            return null;
        }
    }

    /** The products inside a flash deal, so the section sells rather than only counting down. */
    public function flashDealProducts(int $dealId, int $limit): Collection
    {
        return $this->safely(fn () => Product::active()
            ->with('brand:id,name,slug')
            ->whereIn('id', \App\Models\FlashDealProduct::where('flash_deal_id', $dealId)->pluck('product_id'))
            ->take($this->bounded($limit, 24))
            ->get());
    }

    /**
     * One category presented as its own block: the category, its page banner, its sub-categories
     * and its products — the same banner row the category page shows, so editing it in Banner
     * Setup (or on the category form) updates both places.
     */
    public function categoryShowcase(array $settings): ?array
    {
        $categoryId = (int) ($settings['category_id'] ?? 0);
        if ($categoryId <= 0) {
            return null;
        }

        try {
            $category = Category::find($categoryId, ['id', 'name', 'slug', 'icon']);
            if (!$category) {
                return null;
            }

            $banner = null;
            if ($settings['banner'] ?? true) {
                $row = app(\App\Services\EntityPageBannerService::class)->current(entity: 'category', resourceId: $categoryId);
                $banner = $row && $row->published
                    ? [
                        'image'        => getStorageImages(path: $row->photo_full_url, type: 'banner'),
                        // The phone artwork the merchant uploaded with the banner, when there is
                        // one — a wide desktop banner shrunk to a phone is unreadable otherwise.
                        'image_mobile' => $row->mobile_photo
                            ? getStorageImages(path: $row->mobile_photo_full_url, type: 'banner')
                            : null,
                        'title'       => $row->title,
                        'subtitle'    => $row->sub_title,
                        'link'        => $row->url,
                        'button_text' => $row->button_text,
                    ]
                    : null;
            }

            $subCategories = ($settings['sub_categories'] ?? true)
                ? Category::where('parent_id', $categoryId)->orderBy('priority')->take(12)->get(['id', 'name', 'slug'])
                : collect();

            return [
                'category'       => $category,
                'banner'         => $banner,
                'sub_categories' => $subCategories,
                'products'       => $this->productsFrom(
                    ContentSource::scoped('category', $categoryId, $settings['limit'] ?? null),
                ),
            ];
        } catch (\Throwable $exception) {
            report($exception);
            return null;
        }
    }

    /**
     * Real customer voices: approved product reviews with a comment, best rated first.
     * Nothing invented — every card on the storefront is a review a customer actually left.
     */
    public function testimonials(int $limit, int $minRating = 4): Collection
    {
        return $this->safely(fn () => \App\Models\Review::query()
            ->where('status', 1)
            ->whereNull('delivery_man_id')
            ->where('rating', '>=', max(1, min(5, $minRating)))
            ->whereNotNull('comment')
            ->where('comment', '!=', '')
            ->with(['customer:id,f_name,l_name', 'product:id,name,slug'])
            ->orderByDesc('rating')
            ->orderByDesc('id')
            ->take($this->bounded($limit, 12))
            ->get());
    }

    // --- Offers & Deals -------------------------------------------------------------------
    // Each of these mirrors a screen the merchant already works in (Promotion -> ...), so a
    // section is a window onto that screen rather than a second place to maintain the same data.

    /** The running "deal of the day", with the product it features. */
    public function dealOfTheDay(): ?array
    {
        try {
            $deal = \App\Models\DealOfTheDay::where('status', 1)->latest('id')->first();
            $product = $deal ? Product::active()->with('brand:id,name,slug')->find($deal->product_id) : null;

            return $product ? ['deal' => $deal, 'product' => $product] : null;
        } catch (\Throwable $exception) {
            report($exception);
            return null;
        }
    }

    /** Products of the running featured deal (Promotion -> Featured deal). */
    public function featuredDealProducts(int $limit): Collection
    {
        return $this->safely(function () use ($limit) {
            $deal = \App\Models\FlashDeal::where(['deal_type' => 'feature_deal', 'status' => 1])
                ->whereDate('start_date', '<=', date('Y-m-d'))
                ->whereDate('end_date', '>=', date('Y-m-d'))
                ->latest('id')
                ->first();

            if (!$deal) {
                return collect();
            }

            return Product::active()
                ->with('brand:id,name,slug')
                ->whereIn('id', \App\Models\FlashDealProduct::where('flash_deal_id', $deal->id)->pluck('product_id'))
                ->take($this->bounded($limit, 24))
                ->get();
        });
    }

    /** Products currently on clearance (Promotion -> Clearance sale). */
    public function clearanceProducts(int $limit): Collection
    {
        return $this->safely(fn () => Product::active()
            ->with('brand:id,name,slug')
            ->whereIn('id', \App\Models\StockClearanceProduct::where('is_active', 1)->pluck('product_id'))
            ->take($this->bounded($limit, 24))
            ->get());
    }

    /**
     * Coupons a customer can actually use right now: live, not expired, and not tied to one
     * specific customer — a code nobody else can redeem does not belong on the home page.
     */
    public function coupons(int $limit): Collection
    {
        return $this->safely(fn () => \App\Models\Coupon::where('status', 1)
            ->whereDate('start_date', '<=', date('Y-m-d'))
            ->whereDate('expire_date', '>=', date('Y-m-d'))
            ->whereIn('coupon_type', ['discount_on_purchase', 'free_delivery'])
            ->orderBy('expire_date')
            ->take($this->bounded($limit, 12))
            ->get());
    }

    // --- Storytelling & trust -------------------------------------------------------------

    /** Live counts for the store-stats bar; every number is a real row count. */
    public function storeStats(): array
    {
        return $this->safely(fn () => collect([
            'products'   => Product::active()->count(),
            'brands'     => Brand::where('status', 1)->count(),
            'categories' => Category::where('position', 0)->count(),
            'customers'  => \App\Models\User::count(),
            'orders'     => \App\Models\Order::count(),
        ]))->all();
    }

    /** The newest published blog posts (Blog module), or an empty list when the module is off. */
    public function blogPosts(int $limit): Collection
    {
        return $this->safely(function () use ($limit) {
            if (!class_exists(\Modules\Blog\app\Models\Blog::class)) {
                return collect();
            }

            return \Modules\Blog\app\Models\Blog::active()
                ->where('is_published', 1)
                ->whereDate('publish_date', '<=', date('Y-m-d'))
                ->latest('publish_date')
                ->take($this->bounded($limit, 12))
                ->get();
        });
    }

    /**
     * A bundle: the picked products plus what the set costs, before and after the section's
     * bundle discount. The maths is done here so the button and the label can never disagree.
     *
     * @return array{products: Collection, total: float, discounted: float, saved: float, percent: int}|null
     */
    public function bundle(array $settings): ?array
    {
        $products = $this->pickedProducts($settings['product_ids'] ?? null, self::BUNDLE_LIMIT);
        if ($products->count() < 2) {
            return null;
        }

        $total = $products->sum(fn ($product) => (float) getProductPriceByType(
            product: $product, type: 'discounted_unit_price', result: 'value',
        ));
        $percent = max(0, min(90, (int) ($settings['discount'] ?? 0)));
        $discounted = round($total * (100 - $percent) / 100, 2);

        return [
            'products'   => $products,
            'total'      => $total,
            'discounted' => $discounted,
            'saved'      => round($total - $discounted, 2),
            'percent'    => $percent,
        ];
    }

    /**
     * The seconds left until today's shipping cut-off, or null once it has passed.
     *
     * The cut-off is a wall-clock time in the store's own timezone, so "order within 2:14" means
     * the same thing to the merchant packing the boxes and to the customer reading the page.
     */
    public function shippingCutoff(string $time): ?int
    {
        try {
            $now = now();
            $cutoff = $now->copy()->setTimeFromTimeString($time !== '' ? $time : '16:00');
            // Carbon counts FROM the receiver: read it the other way round and today's cut-off comes
            // back negative, which renders as a dead 00:00:00 clock.
            $seconds = (int) $now->diffInSeconds($cutoff, absolute: false);

            return $seconds > 0 ? $seconds : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Clamp a merchant-supplied count so a stray value cannot fetch the whole catalogue. */
    private function bounded(int $value, int $max): int
    {
        return max(1, min($value, $max));
    }

    /** Run a read; a broken query returns nothing rather than taking the storefront down. */
    private function safely(callable $query): Collection
    {
        try {
            $result = $query();
        } catch (\Throwable $exception) {
            report($exception);
            return collect();
        }

        return $result instanceof Collection ? $result : collect($result);
    }
}
